<?php
// src/Controller/CourseLikeController.php

namespace App\Controller;

use App\Entity\Course;
use App\Entity\CourseLike;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

class CourseLikeController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $logger)
    {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    #[Route('/course/{id}/like', name: 'app_course_like', methods: ['POST'])]
    public function like(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'User not authenticated'], 401);
        }

        $course = $this->entityManager->getRepository(Course::class)->find($id);
        if (!$course) {
            return new JsonResponse(['error' => 'Course not found'], 404);
        }

        try {
            $existingLike = $this->entityManager->getRepository(CourseLike::class)->findOneBy([
                'user' => $user,
                'course' => $course,
            ]);

            if ($existingLike) {
                $this->entityManager->remove($existingLike);
                $action = 'unliked';
            } else {
                $like = new CourseLike();
                $like->setUser($user);
                $like->setCourse($course);
                $like->setLikedAt(new \DateTime());
                $this->entityManager->persist($like);
                $action = 'liked';
            }

            $this->entityManager->flush();
            
            return new JsonResponse([
                'success' => true,
                'action' => $action,
                'likeCount' => $course->getLikeCount(),
                'isLiked' => $action === 'liked'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to process like for course ID ' . $id . ': ' . $e->getMessage());
            return new JsonResponse(['error' => 'Failed to process like'], 500);
        }
    }
}