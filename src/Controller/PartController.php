<?php

namespace App\Controller;

use App\Entity\Part;
use App\Entity\User;
use App\Entity\Enrollment;
use App\Service\CourseProgressService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PartController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private CourseProgressService $progressService;

    public function __construct(EntityManagerInterface $entityManager, CourseProgressService $progressService)
    {
        $this->entityManager = $entityManager;
        $this->progressService = $progressService;
    }

    #[Route('/part/{id}', name: 'app_part_details')]
    public function show(int $id): Response
    {
        $part = $this->entityManager->getRepository(Part::class)->find($id);
        if (!$part) {
            throw $this->createNotFoundException('Part not found');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User not authenticated');
        }

        // Check if user has ROLE_ADMIN or ROLE_TEACHER, or is a ROLE_CLIENT enrolled in the course
        $hasAccess = false;
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_TEACHER')) {
            $hasAccess = true;
        } elseif ($this->isGranted('ROLE_CLIENT')) {
            $course = $part->getCourse();
            if ($course) {
                $enrollment = $this->entityManager->getRepository(Enrollment::class)->findOneBy([
                    'user' => $user,
                    'course' => $course,
                ]);
                if ($enrollment) {
                    $hasAccess = true;
                }
            }
        }

        if (!$hasAccess) {
            throw $this->createAccessDeniedException('You do not have permission to access this part');
        }

        if (!$this->progressService->isPartUnlocked($part, $user)) {
            throw $this->createAccessDeniedException('Part is locked');
        }

        return $this->redirectToRoute('app_course_details', ['id' => $part->getCourse()->getId()]);
    }
}