<?php

namespace App\Controller;

use App\Entity\UserInteraction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class UserInteractionController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/interaction/save', name: 'app_interaction_save', methods: ['POST'])]
    public function saveInteraction(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['status' => 'error', 'message' => 'User not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['partId']) || !isset($data['interactionData'])) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid data'], 400);
        }

        $part = $this->entityManager->getRepository(\App\Entity\Part::class)->find($data['partId']);
        if (!$part) {
            return new JsonResponse(['status' => 'error', 'message' => 'Part not found'], 404);
        }

        $interaction = new UserInteraction();
        $interaction->setUser($user);
        $interaction->setPart($part);
        $interaction->setTool('geogebra');
        $interaction->setData($data['interactionData']);
        $interaction->setCreatedAt(new \DateTime());

        try {
            $this->entityManager->persist($interaction);
            $this->entityManager->flush();
            return new JsonResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return new JsonResponse(['status' => 'error', 'message' => 'Failed to save interaction'], 500);
        }
    }

    #[Route('/admin/interactions/{courseId}', name: 'app_interaction_report', methods: ['GET'])]
    public function interactionReport(int $courseId): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TEACHER');

        $course = $this->entityManager->getRepository(\App\Entity\Course::class)->find($courseId);
        if (!$course) {
            throw $this->createNotFoundException('Course not found');
        }

        $interactions = $this->entityManager->getRepository(UserInteraction::class)
            ->createQueryBuilder('i')
            ->join('i.part', 'p')
            ->where('p.course = :course')
            ->setParameter('course', $course)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/interaction_report.html.twig', [
            'course' => $course,
            'interactions' => $interactions,
        ]);
    }
}