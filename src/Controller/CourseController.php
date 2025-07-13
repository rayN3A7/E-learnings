<?php

// src/Controller/CourseController.php
namespace App\Controller;

use App\Entity\Course;
use App\Entity\Enrollment;
use App\Entity\User;
use App\Form\CourseType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CourseController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/course/create', name: 'app_course_create')]
    public function create(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TEACHER');
        $course = new Course();
        $form = $this->createForm(CourseType::class, $course);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $course->setCreatedBy($this->getUser());
            $course->setCreatedAt(new \DateTime());
            $this->entityManager->persist($course);
            $this->entityManager->flush();
            return $this->redirectToRoute('app_course_details', ['id' => $course->getId()]);
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

        $this->entityManager->persist($enrollment);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_course_details', ['id' => $course->getId()]);
    }

    #[Route('/course/{id}', name: 'app_course_details')]
    public function details(int $id): Response
    {
        $course = $this->entityManager->getRepository(Course::class)->find($id);
        if (!$course) {
            throw $this->createNotFoundException('Course not found');
        }

        $progress_percentage = 0; // Replace with actual logic
        $is_enrolled = $this->getUser() && $course->getEnrollments()->exists(function($key, $enrollment) {
            return $enrollment->getUser() === $this->getUser();
        });
        $current_part = $course->getParts()->first() ?: null;
        $is_course_completed = false; // Replace with actual logic
        $final_quiz = null; // Replace with actual logic
        $current_quiz_attempt = null; // Replace with actual logic

        return $this->render('course_details.html.twig', [
            'course' => $course,
            'progress_percentage' => $progress_percentage,
            'is_enrolled' => $is_enrolled,
            'current_part' => $current_part,
            'is_course_completed' => $is_course_completed,
            'final_quiz' => $final_quiz,
            'current_quiz_attempt' => $current_quiz_attempt,
            'is_part_unlocked' => function($part) { return true; }, // Replace with actual logic
        ]);
    }
}