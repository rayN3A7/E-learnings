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
            $config->set('HTML.DefinitionID', 'html5-definitions');
            $config->set('HTML.DefinitionRev', 1);
            if ($def = $config->maybeGetRawHTMLDefinition()) {
                $def->addElement('video', 'Block', 'Optional: (source, Flow) | (Flow, source) | Flow', 'Common', [
                    'src' => 'URI',
                    'controls' => 'Bool',
                    'width' => 'Length',
                    'height' => 'Length',
                ]);
            }
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
                // Ignore regenerate in create since it's new
                $videoFile = $partForm->get('video')->get('filename')->getData();
                $mediaUploads = $partForm->get('writtenSection')->get('mediaUploads')->getData() ?? [];
                $mediaUrls = $partForm->get('writtenSection')->get('mediaUrls')->getData() ?? [];

                // Persist WrittenSection
                $writtenSection = $part->getWrittenSection();
                if ($writtenSection && !$writtenSection->getId()) {
                    $writtenSection->setPart($part);
                    $this->entityManager->persist($writtenSection);
                }

                if ($this->purifier && $writtenSection && $writtenSection->getContent()) {
                    try {
                        $sanitizedContent = $this->purifier->purify($writtenSection->getContent());
                        $writtenSection->setContent($sanitizedContent);
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to purify written content for part ' . ($index + 1) . ': ' . $e->getMessage() . ' Content: ' . $writtenSection->getContent());
                    }
                }

                // Handle media URLs (append to content as <img> or <video> tags)
                foreach ($mediaUrls as $url) {
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
                        if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif'])) {
                            $writtenSection->setContent($writtenSection->getContent() . '<img src="' . $url . '" alt="Media URL">');
                        } elseif (in_array(strtolower($ext), ['mp4', 'webm', 'ogg'])) {
                            $writtenSection->setContent($writtenSection->getContent() . '<video src="' . $url . '" controls></video>');
                        }
                    }
                }

                // Handle media uploads
                foreach ($mediaUploads as $mediaFile) {
                    if ($mediaFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                        $newFilename = uniqid('media-') . '.' . $mediaFile->guessExtension();
                        try {
                            $mediaFile->move($this->getParameter('written_media_directory'), $newFilename);
                            $mediaUpload = new MediaUpload();
                            $mediaUpload->setUrl($newFilename);  // Fixed: Use setUrl instead of setFilename
                            $mediaUpload->setType(pathinfo($newFilename, PATHINFO_EXTENSION)); // Simplified type
                            $mediaUpload->setUploadedAt(new \DateTime());
                            $mediaUpload->setUploadedBy($this->getUser());
                            $mediaUpload->setWrittenSection($writtenSection);
                            $this->entityManager->persist($mediaUpload);
                            $writtenSection->addMediaUpload($mediaUpload);
                        } catch (FileException $e) {
                            $this->logger->error('Media upload failed for part ' . ($index + 1) . ': ' . $e->getMessage());
                            $this->addFlash('error', 'Failed to upload media for part ' . ($index + 1) . ': ' . $e->getMessage());
                            return $this->render('course_create.html.twig', ['form' => $form->createView()]);
                        }
                    }
                }

                // Persist Video
                if ($videoFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                    $newFilename = uniqid('video-') . '.' . $videoFile->guessExtension();
                    try {
                        $videoFile->move($this->getParameter('part_videos_directory'), $newFilename);
                        $video = $part->getVideo() ?: new Video();
                        $video->setFilename($newFilename);
                        $video->setDescription($partForm->get('video')->get('description')->getData());
                        $video->setDuration($partForm->get('video')->get('duration')->getData());
                        $video->setPart($part);
                        $this->entityManager->persist($video);
                        $part->setVideo($video);
                    } catch (FileException $e) {
                        $this->logger->error('Video upload failed for part ' . ($index + 1) . ': ' . $e->getMessage());
                        $this->addFlash('error', 'Failed to upload video for part ' . ($index + 1) . ': ' . $e->getMessage());
                        return $this->render('course_create.html.twig', ['form' => $form->createView()]);
                    }
                }

                // Persist Quiz
                if ($quizMode === 'manual' && $quizData instanceof Quiz) {
                    $questions = $quizData->getQuestions();
                    foreach ($questions as $question) {
                        $question->setQuiz($quizData);
                        $this->entityManager->persist($question);
                    }
                    $quizData->setPart($part);
                    $quizData->setGeneratedByAI(false);
                    $this->entityManager->persist($quizData);
                } elseif ($quizMode === 'ai') {
                    $generatedQuiz = $this->quizService->getOrGeneratePartQuiz($this->getUser(), $part, $quizMode);
                    if ($generatedQuiz) {
                        if (!$this->entityManager->contains($generatedQuiz)) {
                            $generatedQuiz->setPart($part);
                            $this->entityManager->persist($generatedQuiz);
                        }
                        $part->setQuiz($generatedQuiz);
                    }
                }

                $course->addPart($part);
                $this->entityManager->persist($part);
            }

            $finalQuizData = $form->get('finalQuiz')->getData();
            $finalQuizMode = $form->get('quizMode')->getData() ?? 'ai';
            if ($finalQuizMode === 'manual' && $finalQuizData instanceof Quiz) {
                $questions = $finalQuizData->getQuestions();
                foreach ($questions as $question) {
                    $question->setQuiz($finalQuizData);
                    $this->entityManager->persist($question);
                }
                $finalQuizData->setCourse($course);
                $finalQuizData->setGeneratedByAI(false);
                $this->entityManager->persist($finalQuizData);
                $course->setFinalQuiz($finalQuizData);
            } elseif ($finalQuizMode === 'ai') {
                $generatedFinalQuiz = $this->quizService->getOrGenerateFinalQuiz(null, $course, $finalQuizMode);
                if ($generatedFinalQuiz) {
                    if (!$this->entityManager->contains($generatedFinalQuiz)) {
                        $generatedFinalQuiz->setCourse($course);
                        $this->entityManager->persist($generatedFinalQuiz);
                    }
                    $course->setFinalQuiz($generatedFinalQuiz);
                }
            }

            // Log entity state before flush
            $this->logger->info('Course: ' . json_encode([
                'id' => $course->getId(),
                'title' => $course->getTitle(),
                'createdBy' => $course->getCreatedBy() ? $course->getCreatedBy()->getId() : null,
                'parts' => array_map(fn($part) => [
                    'id' => $part->getId(),
                    'title' => $part->getTitle(),
                    'writtenSection' => $part->getWrittenSection() ? $part->getWrittenSection()->getId() : null,
                    'quiz' => $part->getQuiz() ? $part->getQuiz()->getId() : null,
                    'video' => $part->getVideo() ? $part->getVideo()->getId() : null,
                ], $course->getParts()->toArray()),
                'finalQuiz' => $course->getFinalQuiz() ? $course->getFinalQuiz()->getId() : null,
            ], JSON_PRETTY_PRINT));

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

    #[Route('/course/{id}/update', name: 'app_course_update', methods: ['GET', 'POST'])]
    public function update(int $id, Request $request, \Symfony\Component\String\Slugger\SluggerInterface $slugger): Response
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

            // Handle parts
            $existingParts = $course->getParts()->toArray();
            $submittedParts = $form->get('parts')->getData()->toArray();
            $existingPartIds = array_map(fn($part) => $part->getId(), $existingParts);
            $submittedPartIds = array_map(fn($part) => $part->getId(), $submittedParts);

            // Remove parts that are no longer in the form
            foreach ($existingParts as $part) {
                if (!in_array($part->getId(), $submittedPartIds)) {
                    $course->removePart($part);
                    $this->entityManager->remove($part);
                }
            }

            foreach ($form->get('parts') as $index => $partForm) {
                $part = $partForm->getData();
                $quizData = $partForm->get('quiz')->getData();
                $quizMode = $partForm->get('quizMode')->getData() ?? 'ai';
                $regenerate = $partForm->get('regenerateQuiz')->getData();
                $videoFile = $partForm->get('video')->get('filename')->getData();
                $mediaUploads = $partForm->get('writtenSection')->get('mediaUploads')->getData() ?? [];
                $mediaUrls = $partForm->get('writtenSection')->get('mediaUrls')->getData() ?? [];

                // Persist WrittenSection
                $writtenSection = $part->getWrittenSection();
                if ($writtenSection && !$writtenSection->getId()) {
                    $writtenSection->setPart($part);
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

                // Handle media URLs (append to content as <img> or <video> tags)
                foreach ($mediaUrls as $url) {
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
                        if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif'])) {
                            $writtenSection->setContent($writtenSection->getContent() . '<img src="' . $url . '" alt="Media URL">');
                        } elseif (in_array(strtolower($ext), ['mp4', 'webm', 'ogg'])) {
                            $writtenSection->setContent($writtenSection->getContent() . '<video src="' . $url . '" controls></video>');
                        }
                    }
                }

                // Handle media uploads
                foreach ($mediaUploads as $mediaFile) {
                    if ($mediaFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                        $newFilename = uniqid('media-') . '.' . $mediaFile->guessExtension();
                        try {
                            $mediaFile->move($this->getParameter('written_media_directory'), $newFilename);
                            $mediaUpload = new MediaUpload();
                            $mediaUpload->setUrl($newFilename);  // Fixed: Use setUrl instead of setFilename
                            $mediaUpload->setType(pathinfo($newFilename, PATHINFO_EXTENSION)); // Simplified type
                            $mediaUpload->setUploadedAt(new \DateTime());
                            $mediaUpload->setUploadedBy($this->getUser());
                            $mediaUpload->setWrittenSection($writtenSection);
                            $this->entityManager->persist($mediaUpload);
                            $writtenSection->addMediaUpload($mediaUpload);
                        } catch (FileException $e) {
                            $this->logger->error('Media upload failed for part ' . ($index + 1) . ': ' . $e->getMessage());
                            $this->addFlash('error', 'Failed to upload media for part ' . ($index + 1) . ': ' . $e->getMessage());
                            return $this->render('course_update.html.twig', ['form' => $form->createView(), 'course' => $course]);
                        }
                    }
                }

                // Persist Video
                if ($videoFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                    $newFilename = uniqid('video-') . '.' . $videoFile->guessExtension();
                    try {
                        $videoFile->move($this->getParameter('part_videos_directory'), $newFilename);
                        $video = $part->getVideo() ?: new Video();
                        $video->setFilename($newFilename);
                        $video->setDescription($partForm->get('video')->get('description')->getData());
                        $video->setDuration($partForm->get('video')->get('duration')->getData());
                        $video->setPart($part);
                        $this->entityManager->persist($video);
                        $part->setVideo($video);
                    } catch (FileException $e) {
                        $this->logger->error('Video upload failed for part ' . ($index + 1) . ': ' . $e->getMessage());
                        $this->addFlash('error', 'Failed to upload video for part ' . ($index + 1) . ': ' . $e->getMessage());
                        return $this->render('course_update.html.twig', ['form' => $form->createView(), 'course' => $course]);
                    }
                }

                // Handle Quiz - Robust regeneration
                $existingQuizzes = $this->entityManager->getRepository(Quiz::class)->findBy(['part' => $part]);
                $needsRegeneration = false;

                if ($quizMode === 'manual' && $quizData instanceof Quiz) {
                    // Remove any existing quizzes (AI or orphan)
                    foreach ($existingQuizzes as $oldQuiz) {
                        $oldQuiz->setPart(null);
                        $this->entityManager->persist($oldQuiz);
                        $this->entityManager->flush();
                        $this->entityManager->remove($oldQuiz);
                    }
                    $this->entityManager->flush();
                    $this->quizService->invalidatePartQuizCache($part, 'ai');

                    $questions = $quizData->getQuestions();
                    foreach ($questions as $question) {
                        $question->setQuiz($quizData);
                        if (!$this->entityManager->contains($question)) {
                            $this->entityManager->persist($question);
                        }
                    }
                    $quizData->setPart($part);
                    $quizData->setGeneratedByAI(false);
                    if (!$this->entityManager->contains($quizData)) {
                        $this->entityManager->persist($quizData);
                    }
                    $part->setQuiz($quizData);
                } elseif ($quizMode === 'ai') {
                    $existingQuiz = !empty($existingQuizzes) ? $existingQuizzes[0] : null;
                    if ($existingQuiz && !$existingQuiz->isGeneratedByAI()) {
                        $needsRegeneration = true;
                    } elseif ($existingQuiz && $regenerate) {
                        $needsRegeneration = true;
                    }

                    if ($needsRegeneration) {
                        foreach ($existingQuizzes as $oldQuiz) {
                            $oldQuiz->setPart(null);
                            $this->entityManager->persist($oldQuiz);
                            $this->entityManager->flush();
                            $this->entityManager->remove($oldQuiz);
                        }
                        $this->entityManager->flush();
                        $this->quizService->invalidatePartQuizCache($part, $quizMode);
                        $part->setQuiz(null);
                    }

                    $generatedQuiz = $this->quizService->getOrGeneratePartQuiz($this->getUser(), $part, $quizMode);
                    if ($generatedQuiz) {
                        if (!$this->entityManager->contains($generatedQuiz)) {
                            $generatedQuiz->setPart($part);
                            $this->entityManager->persist($generatedQuiz);
                        }
                        $part->setQuiz($generatedQuiz);
                    }
                }

                if (!$course->getParts()->contains($part)) {
                    $course->addPart($part);
                }
                if (!$this->entityManager->contains($part)) {
                    $this->entityManager->persist($part);
                }
            }

            // Handle Final Quiz - Robust regeneration
            $finalQuizMode = $form->get('quizMode')->getData() ?? 'ai';
            $regenerateFinal = $form->get('regenerateFinalQuiz')->getData();
            $existingFinalQuizzes = $this->entityManager->getRepository(Quiz::class)->findBy(['course' => $course]);
            $needsFinalRegeneration = false;

            $finalQuizData = $form->get('finalQuiz')->getData();
            if ($finalQuizMode === 'manual' && $finalQuizData instanceof Quiz) {
                // Remove any existing final quizzes
                foreach ($existingFinalQuizzes as $oldFinalQuiz) {
                    $course->setFinalQuiz(null);
                    $this->entityManager->flush();
                    $this->entityManager->remove($oldFinalQuiz);
                }
                $this->entityManager->flush();
                $this->quizService->invalidateFinalQuizCache($course, 'ai');

                $questions = $finalQuizData->getQuestions();
                foreach ($questions as $question) {
                    $question->setQuiz($finalQuizData);
                    if (!$this->entityManager->contains($question)) {
                        $this->entityManager->persist($question);
                    }
                }
                $finalQuizData->setCourse($course);
                $finalQuizData->setGeneratedByAI(false);
                if (!$this->entityManager->contains($finalQuizData)) {
                    $this->entityManager->persist($finalQuizData);
                }
                $course->setFinalQuiz($finalQuizData);
            } elseif ($finalQuizMode === 'ai') {
                $existingFinalQuiz = !empty($existingFinalQuizzes) ? $existingFinalQuizzes[0] : null;
                if ($existingFinalQuiz && !$existingFinalQuiz->isGeneratedByAI()) {
                    $needsFinalRegeneration = true;
                } elseif ($existingFinalQuiz && $regenerateFinal) {
                    $needsFinalRegeneration = true;
                }

                if ($needsFinalRegeneration) {
                    foreach ($existingFinalQuizzes as $oldFinalQuiz) {
                        $course->setFinalQuiz(null);
                        $this->entityManager->flush();
                        $this->entityManager->remove($oldFinalQuiz);
                    }
                    $this->entityManager->flush();
                    $this->quizService->invalidateFinalQuizCache($course, $finalQuizMode);
                }

                $generatedFinalQuiz = $this->quizService->getOrGenerateFinalQuiz(null, $course, $finalQuizMode);
                if ($generatedFinalQuiz) {
                    if (!$this->entityManager->contains($generatedFinalQuiz)) {
                        $generatedFinalQuiz->setCourse($course);
                        $this->entityManager->persist($generatedFinalQuiz);
                    }
                    $course->setFinalQuiz($generatedFinalQuiz);
                }
            }

            // Log entity state before flush
            $this->logger->info('Course update: ' . json_encode([
                'id' => $course->getId(),
                'title' => $course->getTitle(),
                'createdBy' => $course->getCreatedBy() ? $course->getCreatedBy()->getId() : null,
                'parts' => array_map(fn($part) => [
                    'id' => $part->getId(),
                    'title' => $part->getTitle(),
                    'writtenSection' => $part->getWrittenSection() ? $part->getWrittenSection()->getId() : null,
                    'quiz' => $part->getQuiz() ? $part->getQuiz()->getId() : null,
                    'video' => $part->getVideo() ? $part->getVideo()->getId() : null,
                ], $course->getParts()->toArray()),
                'finalQuiz' => $course->getFinalQuiz() ? $course->getFinalQuiz()->getId() : null,
            ], JSON_PRETTY_PRINT));

            try {
                $this->entityManager->flush();
                $this->addFlash('success', 'Course updated successfully!');
                return $this->redirectToRoute('app_course_details', ['id' => $course->getId()]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to update course ID ' . $id . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
                $this->addFlash('error', 'Failed to update course: ' . $e->getMessage());
                return $this->render('course_update.html.twig', ['form' => $form->createView(), 'course' => $course]);
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
            'course' => $course,
        ]);
    }

    #[Route('/course/{id}/join', name: 'app_join_course', methods: ['GET', 'POST'])]
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

    #[Route('/course/{id}', name: 'app_course_details', methods: ['GET'])]
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
                    $part_quiz->setPart($current_part);
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
                    $final_quiz->setCourse($course);
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

    #[Route('/course/{id}/delete', name: 'app_course_delete', methods: ['POST'])]
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