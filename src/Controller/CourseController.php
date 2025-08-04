<?php

namespace App\Controller;

use App\Entity\Quiz;
use App\Entity\Course;
use App\Entity\Enrollment;
use App\Entity\Part;
use App\Entity\Video;
use App\Entity\User;
use App\Entity\QuizAttempt;
use App\Entity\WrittenSection;
use App\Entity\MediaUpload;
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
use HTMLPurifier;
use HTMLPurifier_Config;

class CourseController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private CourseProgressService $progressService;
    private QuizService $quizService;
    private LoggerInterface $logger;
    private ?HTMLPurifier $purifier;
    private string $cacheDir;

    public function __construct(EntityManagerInterface $entityManager, CourseProgressService $progressService, QuizService $quizService, LoggerInterface $logger, string $cacheDir)
    {
        $this->entityManager = $entityManager;
        $this->progressService = $progressService;
        $this->quizService = $quizService;
        $this->logger = $logger;
        $this->cacheDir = $cacheDir;
        $this->initializePurifier();
    }

    private function initializePurifier(): void
    {
        try {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
            $config->set('HTML.AllowedElements', [
                'p', 'b', 'i', 'u', 'strong', 'em', 'span', 'div', 'br', 'img', 'video', 'source',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th', 'a',
                'blockquote', 'pre', 'code', 'hr', 'sub', 'sup', 's', 'del', 'ins', 'mark', 'small'
            ]);
            $config->set('HTML.AllowedAttributes', [
                'class', 'style', 'src', 'alt', 'href', 'title', 'target', 'type', 'controls',
                'poster', 'autoplay', 'loop', 'muted', 'width', 'height', 'id', 'name', 'data-*'
            ]);
            $config->set('URI.AllowedSchemes', ['http', 'https', 'data', 'mailto']);
            $config->set('Attr.EnableID', true);
            $config->set('CSS.AllowedProperties', ['color', 'background-color', 'font-size', 'font-family', 'text-align', 'margin', 'padding']);
            if (!is_dir($this->cacheDir) || !is_writable($this->cacheDir)) {
                throw new \RuntimeException("Cache directory '$this->cacheDir' is not writable.");
            }
            $config->set('Cache.SerializerPath', $this->cacheDir);
            $this->purifier = new HTMLPurifier($config);
            $this->logger->info('HTMLPurifier initialized successfully with cache at ' . $this->cacheDir);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize HTMLPurifier: ' . $e->getMessage());
            $this->purifier = null;
        }
    }

  #[Route('/course/create', name: 'app_course_create', methods: ['GET', 'POST'])]
public function create(Request $request): Response
{
    $this->denyAccessUnlessGranted('ROLE_TEACHER');
    $course = new Course();
    $form = $this->createForm(CourseType::class, $course, [
        'csrf_protection' => true,
        'csrf_field_name' => '_token',
        'csrf_token_id' => 'course_create',
    ]);
    $form->handleRequest($request);

    $this->logger->info('Form submitted: ' . json_encode($request->request->all(), JSON_PRETTY_PRINT));

    if ($form->isSubmitted() && $form->isValid()) {
        $this->logger->info('Form is valid, processing data');
        $imageFile = $form->get('image')->getData();
        if ($imageFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $newFilename = uniqid('course-') . '.' . $imageFile->guessExtension();
            try {
                $imageFile->move($this->getParameter('course_images_directory'), $newFilename);
                $course->setImage($newFilename);
            } catch (FileException $e) {
                $this->logger->error('Course image upload failed: ' . $e->getMessage());
                $this->addFlash('error', 'Failed to upload course image: ' . $e->getMessage());
                return $this->render('course_create.html.twig', ['form' => $form->createView()]);
            }
        }

        $course->setCreatedBy($this->getUser());
        $course->setCreatedAt(new \DateTime());
        $this->entityManager->persist($course);

        foreach ($form->get('parts') as $index => $partForm) {
            $part = $partForm->getData();
            $quizData = $partForm->get('quiz')->getData();
            $quizMode = $partForm->get('quizMode')->getData() ?? 'ai';

            // Persist WrittenSection
            $writtenSection = $part->getWrittenSection();
            if ($writtenSection && !$writtenSection->getId()) {
                $writtenSection->setPart($part); // Ensure bidirectional relationship
                $this->entityManager->persist($writtenSection);
            }

            if ($this->purifier && $writtenSection && $writtenSection->getContent()) {
                try {
                    $sanitizedContent = $this->purifier->purify($writtenSection->getContent());
                    $writtenSection->setContent($sanitizedContent);
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to purify written content for part ' . ($index + 1) . ': ' . $e->getMessage());
                }
            }

            // Persist Quiz
            if ($quizMode === 'manual' && $quizData instanceof Quiz) {
                $questions = $quizData->getQuestions();
                if (count($questions) !== 10) {
                    $this->addFlash('error', 'Part ' . ($index + 1) . ' quiz must have exactly 10 questions.');
                    return $this->render('course_create.html.twig', ['form' => $form->createView()]);
                }
                foreach ($questions as $question) {
                    if ($question->getType() === 'mcq' && count($question->getOptions()) !== 4) {
                        $this->addFlash('error', 'MCQ questions in part ' . ($index + 1) . ' quiz must have exactly 4 options.');
                        return $this->render('course_create.html.twig', ['form' => $form->createView()]);
                    }
                    if ($question->getType() === 'numeric' && !is_numeric($question->getCorrectAnswer())) {
                        $this->addFlash('error', 'Numeric questions in part ' . ($index + 1) . ' quiz must have a numeric correct answer.');
                        return $this->render('course_create.html.twig', ['form' => $form->createView()]);
                    }
                    $question->setQuiz($quizData);
                    $this->entityManager->persist($question);
                }
                $quizData->setPart($part);
                $quizData->setGeneratedByAI(false);
                $this->entityManager->persist($quizData);
            } elseif ($quizMode === 'ai') {
                $generatedQuiz = $this->quizService->getOrGeneratePartQuiz($this->getUser(), $part, $quizMode); // Pass user
                if ($generatedQuiz) {
                    if (!$this->entityManager->contains($generatedQuiz)) {
                        $generatedQuiz->setPart($part); // Ensure bidirectional relationship
                        $this->entityManager->persist($generatedQuiz);
                    }
                    $part->setQuiz($generatedQuiz);
                }
            }

            $course->addPart($part); // Ensure part is linked to course
            $this->entityManager->persist($part);
        }

        $finalQuizData = $form->get('finalQuiz')->getData();
        $finalQuizMode = $form->get('quizMode')->getData() ?? 'ai';
        if ($finalQuizMode === 'manual' && $finalQuizData instanceof Quiz) {
            $questions = $finalQuizData->getQuestions();
            if (count($questions) !== 10) {
                $this->addFlash('error', 'Final quiz must have exactly 10 questions.');
                return $this->render('course_create.html.twig', ['form' => $form->createView()]);
            }
            foreach ($questions as $question) {
                if ($question->getType() === 'mcq' && count($question->getOptions()) !== 4) {
                    $this->addFlash('error', 'MCQ questions in final quiz must have exactly 4 options.');
                    return $this->render('course_create.html.twig', ['form' => $form->createView()]);
                }
                if ($question->getType() === 'numeric' && !is_numeric($question->getCorrectAnswer())) {
                    $this->addFlash('error', 'Numeric questions in final quiz must have a numeric correct answer.');
                    return $this->render('course_create.html.twig', ['form' => $form->createView()]);
                }
                $question->setQuiz($finalQuizData);
                $this->entityManager->persist($question);
            }
            $finalQuizData->setCourse($course);
            $finalQuizData->setGeneratedByAI(false);
            $this->entityManager->persist($finalQuizData);
            $course->setFinalQuiz($finalQuizData);
        } elseif ($finalQuizMode === 'ai') {
            $generatedFinalQuiz = $this->quizService->getOrGenerateFinalQuiz($this->getUser(), $course, $finalQuizMode); // Pass user
            if ($generatedFinalQuiz) {
                if (!$this->entityManager->contains($generatedFinalQuiz)) {
                    $generatedFinalQuiz->setCourse($course); // Ensure bidirectional relationship
                    $this->entityManager->persist($generatedFinalQuiz);
                }
                $course->setFinalQuiz($generatedFinalQuiz);
            }
        }

        try {
            $this->entityManager->flush();
            $this->addFlash('success', 'Course created successfully!');
            return $this->redirectToRoute('app_course_details', ['id' => $course->getId()]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create course: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            $this->addFlash('error', 'Failed to create course: ' . $e->getMessage());
            return $this->render('course_create.html.twig', ['form' => $form->createView()]);
        }
    } elseif ($form->isSubmitted()) {
        $errors = [];
        foreach ($form->getErrors(true, true) as $error) {
            $errors[] = $error->getMessage();
        }
        $this->logger->error('Form validation errors: ' . json_encode($errors));
        $this->addFlash('error', 'Form validation failed: ' . implode(', ', $errors));
    }

    return $this->render('course_create.html.twig', ['form' => $form->createView()]);
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
            $this->addFlash('success', 'Successfully enrolled in the course!');
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
        $quizMode = 'ai';

        if ($user) {
            $progress_percentage = $this->progressService->calculateProgress($user, $course);
            $is_enrolled = $course->getEnrollments()->exists(function($key, $enrollment) use ($user) {
                return $enrollment->getUser() === $user;
            });
            $current_part = $this->progressService->getCurrentPart($user, $course);
            $is_course_completed = $this->progressService->isCourseCompleted($user, $course);

            if ($current_part) {
                $current_part = $this->entityManager->createQueryBuilder()
                    ->select('p')
                    ->from(Part::class, 'p')
                    ->leftJoin('p.writtenSection', 'ws')
                    ->leftJoin('ws.mediaUploads', 'mu')
                    ->where('p.id = :id')
                    ->setParameter('id', $current_part->getId())
                    ->getQuery()
                    ->getOneOrNullResult();

                $part_quiz = $current_part->getQuiz() ?: $this->quizService->getOrGeneratePartQuiz($user, $current_part, $quizMode);
                if ($part_quiz && !$part_quiz->getId()) {
                    $this->entityManager->persist($part_quiz);
                    $this->entityManager->flush();
                }
                $current_part->setQuiz($part_quiz);
                $current_quiz_attempt = $part_quiz ? $this->entityManager->getRepository(QuizAttempt::class)->findOneBy(
                    ['quiz' => $part_quiz, 'user' => $user],
                    ['takenAt' => 'DESC']
                ) : null;
            }

            if ($is_course_completed && !$final_quiz) {
                $final_quiz = $course->getFinalQuiz() ?: $this->quizService->getOrGenerateFinalQuiz($user, $course, $quizMode);
                if ($final_quiz && !$final_quiz->getId()) {
                    $this->entityManager->persist($final_quiz);
                    $this->entityManager->flush();
                }
                $course->setFinalQuiz($final_quiz);
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

    #[Route('/course/{id}/update', name: 'app_course_update', methods: ['GET', 'POST'])]
    public function update(int $id, Request $request): Response
    {
        $course = $this->entityManager->getRepository(Course::class)->find($id);
        if (!$course) {
            throw $this->createNotFoundException('Course not found');
        }

        if (!$this->isGranted('ROLE_ADMIN') && (!$this->isGranted('ROLE_TEACHER') || $course->getCreatedBy() !== $this->getUser())) {
            throw $this->createAccessDeniedException('You do not have permission to update this course');
        }

        $form = $this->createForm(CourseType::class, $course, [
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'course_update',
        ]);
        $form->handleRequest($request);

        $this->logger->info('Update form submitted for course ID ' . $id . ': ' . json_encode($request->request->all(), JSON_PRETTY_PRINT));

        if ($form->isSubmitted() && $form->isValid()) {
            $this->logger->info('Form is valid, processing data for course ID ' . $id);
            $imageFile = $form->get('image')->getData();
            if ($imageFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                $newFilename = uniqid('course-') . '.' . $imageFile->guessExtension();
                try {
                    $imageFile->move($this->getParameter('course_images_directory'), $newFilename);
                    $course->setImage($newFilename);
                } catch (FileException $e) {
                    $this->logger->error('Course image upload failed for course ID ' . $id . ': ' . $e->getMessage());
                    $this->addFlash('error', 'Failed to upload course image: ' . $e->getMessage());
                    return $this->render('course_update.html.twig', ['form' => $form->createView(), 'course' => $course]);
                }
            }

            $this->entityManager->persist($course);

            foreach ($form->get('parts') as $index => $partForm) {
                $part = $partForm->getData();
                $quizData = $partForm->get('quiz')->getData();
                $quizMode = $partForm->get('quizMode')->getData() ?? 'ai';

                if ($this->purifier && $part->getWrittenSection() && $part->getWrittenSection()->getContent()) {
                    try {
                        $sanitizedContent = $this->purifier->purify($part->getWrittenSection()->getContent());
                        $part->getWrittenSection()->setContent($sanitizedContent);
                        $this->entityManager->persist($part->getWrittenSection());
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to purify written content for part ' . ($index + 1) . ' in course ID ' . $id . ': ' . $e->getMessage());
                    }
                }

                if ($quizMode === 'manual' && $quizData instanceof Quiz) {
                    $questions = $quizData->getQuestions();
                    if (count($questions) !== 10) {
                        $this->addFlash('error', 'Part ' . ($index + 1) . ' quiz must have exactly 10 questions.');
                        return $this->render('course_update.html.twig', ['form' => $form->createView(), 'course' => $course]);
                    }
                    foreach ($questions as $question) {
                        if ($question->getType() === 'mcq' && count($question->getOptions()) !== 4) {
                            $this->addFlash('error', 'MCQ questions in part ' . ($index + 1) . ' quiz must have exactly 4 options.');
                            return $this->render('course_update.html.twig', ['form' => $form->createView(), 'course' => $course]);
                        }
                        if ($question->getType() === 'numeric' && !is_numeric($question->getCorrectAnswer())) {
                            $this->addFlash('error', 'Numeric questions in part ' . ($index + 1) . ' quiz must have a numeric correct answer.');
                            return $this->render('course_update.html.twig', ['form' => $form->createView(), 'course' => $course]);
                        }
                        $question->setQuiz($quizData);
                        $this->entityManager->persist($question);
                    }
                    $quizData->setPart($part);
                    $quizData->setGeneratedByAI(false);
                    $this->entityManager->persist($quizData);
                } elseif ($quizMode === 'ai') {
                    $this->entityManager->persist($part);
                    $generatedQuiz = $this->quizService->getOrGeneratePartQuiz(null, $part, $quizMode);
                    if ($generatedQuiz && !$generatedQuiz->getId()) {
                        $this->entityManager->persist($generatedQuiz);
                    }
                    $part->setQuiz($generatedQuiz);
                }
                $this->entityManager->persist($part);
            }

            $finalQuizData = $form->get('finalQuiz')->getData();
            $finalQuizMode = $form->get('quizMode')->getData() ?? 'ai';
            if ($finalQuizMode === 'manual' && $finalQuizData instanceof Quiz) {
                $questions = $finalQuizData->getQuestions();
                if (count($questions) !== 10) {
                    $this->addFlash('error', 'Final quiz must have exactly 10 questions.');
                    return $this->render('course_update.html.twig', ['form' => $form->createView(), 'course' => $course]);
                }
                foreach ($questions as $question) {
                    if ($question->getType() === 'mcq' && count($question->getOptions()) !== 4) {
                        $this->addFlash('error', 'MCQ questions in final quiz must have exactly 4 options.');
                        return $this->render('course_update.html.twig', ['form' => $form->createView(), 'course' => $course]);
                    }
                    if ($question->getType() === 'numeric' && !is_numeric($question->getCorrectAnswer())) {
                        $this->addFlash('error', 'Numeric questions in final quiz must have a numeric correct answer.');
                        return $this->render('course_update.html.twig', ['form' => $form->createView(), 'course' => $course]);
                    }
                    $question->setQuiz($finalQuizData);
                    $this->entityManager->persist($question);
                }
                $finalQuizData->setCourse($course);
                $finalQuizData->setGeneratedByAI(false);
                $course->setFinalQuiz($finalQuizData);
                $this->entityManager->persist($finalQuizData);
            } elseif ($finalQuizMode === 'ai') {
                $generatedFinalQuiz = $this->quizService->getOrGenerateFinalQuiz(null, $course, $finalQuizMode);
                if ($generatedFinalQuiz && !$generatedFinalQuiz->getId()) {
                    $this->entityManager->persist($generatedFinalQuiz);
                }
                $course->setFinalQuiz($generatedFinalQuiz);
            }

            try {
                $this->entityManager->flush();
                $this->addFlash('success', 'Course updated successfully!');
                return $this->redirectToRoute('app_course_details', ['id' => $course->getId()]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to update course ID ' . $id . ': ' . $e->getMessage());
                $this->addFlash('error', 'Failed to update course due to a database error: ' . $e->getMessage());
            }
        } elseif ($form->isSubmitted()) {
            $errors = [];
            foreach ($form->getErrors(true, true) as $error) {
                $errors[] = $error->getMessage();
            }
            $this->logger->error('Form validation errors for course ID ' . $id . ': ' . json_encode($errors));
            $this->addFlash('error', 'Form validation failed: ' . implode(', ', $errors));
        }

        return $this->render('course_update.html.twig', [
            'form' => $form->createView(),
            'course' => $course
        ]);
    }

    #[Route('/course/{id}', name: 'app_course_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $course = $entityManager->getRepository(Course::class)->find($id);
        if (!$course) {
            throw $this->createNotFoundException('Course not found');
        }

        if (!$this->isGranted('ROLE_ADMIN') && (!$this->isGranted('ROLE_TEACHER') || $course->getCreatedBy() !== $this->getUser())) {
            throw $this->createAccessDeniedException('You do not have permission to delete this course');
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete' . $id, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        try {
            $entityManager->remove($course);
            $entityManager->flush();
            $this->addFlash('success', 'Course deleted successfully!');
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete course ID ' . $id . ': ' . $e->getMessage());
            $this->addFlash('error', 'Failed to delete course due to a database error.');
        }

        return $this->redirectToRoute('app_courses');
    }
}