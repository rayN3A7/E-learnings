<?php
// src/Controller/CourseController.php

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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Routing\Annotation\Route;
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

    $this->logger->info('Form submitted: ' . json_encode($request->request->all()));

    if ($form->isSubmitted() && $form->isValid()) {
        $this->logger->info('Form is valid, processing data');
        $imageFile = $form->get('image')->getData();
        if ($imageFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
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

        foreach ($form->get('parts') as $index => $partForm) {
            $part = $partForm->getData();
            $this->logger->info("Processing part $index: " . json_encode($partForm->getData()));
            $videoFile = $partForm->get('videoFile')->getData();
            $videoDescription = $partForm->get('videoDescription')->getData();
            $geogebraMaterialId = $partForm->get('geogebraMaterialId')->getData();
            $tutorialContent = $partForm->get('tutorialContent')->getData();

            if ($videoFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
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

            $part->setGeogebraMaterialId($geogebraMaterialId);
            $part->setTutorialContent($tutorialContent);
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
            $this->addFlash('error', 'Failed to create course due to a database error: ' . $e->getMessage());
        }
    } else {
        $this->logger->info('Form is not valid or not submitted');
        if ($form->isSubmitted()) {
            $this->logger->error('Form errors: ' . json_encode($form->getErrors(true)));
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
                }
            }

            if ($is_course_completed) {
                $final_quiz = $this->quizService->getOrGenerateFinalQuiz($user, $course);
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
        $course = $this->entityManager->getRepository(Course::class)->find($id);
        if (!$course) {
            throw $this->createNotFoundException('Course not found');
        }

        if (!$this->isGranted('ROLE_ADMIN') && (!$this->isGranted('ROLE_TEACHER') || $course->getCreatedBy() !== $this->getUser())) {
            throw $this->createAccessDeniedException('You do not have permission to update this course');
        }

        $form = $this->createForm(CourseType::class, $course);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $imageFile = $form->get('image')->getData();
            if ($imageFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
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
                $geogebraMaterialId = $partForm->get('geogebraMaterialId')->getData();
                $tutorialContent = $partForm->get('tutorialContent')->getData();

                if ($videoFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
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

                $part->setGeogebraMaterialId($geogebraMaterialId);
                $part->setTutorialContent($tutorialContent);
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

    #[Route('/course/{id}/delete', name: 'app_course_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $course = $this->entityManager->getRepository(Course::class)->find($id);
        if (!$course) {
            throw $this->createNotFoundException('Course not found');
        }

        if (!$this->isGranted('ROLE_ADMIN') && (!$this->isGranted('ROLE_TEACHER') || $course->getCreatedBy() !== $this->getUser())) {
            throw $this->createAccessDeniedException('You do not have permission to delete this course');
        }

        if ($this->isCsrfTokenValid('delete'.$id, $request->request->get('_token'))) {
            try {
                $this->entityManager->remove($course);
                $this->entityManager->flush();
                $this->addFlash('success', 'Course deleted successfully!');
            } catch (\Exception $e) {
                $this->logger->error('Failed to delete course ID ' . $id . ': ' . $e->getMessage());
                $this->addFlash('error', 'Failed to delete course due to a database error.');
            }
        } else {
            $this->addFlash('error', 'Invalid CSRF token.');
        }

        return $this->redirectToRoute('app_courses');
    }

  #[Route('/upload/image', name: 'upload_image', methods: ['POST'])]
public function uploadImage(Request $request): JsonResponse
{
    $file = $request->files->get('file');
    if (!$file || !$file->isValid()) {
        $this->logger->error('No file uploaded or file is invalid');
        return new JsonResponse(['error' => 'No file uploaded or invalid file'], 400);
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    $extension = $file->guessExtension();
    if (!in_array($extension, $allowedExtensions)) {
        $this->logger->error('Invalid file type: ' . $extension);
        return new JsonResponse(['error' => 'Invalid file type. Only JPG, PNG, and GIF are allowed.'], 400);
    }

    $uploadDir = $this->getParameter('upload_directory');
    $this->logger->info('Upload directory: ' . $uploadDir);
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        $this->logger->error('Upload directory is not writable: ' . $uploadDir);
        return new JsonResponse(['error' => 'Server configuration error: Upload directory not writable'], 500);
    }

    $newFilename = uniqid('media-') . '.' . $extension;
    try {
        $file->move($uploadDir, $newFilename);
        // Use the request object to get the scheme and host dynamically
        $location = $request->getSchemeAndHttpHost() . $request->getBasePath() . '/uploads/' . $newFilename;
        $this->logger->info('Image uploaded successfully: ' . $location);
        return new JsonResponse(['location' => $location]);
    } catch (FileException $e) {
        $this->logger->error('Image upload failed: ' . $e->getMessage());
        return new JsonResponse(['error' => 'Failed to upload image: ' . $e->getMessage()], 500);
    } catch (\Exception $e) {
        $this->logger->error('Unexpected error during image upload: ' . $e->getMessage());
        return new JsonResponse(['error' => 'Unexpected server error: ' . $e->getMessage()], 500);
    }
}
}