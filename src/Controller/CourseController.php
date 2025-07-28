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

    public function __construct(EntityManagerInterface $entityManager, CourseProgressService $progressService, QuizService $quizService, LoggerInterface $logger)
    {
        $this->entityManager = $entityManager;
        $this->progressService = $progressService;
        $this->quizService = $quizService;
        $this->logger = $logger;

        try {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Doctype', 'HTML5');
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
            $cacheDir = $this->getParameter('kernel.cache_dir');
            if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
                throw new \RuntimeException("Cache directory '$cacheDir' is not writable.");
            }
            $config->set('Cache.SerializerPath', $cacheDir);
            $this->purifier = new HTMLPurifier($config);
            $this->logger->info('HTMLPurifier initialized successfully with cache at ' . $cacheDir);
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

        if ($form->isSubmitted()) {
            if (!$form->isValid()) {
                $errors = [];
                foreach ($form->getErrors(true, true) as $error) {
                    $errors[] = $error->getMessage();
                }
                $this->logger->error('Form validation errors: ' . json_encode($errors));
                $this->addFlash('error', 'Form validation failed: ' . implode(', ', $errors));
            } else {
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
                    }
                }

                foreach ($form->get('parts') as $index => $partForm) {
                    $part = $partForm->getData();
                    $videoFile = $partForm->get('videoFile')->getData();
                    $videoDescription = $partForm->get('videoDescription')->getData();
                    $geogebraMaterialId = $partForm->get('geogebraMaterialId')->getData();
                    $tutorialContent = $partForm->get('tutorialContent')->getData();
                    $writtenSectionData = $partForm->get('writtenSection')->getData();
                    $mediaFiles = $request->files->get("course[parts][$index][writtenSection][mediaUploads]");
                    $mediaUrls = $request->request->get("course[parts][$index][writtenSection][mediaUrls]") ?: [];

                    if ($videoFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                        $newFilename = uniqid('video-') . '.' . $videoFile->guessExtension();
                        try {
                            $videoFile->move($this->getParameter('part_videos_directory'), $newFilename);
                            $video = new Video();
                            $video->setFilename($newFilename);
                            $video->setDescription($videoDescription);
                            $video->setDuration($part->getDuration());
                            $video->setPart($part);
                            $part->setVideo($video);
                            $this->entityManager->persist($video);
                        } catch (FileException $e) {
                            $this->logger->error('Video upload failed for part ID ' . ($part->getId() ?? 'new') . ': ' . $e->getMessage());
                            $this->addFlash('error', 'Failed to upload video for part "' . $part->getTitle() . '": ' . $e->getMessage());
                        }
                    }

                    $part->setGeogebraMaterialId($geogebraMaterialId);
                    $part->setTutorialContent($tutorialContent);

                    if ($writtenSectionData instanceof WrittenSection) {
                        $rawContent = $writtenSectionData->getContent() ?? '';
                        $this->logger->info("Raw content for part $index: " . $rawContent);
                        if ($this->purifier !== null) {
                            try {
                                $sanitizedContent = $this->purifier->purify($rawContent);
                                $this->logger->info("Sanitized content for part $index: " . $sanitizedContent);
                                $writtenSectionData->setContent($sanitizedContent);
                            } catch (\Exception $e) {
                                $this->logger->error("HTMLPurifier failed to purify content for part $index: " . $e->getMessage());
                                $writtenSectionData->setContent('');
                                $this->addFlash('warning', 'Content sanitization failed for part ' . ($index + 1) . '. Content has been cleared for safety.');
                            }
                        } else {
                            $this->logger->warning("HTMLPurifier not initialized, skipping content sanitization for part $index");
                            $writtenSectionData->setContent($rawContent); // Preserve raw content if purifier fails
                            $this->addFlash('warning', 'Content sanitization failed for part ' . ($index + 1) . '. Raw content preserved.');
                        }
                        $writtenSectionData->setPart($part);
                        $part->setWrittenSection($writtenSectionData);

                        // Process uploaded media files
                        if ($mediaFiles && is_array($mediaFiles)) {
                            foreach ($mediaFiles as $file) {
                                if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                                    $newFilename = uniqid('media-') . '.' . $file->guessExtension();
                                    $uploadDir = $this->getParameter('upload_directory');
                                    try {
                                        $file->move($uploadDir, $newFilename);
                                        $mediaUpload = new MediaUpload();
                                        $mediaUpload->setUrl($request->getSchemeAndHttpHost() . '/uploads/' . $newFilename);
                                        $mediaUpload->setType($file->guessExtension());
                                        $mediaUpload->setUploadedBy($this->getUser());
                                        $mediaUpload->setUploadedAt(new \DateTime());
                                        $writtenSectionData->addMediaUpload($mediaUpload);
                                        $this->entityManager->persist($mediaUpload);
                                        $this->logger->info("Media uploaded and persisted for part $index: " . $mediaUpload->getUrl());
                                    } catch (FileException $e) {
                                        $this->logger->error('Media upload failed for part ' . ($index + 1) . ': ' . $e->getMessage());
                                        $this->addFlash('error', 'Failed to upload media for part ' . ($index + 1) . ': ' . $e->getMessage());
                                    }
                                }
                            }
                        }

                        // Process media URLs
                        foreach ($mediaUrls as $url) {
                            if (filter_var($url, FILTER_VALIDATE_URL)) {
                                $mediaUpload = new MediaUpload();
                                $mediaUpload->setUrl($url);
                                $mediaUpload->setType(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'unknown');
                                $mediaUpload->setUploadedBy($this->getUser());
                                $mediaUpload->setUploadedAt(new \DateTime());
                                $writtenSectionData->addMediaUpload($mediaUpload);
                                $this->entityManager->persist($mediaUpload);
                                $this->logger->info("Media URL persisted for part $index: " . $url);
                            } else {
                                $this->logger->warning("Invalid URL skipped for part $index: " . $url);
                            }
                        }

                        $this->entityManager->persist($writtenSectionData);
                    }

                    $this->entityManager->persist($part);
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
            }

            return $this->render('course_create.html.twig', ['form' => $form->createView()]);
        }

        // Return the form view for GET requests or when form is not submitted
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
                // Use QueryBuilder for eager loading
                $current_part = $this->entityManager->createQueryBuilder()
                    ->select('p')
                    ->from(Part::class, 'p')
                    ->leftJoin('p.writtenSection', 'ws')
                    ->leftJoin('ws.mediaUploads', 'mu')
                    ->where('p.id = :id')
                    ->setParameter('id', $current_part->getId())
                    ->getQuery()
                    ->getOneOrNullResult();

                $part_quiz = $this->quizService->getOrGeneratePartQuiz($user, $current_part);
                if ($part_quiz) {
                    $current_quiz_attempt = $this->entityManager->getRepository(QuizAttempt::class)->findOneBy(
                        ['quiz' => $part_quiz, 'user' => $user],
                        ['takenAt' => 'DESC']
                    );
                }
            }

            if ($is_course_completed && !$final_quiz) {
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

    $form = $this->createForm(CourseType::class, $course, [
        'csrf_protection' => true,
        'csrf_field_name' => '_token',
        'csrf_token_id' => 'course_update',
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $imageFile = $form->get('image')->getData();
        if ($imageFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $newFilename = uniqid('course-') . '.' . $imageFile->guessExtension();
            try {
                $imageFile->move($this->getParameter('course_images_directory'), $newFilename);
                $course->setImage($newFilename);
            } catch (FileException $e) {
                $this->logger->error('Course image upload failed: ' . $e->getMessage());
                $this->addFlash('error', 'Failed to upload course image: ' . $e->getMessage());
            }
        }

        $user = $this->getUser();
        $failedQuizzes = [];

        foreach ($form->get('parts') as $index => $partForm) {
            $part = $partForm->getData();
            $existingPart = $part->getId() ? $this->entityManager->getRepository(Part::class)->find($part->getId()) : null;

            // Enhanced regeneration condition
            $shouldRegenerateQuiz = !$existingPart || !$this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => $part]) ||
                ($existingPart && (
                    $existingPart->getTitle() !== $part->getTitle() ||
                    $existingPart->getDescription() !== $part->getDescription() ||
                    ($existingPart->getWrittenSection() && $part->getWrittenSection() && $existingPart->getWrittenSection()->getContent() !== $part->getWrittenSection()->getContent()) ||
                    ($existingPart->getTutorialContent() !== $part->getTutorialContent()) ||
                    ($existingPart->getVideo() && $part->getVideo() && $existingPart->getVideo()->getDescription() !== $part->getVideo()->getDescription())
                ));
            $this->logger->debug('shouldRegenerateQuiz for part ID ' . ($part->getId() ?? 'new') . ': ' . ($shouldRegenerateQuiz ? 'true' : 'false') . ', reasons: ' . json_encode([
                'title_changed' => $existingPart ? $existingPart->getTitle() !== $part->getTitle() : false,
                'desc_changed' => $existingPart ? $existingPart->getDescription() !== $part->getDescription() : false,
                'written_changed' => $existingPart && $existingPart->getWrittenSection() && $part->getWrittenSection() ? $existingPart->getWrittenSection()->getContent() !== $part->getWrittenSection()->getContent() : false,
                'tutorial_changed' => $existingPart ? $existingPart->getTutorialContent() !== $part->getTutorialContent() : false,
                'video_desc_changed' => $existingPart && $existingPart->getVideo() && $part->getVideo() ? $existingPart->getVideo()->getDescription() !== $part->getVideo()->getDescription() : false,
            ]));

            if ($shouldRegenerateQuiz) {
                $existingQuiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => $part]);
                if ($existingQuiz) {
                    $this->entityManager->remove($existingQuiz);
                    $this->entityManager->flush(); // Immediate flush to ensure deletion
                    $this->logger->info('Deleted existing quiz for part ID ' . $part->getId() . ' due to content change');
                }

                $newQuiz = $this->quizService->getOrGeneratePartQuiz($user, $part);
                if (!$newQuiz || !$newQuiz->isGeneratedByAI()) {
                    $failedQuizzes[] = $part->getTitle();
                    $this->logger->warning('Failed to generate AI quiz for part ' . $part->getTitle() . '. Using fallback quiz.');
                } else {
                    $this->logger->info('Successfully generated new quiz for part ID ' . $part->getId());
                }
            }

            $videoFile = $partForm->get('videoFile')->getData();
            $videoDescription = $partForm->get('videoDescription')->getData();
            $geogebraMaterialId = $partForm->get('geogebraMaterialId')->getData();
            $tutorialContent = $partForm->get('tutorialContent')->getData();
            $writtenSectionData = $partForm->get('writtenSection')->getData();
            $mediaFiles = $request->files->get("course[parts][$index][writtenSection][mediaUploads]");

            if ($videoFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                $newFilename = uniqid('video-') . '.' . $videoFile->guessExtension();
                try {
                    $videoFile->move($this->getParameter('part_videos_directory'), $newFilename);
                    $video = $part->getVideo() ?? new Video();
                    $video->setFilename($newFilename);
                    $video->setDescription($videoDescription);
                    $video->setDuration($part->getDuration());
                    $video->setPart($part);
                    $part->setVideo($video);
                    $this->entityManager->persist($video);
                } catch (FileException $e) {
                    $this->logger->error('Video upload failed for part ID ' . ($part->getId() ?? 'new') . ': ' . $e->getMessage());
                    $this->addFlash('error', 'Failed to upload video for part "' . $part->getTitle() . '": ' . $e->getMessage());
                }
            }

            $part->setGeogebraMaterialId($geogebraMaterialId);
            $part->setTutorialContent($tutorialContent);

            if ($writtenSectionData instanceof WrittenSection) {
                $rawContent = $writtenSectionData->getContent() ?? '';
                $this->logger->info("Raw content for part $index: " . $rawContent);
                if ($this->purifier !== null) {
                    try {
                        $sanitizedContent = $this->purifier->purify($rawContent);
                        $this->logger->info("Sanitized content for part $index: " . $sanitizedContent);
                        $writtenSectionData->setContent($sanitizedContent);
                    } catch (\Exception $e) {
                        $this->logger->error("HTMLPurifier failed to purify content for part $index: " . $e->getMessage());
                        $writtenSectionData->setContent('');
                        $this->addFlash('warning', 'Content sanitization failed for part ' . ($index + 1) . '. Content has been cleared for safety.');
                    }
                } else {
                    $this->logger->warning("HTMLPurifier not initialized, skipping content sanitization for part $index");
                    $writtenSectionData->setContent($rawContent);
                    $this->addFlash('warning', 'Content sanitization failed for part ' . ($index + 1) . '. Raw content preserved.');
                }
                $writtenSectionData->setPart($part);
                $part->setWrittenSection($writtenSectionData);

                if ($mediaFiles && is_array($mediaFiles)) {
                    foreach ($mediaFiles as $file) {
                        if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                            $newFilename = uniqid('media-') . '.' . $file->guessExtension();
                            $uploadDir = $this->getParameter('upload_directory');
                            try {
                                $file->move($uploadDir, $newFilename);
                                $mediaUpload = new MediaUpload();
                                $mediaUpload->setUrl($request->getSchemeAndHttpHost() . '/uploads/' . $newFilename);
                                $mediaUpload->setType($file->guessExtension());
                                $mediaUpload->setUploadedBy($this->getUser());
                                $mediaUpload->setUploadedAt(new \DateTime());
                                $writtenSectionData->addMediaUpload($mediaUpload);
                                $this->entityManager->persist($mediaUpload);
                                $this->logger->info("Media uploaded and persisted for part $index: " . $mediaUpload->getUrl());
                            } catch (FileException $e) {
                                $this->logger->error('Media upload failed for part ' . ($index + 1) . ': ' . $e->getMessage());
                                $this->addFlash('error', 'Failed to upload media for part ' . ($index + 1) . ': ' . $e->getMessage());
                            }
                        }
                    }
                }
                $this->entityManager->persist($writtenSectionData);
            }

            $this->entityManager->persist($part);
        }

        try {
            $this->entityManager->flush();
            if ($this->progressService->isCourseCompleted($user, $course)) {
                $finalQuiz = $this->quizService->getOrGenerateFinalQuiz($user, $course);
                if (!$finalQuiz || !$finalQuiz->isGeneratedByAI()) {
                    $this->addFlash('warning', 'Failed to generate AI final quiz for course. Using fallback quiz.');
                }
            }
            if (!empty($failedQuizzes)) {
                $this->addFlash('warning', 'Failed to generate AI quizzes for parts: ' . implode(', ', $failedQuizzes) . '. Using fallback quizzes.');
            }
            $this->addFlash('success', 'Course updated successfully!');
            return $this->redirectToRoute('app_courses');
        } catch (\Exception $e) {
            $this->logger->error('Failed to update course: ' . $e->getMessage());
            $this->addFlash('error', 'Failed to update course due to a database error.');
        }
    }

    return $this->render('course_update.html.twig', ['form' => $form->createView(), 'course' => $course]);
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
            $location = $request->getSchemeAndHttpHost() . '/uploads/' . $newFilename;
            $this->logger->info('Image uploaded successfully: ' . $location);
            // Return TinyMCE-compatible response
            return new JsonResponse([
                'location' => $location,
                'message' => 'Image uploaded successfully'
            ]);
        } catch (FileException $e) {
            $this->logger->error('Image upload failed: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Failed to upload image: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error during image upload: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Unexpected server error: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/upload/video', name: 'upload_video', methods: ['POST'])]
    public function uploadVideo(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file || !$file->isValid()) {
            $this->logger->error('No video file uploaded or file is invalid');
            return new JsonResponse(['error' => 'No video file uploaded or invalid file'], 400);
        }

        $allowedExtensions = ['mp4', 'webm', 'ogg'];
        $extension = $file->guessExtension();
        if (!in_array($extension, $allowedExtensions)) {
            $this->logger->error('Invalid video file type: ' . $extension);
            return new JsonResponse(['error' => 'Invalid file type. Only MP4, WebM, and OGG are allowed.'], 400);
        }

        $uploadDir = $this->getParameter('part_videos_directory');
        $this->logger->info('Video upload directory: ' . $uploadDir);
        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
            $this->logger->error('Video upload directory is not writable: ' . $uploadDir);
            return new JsonResponse(['error' => 'Server configuration error: Upload directory not writable'], 500);
        }

        $newFilename = uniqid('video-') . '.' . $extension;
        try {
            $file->move($uploadDir, $newFilename);
            $location = $request->getSchemeAndHttpHost() . '/uploads/videos/' . $newFilename;
            $this->logger->info('Video uploaded successfully: ' . $location);
            return new JsonResponse(['location' => $location]);
        } catch (FileException $e) {
            $this->logger->error('Video upload failed: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Failed to upload video: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error during video upload: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Unexpected server error: ' . $e->getMessage()], 500);
        }
    }
    #[Route('/debug/quiz/generate/{partId}', name: 'debug_quiz_generate', methods: ['GET'])]
public function debugQuizGenerate(int $partId): JsonResponse
{
    $part = $this->entityManager->getRepository(Part::class)->find($partId);
    if (!$part) {
        return new JsonResponse(['error' => 'Part not found'], 404);
    }

    $quiz = $this->quizService->getOrGeneratePartQuiz($this->getUser(), $part);
    if (!$quiz) {
        return new JsonResponse(['error' => 'Failed to generate quiz'], 500);
    }

    return new JsonResponse([
        'quiz_id' => $quiz->getId(),
        'title' => $quiz->getTitle(),
        'questions' => array_map(function ($question) {
            return [
                'text' => $question->getText(),
                'type' => $question->getType(),
                'options' => $question->getOptions(),
                'correct_answer' => $question->getCorrectAnswer(),
            ];
        }, $quiz->getQuestions()->toArray()),
        'generated_by_ai' => $quiz->isGeneratedByAI(),
    ]);
}
}