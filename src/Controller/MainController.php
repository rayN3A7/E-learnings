<?php
// src/Controller/MainController.php

namespace App\Controller;

use App\Entity\Course;
use App\Entity\CourseLike;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MainController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        $courses = $this->entityManager->getRepository(Course::class)->findBy([], [], 3);
        return $this->render('base.html.twig', [
            'courses' => $courses,
        ]);
    }

    #[Route('/about', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('about.html.twig');
    }

    #[Route('/courses', name: 'app_courses')]
    public function courses(): Response
    {
        $courses = $this->entityManager->getRepository(Course::class)->findAll();
        foreach ($courses as $course) {
            $course->getLikes()->initialize(); // Eager-load likes
        }
        return $this->render('courses.html.twig', [
            'courses' => $courses,
        ]);
    }

    #[Route('/course/{id}/like', name: 'app_course_like', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function likeCourse(int $id, Request $request): JsonResponse
    {
        // Find course
        $course = $this->entityManager->getRepository(Course::class)->find($id);
        if (!$course) {
            return new JsonResponse(['error' => 'Course not found'], 404);
        }

        // Use default user ID from request body
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