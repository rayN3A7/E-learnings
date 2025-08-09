<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\Course;
use App\Entity\Enrollment;
use App\Entity\MediaUpload;
use App\Entity\Part;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/forum')]
class ForumController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private SluggerInterface $slugger;
    private LoggerInterface $logger;
    private CsrfTokenManagerInterface $csrfTokenManager;

    public function __construct(EntityManagerInterface $entityManager, SluggerInterface $slugger, LoggerInterface $logger, CsrfTokenManagerInterface $csrfTokenManager)
    {
        $this->entityManager = $entityManager;
        $this->slugger = $slugger;
        $this->logger = $logger;
        $this->csrfTokenManager = $csrfTokenManager;
    }

    #[Route('/course/{id}', name: 'app_forum_course', methods: ['GET', 'POST'])]
    public function courseForum(int $id, Request $request): Response
    {
        $course = $this->entityManager->getRepository(Course::class)->find($id);
        if (!$course) {
            throw $this->createNotFoundException('Course not found');
        }

        $user = $this->getUser();
        $isAllowed = $this->isGranted('ROLE_TEACHER') || $this->isGranted('ROLE_ADMIN');
        if (!$isAllowed && $this->isGranted('ROLE_CLIENT')) {
            $enrollment = $this->entityManager->getRepository(Enrollment::class)->findOneBy(['user' => $user, 'course' => $course]);
            $isAllowed = (bool) $enrollment;
        }
        if (!$isAllowed) {
            throw $this->createAccessDeniedException('You must be enrolled in this course or have teacher/admin privileges to access the forum.');
        }

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('forum_post', $token)) {
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
                }
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_forum_course', ['id' => $id]);
            }

            $comment = new Comment();
            $comment->setUser($this->getUser());
            $comment->setContent($request->request->get('content'));
            $comment->setPostedAt(new \DateTime());

            $parentId = $request->request->get('parentId');
            if ($parentId) {
                $parent = $this->entityManager->getRepository(Comment::class)->find($parentId);
                if ($parent) {
                    $comment->setParent($parent);
                    if ($parent->getPart()) {
                        $comment->setPart($parent->getPart());
                    } else {
                        $comment->setCourse($parent->getCourse());
                    }
                } else {
                    if ($request->isXmlHttpRequest()) {
                        return new JsonResponse(['error' => 'Invalid parent comment.'], 400);
                    }
                    $this->addFlash('error', 'Invalid parent comment.');
                    return $this->redirectToRoute('app_forum_course', ['id' => $id]);
                }
            } else {
                $comment->setCourse($course);
            }

            // Ensure upload directory exists
            $dir = $this->getParameter('comment_media_directory');
            $filesystem = new Filesystem();
            if (!$filesystem->exists($dir)) {
                try {
                    $filesystem->mkdir($dir, 0777);
                    $this->logger->info('Created upload directory: ' . $dir);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to create upload directory: ' . $e->getMessage());
                    if ($request->isXmlHttpRequest()) {
                        return new JsonResponse(['error' => 'Server error: Unable to create upload directory.'], 500);
                    }
                    $this->addFlash('error', 'Server error: Unable to create upload directory.');
                    return $this->redirectToRoute('app_forum_course', ['id' => $id]);
                }
            }

            // Handle media uploads with error handling
            $mediaFiles = $request->files->get('media');
            $uploadErrors = []; // Collect errors
            $mimeTypes = new MimeTypes(); // For server-side MIME guessing
            if ($mediaFiles) {
                foreach ($mediaFiles as $mediaFile) {
                    if ($mediaFile) {
                        $originalFilename = pathinfo($mediaFile->getClientOriginalName(), PATHINFO_FILENAME);
                        $safeFilename = $this->slugger->slug($originalFilename);
                        $newFilename = $safeFilename . '-' . uniqid() . '.' . $mediaFile->guessExtension();

                        try {
                            $mediaFile->move($dir, $newFilename);

                            // Verify file was moved
                            $fullPath = $dir . '/' . $newFilename;
                            if (!$filesystem->exists($fullPath)) {
                                throw new FileException('File move failed: File not found after move.');
                            }

                            $this->logger->info('Successfully uploaded file: ' . $fullPath);

                            $mediaUpload = new MediaUpload();
                            $mediaUpload->setUrl('uploads/comment_media/' . $newFilename);

                            $mimeType = $mimeTypes->guessMimeType($fullPath) ?? $mediaFile->getClientMimeType() ?? 'application/octet-stream';
                            $mediaUpload->setType($mimeType);
                            $this->logger->info('Detected MIME type: ' . $mimeType . ' for file: ' . $newFilename);

                            $mediaUpload->setUploadedBy($this->getUser());
                            $mediaUpload->setUploadedAt(new \DateTime());
                            $mediaUpload->setComment($comment);

                            $this->entityManager->persist($mediaUpload);
                            $comment->addMediaUpload($mediaUpload);
                        } catch (\Exception $e) { // Broader catch to log any unexpected errors
                            $this->logger->error('File upload failed for comment: ' . $e->getMessage() . ' - File: ' . $mediaFile->getClientOriginalName());
                            $uploadErrors[] = $mediaFile->getClientOriginalName() . ': ' . $e->getMessage();
                        }
                    }
                }
            }

            $this->entityManager->persist($comment);
            $this->entityManager->flush();

            // Handle upload errors in response
            if (!empty($uploadErrors)) {
                $errorMsg = 'Comment posted, but some media uploads failed: ' . implode(', ', $uploadErrors);
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(['success' => true, 'warning' => $errorMsg], 206); // Partial success
                }
                $this->addFlash('warning', $errorMsg);
                return $this->redirectToRoute('app_forum_course', ['id' => $id]);
            }

            if ($request->isXmlHttpRequest()) {
                $media = [];
                foreach ($comment->getMediaUploads() as $m) {
                    $media[] = ['url' => $m->getUrl(), 'type' => $m->getType()];
                }
                return new JsonResponse([
                    'success' => true,
                    'comment' => [
                        'id' => $comment->getId(),
                        'content' => $comment->getContent(),
                        'username' => $comment->getUser()->getUsername(),
                        'role' => $comment->getUser()->getRole(),
                        'postedAt' => $comment->getPostedAt()->format('Y-m-d H:i'),
                        'parentId' => $parentId,
                        'partId' => $comment->getPart() ? $comment->getPart()->getId() : null,
                        'avatar' => $comment->getUser()->getAvatar() ? '/uploads/avatars/' . $comment->getUser()->getAvatar() : 'https://placehold.co/48x48.png',
                        'media' => $media,
                        'userId' => $comment->getUser()->getId(),
                        'edit_token' => $this->csrfTokenManager->getToken('comment_edit_' . $comment->getId())->getValue(),
                        'delete_token' => $this->csrfTokenManager->getToken('comment_delete_' . $comment->getId())->getValue(),
                    ]
                ]);
            }

            $this->addFlash('success', 'Comment posted successfully!');
            return $this->redirectToRoute('app_forum_course', ['id' => $id]);
        }

        $comments = $this->entityManager->getRepository(Comment::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.part', 'p')
            ->where('c.course = :course OR p.course = :course')
            ->andWhere('c.parent IS NULL')
            ->setParameter('course', $course)
            ->orderBy('c.postedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('forum/course.html.twig', [
            'course' => $course,
            'comments' => $comments,
        ]);
    }

    #[Route('/part/{id}', name: 'app_forum_part', methods: ['GET', 'POST'])]
    public function partForum(int $id, Request $request): Response
    {
        $part = $this->entityManager->getRepository(Part::class)->find($id);
        if (!$part) {
            throw $this->createNotFoundException('Part not found');
        }

        $course = $part->getCourse();
        $user = $this->getUser();
        $isAllowed = $this->isGranted('ROLE_TEACHER') || $this->isGranted('ROLE_ADMIN');
        if (!$isAllowed && $this->isGranted('ROLE_CLIENT')) {
            $enrollment = $this->entityManager->getRepository(Enrollment::class)->findOneBy(['user' => $user, 'course' => $course]);
            $isAllowed = (bool) $enrollment;
        }
        if (!$isAllowed) {
            throw $this->createAccessDeniedException('You must be enrolled in this course or have teacher/admin privileges to access the forum.');
        }

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('forum_post', $token)) {
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
                }
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_forum_part', ['id' => $id]);
            }

            $comment = new Comment();
            $comment->setUser($this->getUser());
            $comment->setPart($part);
            $comment->setContent($request->request->get('content'));
            $comment->setPostedAt(new \DateTime());

            $parentId = $request->request->get('parentId');
            if ($parentId) {
                $parent = $this->entityManager->getRepository(Comment::class)->find($parentId);
                if ($parent && $parent->getPart() === $part) {
                    $comment->setParent($parent);
                } else {
                    if ($request->isXmlHttpRequest()) {
                        return new JsonResponse(['error' => 'Invalid parent comment.'], 400);
                    }
                    $this->addFlash('error', 'Invalid parent comment.');
                    return $this->redirectToRoute('app_forum_part', ['id' => $id]);
                }
            }

            // Ensure upload directory exists
            $dir = $this->getParameter('comment_media_directory');
            $filesystem = new Filesystem();
            if (!$filesystem->exists($dir)) {
                try {
                    $filesystem->mkdir($dir, 0777);
                    $this->logger->info('Created upload directory: ' . $dir);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to create upload directory: ' . $e->getMessage());
                    if ($request->isXmlHttpRequest()) {
                        return new JsonResponse(['error' => 'Server error: Unable to create upload directory.'], 500);
                    }
                    $this->addFlash('error', 'Server error: Unable to create upload directory.');
                    return $this->redirectToRoute('app_forum_part', ['id' => $id]);
                }
            }

            // Handle media uploads with error handling
            $mediaFiles = $request->files->get('media');
            $uploadErrors = [];
            $mimeTypes = new MimeTypes();
            if ($mediaFiles) {
                foreach ($mediaFiles as $mediaFile) {
                    if ($mediaFile) {
                        $originalFilename = pathinfo($mediaFile->getClientOriginalName(), PATHINFO_FILENAME);
                        $safeFilename = $this->slugger->slug($originalFilename);
                        $newFilename = $safeFilename . '-' . uniqid() . '.' . $mediaFile->guessExtension();

                        try {
                            $mediaFile->move($dir, $newFilename);

                            $fullPath = $dir . '/' . $newFilename;
                            if (!$filesystem->exists($fullPath)) {
                                throw new FileException('File move failed: File not found after move.');
                            }

                            $this->logger->info('Successfully uploaded file: ' . $fullPath);

                            $mediaUpload = new MediaUpload();
                            $mediaUpload->setUrl('uploads/comment_media/' . $newFilename);

                            $mimeType = $mimeTypes->guessMimeType($fullPath) ?? $mediaFile->getClientMimeType() ?? 'application/octet-stream';
                            $mediaUpload->setType($mimeType);
                            $this->logger->info('Detected MIME type: ' . $mimeType . ' for file: ' . $newFilename);

                            $mediaUpload->setUploadedBy($this->getUser());
                            $mediaUpload->setUploadedAt(new \DateTime());
                            $mediaUpload->setComment($comment);

                            $this->entityManager->persist($mediaUpload);
                            $comment->addMediaUpload($mediaUpload);
                        } catch (\Exception $e) {
                            $this->logger->error('File upload failed for comment: ' . $e->getMessage() . ' - File: ' . $mediaFile->getClientOriginalName());
                            $uploadErrors[] = $mediaFile->getClientOriginalName() . ': ' . $e->getMessage();
                        }
                    }
                }
            }

            $this->entityManager->persist($comment);
            $this->entityManager->flush();

            if (!empty($uploadErrors)) {
                $errorMsg = 'Comment posted, but some media uploads failed: ' . implode(', ', $uploadErrors);
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(['success' => true, 'warning' => $errorMsg], 206);
                }
                $this->addFlash('warning', $errorMsg);
                return $this->redirectToRoute('app_forum_part', ['id' => $id]);
            }

            if ($request->isXmlHttpRequest()) {
                $media = [];
                foreach ($comment->getMediaUploads() as $m) {
                    $media[] = ['url' => $m->getUrl(), 'type' => $m->getType()];
                }
                return new JsonResponse([
                    'success' => true,
                    'comment' => [
                        'id' => $comment->getId(),
                        'content' => $comment->getContent(),
                        'username' => $comment->getUser()->getUsername(),
                        'role' => $comment->getUser()->getRole(),
                        'postedAt' => $comment->getPostedAt()->format('Y-m-d H:i'),
                        'parentId' => $parentId,
                        'partId' => $comment->getPart() ? $comment->getPart()->getId() : null,
                        'avatar' => $comment->getUser()->getAvatar() ? '/uploads/avatars/' . $comment->getUser()->getAvatar() : 'https://placehold.co/48x48.png',
                        'media' => $media,
                        'userId' => $comment->getUser()->getId(),
                        'edit_token' => $this->csrfTokenManager->getToken('comment_edit_' . $comment->getId())->getValue(),
                        'delete_token' => $this->csrfTokenManager->getToken('comment_delete_' . $comment->getId())->getValue(),
                    ]
                ]);
            }

            $this->addFlash('success', 'Comment posted successfully!');
            return $this->redirectToRoute('app_forum_part', ['id' => $id]);
        }

        $comments = $this->entityManager->getRepository(Comment::class)->findBy(
            ['part' => $part, 'parent' => null],
            ['postedAt' => 'DESC']
        );

        return $this->render('forum/part.html.twig', [
            'part' => $part,
            'comments' => $comments,
        ]);
    }

    #[Route('/comment/{id}/edit', name: 'app_forum_edit_comment', methods: ['POST'])]
    public function editComment(int $id, Request $request): JsonResponse
    {
        $comment = $this->entityManager->getRepository(Comment::class)->find($id);
        if (!$comment) {
            return new JsonResponse(['error' => 'Comment not found'], 404);
        }

        if ($comment->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('comment_edit_' . $id, $token)) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        $comment->setContent($request->request->get('content'));
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'content' => $comment->getContent()]);
    }

    #[Route('/comment/{id}/delete', name: 'app_forum_delete_comment', methods: ['POST'])]
    public function deleteComment(int $id, Request $request): JsonResponse
    {
        $comment = $this->entityManager->getRepository(Comment::class)->find($id);
        if (!$comment) {
            return new JsonResponse(['error' => 'Comment not found'], 404);
        }

        if ($comment->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('comment_delete_' . $id, $token)) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        $this->entityManager->remove($comment);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/comment/{id}/like', name: 'app_forum_like', methods: ['POST'])]
    public function likeComment(int $id, Request $request): JsonResponse
    {
        $comment = $this->entityManager->getRepository(Comment::class)->find($id);
        if (!$comment) {
            return new JsonResponse(['error' => 'Comment not found'], 404);
        }

        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Login required'], 401);
        }

        $like = $this->entityManager->getRepository(CommentLike::class)->findOneBy(['user' => $user, 'comment' => $comment]);
        if ($like) {
            $this->entityManager->remove($like);
            $liked = false;
        } else {
            $like = new CommentLike();
            $like->setUser($user);
            $like->setComment($comment);
            $like->setLikedAt(new \DateTime());
            $this->entityManager->persist($like);
            $liked = true;
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'liked' => $liked,
            'count' => $comment->getLikeCount(),
        ]);
    }
}