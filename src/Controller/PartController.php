<?php

namespace App\Controller;

use App\Entity\Part;
use App\Entity\User;
use App\Service\CourseProgressService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
    #[IsGranted('ROLE_CLIENT')]
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

        if (!$this->progressService->isPartUnlocked($part, $user)) {
            throw $this->createAccessDeniedException('Part is locked');
        }

        return $this->redirectToRoute('app_course_details', ['id' => $part->getCourse()->getId()]);
    }
}