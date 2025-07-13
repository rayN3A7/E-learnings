<?php

namespace App\Service;

use App\Entity\Course;
use App\Entity\Part;
use App\Entity\Progress;
use App\Entity\QuizAttempt;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class CourseProgressService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function calculateProgress(User $user, Course $course): float
    {
        $parts = $course->getParts()->toArray();
        if (empty($parts)) {
            return 0.0;
        }

        $completedParts = $this->entityManager->getRepository(Progress::class)->count([
            'user' => $user,
            'part' => $parts,
            'completed' => true,
        ]);

        return ($completedParts / count($parts)) * 100;
    }

    public function isCourseCompleted(User $user, Course $course): bool
    {
        $parts = $course->getParts()->toArray();
        if (empty($parts)) {
            return false;
        }

        $completedParts = $this->entityManager->getRepository(Progress::class)->count([
            'user' => $user,
            'part' => $parts,
            'completed' => true,
        ]);

        return $completedParts === count($parts);
    }

    public function isPartUnlocked(Part $part, User $user): bool
    {
        if ($part->getOrder() === 1) {
            return true;
        }

        $previousPart = $this->entityManager->getRepository(Part::class)->findOneBy([
            'course' => $part->getCourse(),
            'order' => $part->getOrder() - 1,
        ]);

        if (!$previousPart || !$previousPart->getQuiz()) {
            return true;
        }

        $attempt = $this->entityManager->getRepository(QuizAttempt::class)->findOneBy([
            'user' => $user,
            'quiz' => $previousPart->getQuiz(),
        ], ['takenAt' => 'DESC']);

        return $attempt && $attempt->getScore() >= 70;
    }

    public function getCurrentPart(User $user, Course $course): ?Part
    {
        $parts = $course->getParts()->toArray();
        usort($parts, fn($a, $b) => $a->getOrder() <=> $b->getOrder());

        foreach ($parts as $part) {
            if ($this->isPartUnlocked($part, $user)) {
                $progress = $this->entityManager->getRepository(Progress::class)->findOneBy([
                    'user' => $user,
                    'part' => $part,
                ]);
                if (!$progress || !$progress->isCompleted()) {
                    return $part;
                }
            }
        }

        return end($parts) ?: null;
    }

    public function markPartCompleted(User $user, Part $part): void
    {
        $progress = $this->entityManager->getRepository(Progress::class)->findOneBy([
            'user' => $user,
            'part' => $part,
        ]);

        if (!$progress) {
            $progress = new Progress();
            $progress->setUser($user);
            $progress->setPart($part);
            $this->entityManager->persist($progress);
        }

        $progress->setCompleted(true);
        $this->entityManager->flush();
    }
}