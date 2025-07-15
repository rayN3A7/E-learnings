<?php

namespace App\Controller;

use App\Entity\Course;
use App\Entity\CourseLike;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

    #[Route('/course/{id}/like', name: 'app_course_like', methods: ['POST'])]
    public function likeCourse(int $id, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            $this->addFlash('error', 'You must be logged in to like a course.');
            return $this->redirectToRoute('app_courses');
        }

        $course = $this->entityManager->getRepository(Course::class)->find($id);
        if (!$course) {
            $this->addFlash('error', 'Course not found.');
            return $this->redirectToRoute('app_courses');
        }

        $existingLike = $this->entityManager->getRepository(CourseLike::class)->findOneBy([
            'user' => $user,
            'course' => $course,
        ]);

        if ($existingLike) {
            $this->entityManager->remove($existingLike);
            $this->addFlash('success', 'Course unliked.');
        } else {
            $like = new CourseLike();
            $like->setUser($user);
            $like->setCourse($course);
            $like->setLikedAt(new \DateTime());
            $this->entityManager->persist($like);
            $this->addFlash('success', 'Course liked!');
        }

        $this->entityManager->flush();
        return $this->redirectToRoute('app_courses');
    }
}