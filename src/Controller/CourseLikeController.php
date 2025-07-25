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

class CourseLikeController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/course/{id}/like', name: 'app_course_like', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function like(string $id, Request $request): JsonResponse
    {
        // Convert id to integer
        $courseId = (int) $id;
        if ($courseId <= 0) {
            return new JsonResponse(['error' => 'Invalid course ID'], 400);
        }

        // Find course
        $course = $this->entityManager->getRepository(Course::class)->find($courseId);
        if (!$course) {
            return new JsonResponse(['error' => 'Course not found'], 404);
        }

        // Use a default user ID from request or fallback to 1
        $userId = $request->request->get('user_id', 1);
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        // Toggle like
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
        ], 200, ['Content-Type' => 'application/json']);
    }
}