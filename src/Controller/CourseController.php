<?php

namespace App\Controller;

use App\Entity\Course;
use App\Entity\Enrollment;
use App\Entity\Part;
use App\Entity\Video;
use App\Entity\User;
use App\Entity\QuizAttempt;
use App\Form\CourseType;
use App\Service\CourseProgressService;
use App\Service\QuizService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Psr\Log\LoggerInterface;

class CourseController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private CourseProgressService $progressService;
    private QuizService $quizService;
    private LoggerInterface $logger;

    public function __construct(EntityManagerInterface $entityManager, CourseProgressService $progressService, QuizService $quizService, LoggerInterface $logger)
    {
        $this->entityManager = $entityManager;
        $this->progressService = $progressService;
        $this->quizService = $quizService;
        $this->logger = $logger;
    }

    #[Route('/course/create', name: 'app_course_create')]
    public function create(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TEACHER');
        $course = new Course();
        $form = $this->createForm(CourseType::class, $course);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $imageFile = $form->get('image')->getData();
            if ($imageFile) {
                $newFilename = uniqid('course-') . '.' . $imageFile->guessExtension();
                try {
                    $imageFile->move(
                        $this->getParameter('course_images_directory'),
                        $newFilename
                    );
                    $course->setImage($newFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', 'Failed to upload course image: ' . $e->getMessage());
                    $this->logger->error('Course image upload failed: ' . $e->getMessage());
                }
            }

            foreach ($form->get('parts') as $partForm) {
                $part = $partForm->getData();
                $videoFile = $partForm->get('videoFile')->getData();
                $videoDescription = $partForm->get('videoDescription')->getData();

                if ($videoFile) {
                    $newFilename = uniqid('video-') . '.' . $videoFile->guessExtension();
                    try {
                        $videoFile->move(
                            $this->getParameter('part_videos_directory'),
                            $newFilename
                        );
                        $video = new Video();
                        $video->setFilename($newFilename);
                        $video->setDescription($videoDescription);
                        $video->setDuration($part->getDuration());
                        $video->setPart($part);
                        $part->setVideo($video);
                        $this->entityManager->persist($video);
                    } catch (FileException $e) {
                        $this->addFlash('error', 'Failed to upload video for part "' . $part->getTitle() . '": ' . $e->getMessage());
                        $this->logger->error('Video upload failed for part ID ' . $part->getId() . ': ' . $e->getMessage());
                    }
                }
            }

            $course->setCreatedBy($this->getUser());
            $course->setCreatedAt(new \DateTime());
            try {
                $this->entityManager->persist($course);
                $this->entityManager->flush();
                $this->addFlash('success', 'Course created successfully!');
                return $this->redirectToRoute('app_course_details', ['id' => $course->getId()]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to create course: ' . $e->getMessage());
                $this->addFlash('error', 'Failed to create course due to a database error.');
            }
        }

        return $this->render('course_create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/course/{id}/join', name: 'app_join_course')]
    public function join(int $id, Request $request): Response
    {
        $course = $this->entityManager->getRepository(Course::class)->find($id);
        if (!$course) {
            throw $this->createNotFoundException('Course not found');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $enrollment = new Enrollment();
        $enrollment->setUser($user);
        $enrollment->setCourse($course);
        $enrollment->setEnrolledAt(new \DateTime());

        try {
            $this->entityManager->persist($enrollment);
            $this->entityManager->flush();
            return $this->redirectToRoute('app_course_details', ['id' => $course->getId()]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to enroll in course ID ' . $id . ': ' . $e->getMessage());
            $this->addFlash('error', 'Failed to enroll in course due to a database error.');
            return $this->redirectToRoute('app_course_details', ['id' => $course->getId()]);
        }
    }

    #[Route('/course/{id}', name: 'app_course_details')]
    public function details(int $id): Response
    {
        $course = $this->entityManager->getRepository(Course::class)->find($id);
        if (!$course) {
            throw $this->createNotFoundException('Course not found');
        }

        $user = $this->getUser();
        $progress_percentage = 0;
        $is_enrolled = false;
        $current_part = null;
        $is_course_completed = false;
        $final_quiz = null;
        $current_quiz_attempt = null;

        if ($user) {
            $progress_percentage = $this->progressService->calculateProgress($user, $course);
            $is_enrolled = $course->getEnrollments()->exists(function($key, $enrollment) use ($user) {
                return $enrollment->getUser() === $user;
            });
            $current_part = $this->progressService->getCurrentPart($user, $course);
            $is_course_completed = $this->progressService->isCourseCompleted($user, $course);

            if ($current_part) {
                $part_quiz = $this->quizService->getOrGeneratePartQuiz($user, $current_part);
                if ($part_quiz) {
                    $current_quiz_attempt = $this->entityManager->getRepository(QuizAttempt::class)->findOneBy(
                        ['quiz' => $part_quiz, 'user' => $user],
                        ['takenAt' => 'DESC']
                    );

                    // Decode quiz question options
                    if ($part_quiz->getQuestions()) {
                        foreach ($part_quiz->getQuestions() as $question) {
                            $options = $question->getOptions();
                            if (is_string($options)) {
                                $question->setOptions(json_decode($options, true) ?: []);
                            }
                        }
                    }
                }
            }

            if ($is_course_completed) {
                $final_quiz = $this->quizService->getOrGenerateFinalQuiz($user, $course);
                if ($final_quiz && $final_quiz->getQuestions()) {
                    foreach ($final_quiz->getQuestions() as $question) {
                        $options = $question->getOptions();
                        if (is_string($options)) {
                            $question->setOptions(json_decode($options, true) ?: []);
                        }
                    }
                }
            }
        }

        return $this->render('course_details.html.twig', [
            'course' => $course,
            'progress_percentage' => $progress_percentage,
            'is_enrolled' => $is_enrolled,
            'current_part' => $current_part,
            'is_course_completed' => $is_course_completed,
            'final_quiz' => $final_quiz,
            'current_quiz_attempt' => $current_quiz_attempt,
            'is_part_unlocked' => fn($part) => $user ? $this->progressService->isPartUnlocked($part, $user) : false,
        ]);
    }

    #[Route('/course/{id}/update', name: 'app_course_update')]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TEACHER');

        $course = $this->entityManager->getRepository(Course::class)->find($id);
        if (!$course) {
            throw $this->createNotFoundException('Course not found');
        }

        $form = $this->createForm(CourseType::class, $course);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $imageFile = $form->get('image')->getData();
            if ($imageFile) {
                $newFilename = uniqid('course-') . '.' . $imageFile->guessExtension();
                try {
                    $imageFile->move(
                        $this->getParameter('course_images_directory'),
                        $newFilename
                    );
                    $course->setImage($newFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', 'Failed to upload course image: ' . $e->getMessage());
                    $this->logger->error('Course image upload failed: ' . $e->getMessage());
                }
            }

            foreach ($form->get('parts') as $partForm) {
                $part = $partForm->getData();
                $videoFile = $partForm->get('videoFile')->getData();
                $videoDescription = $partForm->get('videoDescription')->getData();

                if ($videoFile) {
                    $newFilename = uniqid('video-') . '.' . $videoFile->guessExtension();
                    try {
                        $videoFile->move(
                            $this->getParameter('part_videos_directory'),
                            $newFilename
                        );
                        $video = $part->getVideo() ?? new Video();
                        $video->setFilename($newFilename);
                        $video->setDescription($videoDescription);
                        $video->setDuration($part->getDuration());
                        $video->setPart($part);
                        $part->setVideo($video);
                        $this->entityManager->persist($video);
                    } catch (FileException $e) {
                        $this->addFlash('error', 'Failed to upload video for part "' . $part->getTitle() . '": ' . $e->getMessage());
                        $this->logger->error('Video upload failed for part ID ' . $part->getId() . ': ' . $e->getMessage());
                    }
                }
            }

            try {
                $this->entityManager->flush();
                $this->addFlash('success', 'Course updated successfully!');
                return $this->redirectToRoute('app_courses');
            } catch (\Exception $e) {
                $this->logger->error('Failed to update course: ' . $e->getMessage());
                $this->addFlash('error', 'Failed to update course due to a database error.');
            }
        }

        return $this->render('course_update.html.twig', [
            'form' => $form->createView(),
            'course' => $course,
        ]);
    }
}