<?php

namespace App\Twig;

use App\Entity\Course;
use App\Entity\Quiz;
use App\Entity\Part;
use App\Entity\User;
use App\Repository\QuizAttemptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Psr\Log\LoggerInterface;

class AppExtension extends AbstractExtension
{
    private $tokenStorage;
    private $entityManager;
    private $quizAttemptRepository;
    private $logger;

    public function __construct(
        TokenStorageInterface $tokenStorage,
        EntityManagerInterface $entityManager,
        QuizAttemptRepository $quizAttemptRepository,
        LoggerInterface $logger
    ) {
        $this->tokenStorage = $tokenStorage;
        $this->entityManager = $entityManager;
        $this->quizAttemptRepository = $quizAttemptRepository;
        $this->logger = $logger;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_part_unlocked', [$this, 'isPartUnlocked']),
        ];
    }

    public function isPartUnlocked(Part $part): bool
    {
        $token = $this->tokenStorage->getToken();
        $user = $token ? $token->getUser() : null;
        if (!$user instanceof User) {
            $this->logger->warning('No authenticated user found for isPartUnlocked check');
            return false;
        }

        if (!$part->getCourse()) {
            $this->logger->warning('Part has no associated course', ['part_id' => $part->getId()]);
            return false;
        }

        if ($part->getPartOrder() === 1) {
            return true;
        }

        $previousPart = $this->entityManager->getRepository(Part::class)->findOneBy([
            'course' => $part->getCourse(),
            'partOrder' => $part->getPartOrder() - 1,
        ]);

        if (!$previousPart) {
            $this->logger->info('No previous part found, unlocking part', ['part_id' => $part->getId()]);
            return true;
        }

        $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => $previousPart]);
        if (!$quiz) {
            $this->logger->info('No quiz found for previous part, unlocking part', ['part_id' => $part->getId()]);
            return true;
        }

        $attempt = $this->quizAttemptRepository->findOneBy([
            'user' => $user,
            'quiz' => $quiz,
        ], ['takenAt' => 'DESC']);

        if ($attempt && $attempt->getScore() >= 70) {
            return true;
        }

        $this->logger->info('Part locked due to insufficient quiz score or no attempt', [
            'part_id' => $part->getId(),
            'user_id' => $user->getId(),
        ]);
        return false;
    }
}