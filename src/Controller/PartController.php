<?php

namespace App\Controller;

use App\Entity\Part;
use App\Entity\User;
use App\Entity\Quiz;
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

        $hasAccess = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_TEACHER') ||
                     ($this->isGranted('ROLE_CLIENT') && $this->progressService->isPartUnlocked($part, $user));

        if (!$hasAccess) {
            throw $this->createAccessDeniedException('You do not have permission to access this part');
        }

        $course = $part->getCourse();
        $partQuiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => $part]);

        return $this->render('part_details.html.twig', [
            'part' => $part,
            'course' => $course,
            'quiz' => $partQuiz,
            'geogebraMaterialId' => $part->getGeogebraMaterialId(),
            'tutorialContent' => $part->getTutorialContent(),
        ]);
    }
}