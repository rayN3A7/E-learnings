<?php

namespace App\Controller;

use App\Entity\Course;
use App\Entity\Enrollment;
use App\Entity\User;
use App\Service\CourseProgressService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class UserDashboardController extends AbstractController
{
    #[Route('/my-courses', name: 'app_my_courses')]
    public function myCourses(EntityManagerInterface $entityManager, CourseProgressService $progressService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $enrollments = $entityManager->getRepository(Enrollment::class)->findBy(['user' => $user]);
        $courses = array_filter(array_map(fn($enrollment) => $enrollment->getCourse(), $enrollments), fn($course) => $course !== null);
        $progresses = [];
        foreach ($courses as $course) {
            $progresses[$course->getId()] = $progressService->calculateProgress($user, $course);
        }

        return $this->render('my_courses.html.twig', [
            'courses' => $courses,
            'progresses' => $progresses,
        ]);
    }
}