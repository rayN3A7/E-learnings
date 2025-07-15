<?php

namespace App\Service;

use App\Entity\Course;
use App\Entity\Part;
use App\Entity\Progress;
use App\Entity\QuizAttempt;
use App\Entity\User;
use App\Entity\Enrollment;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Quiz;


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
        if (!$part->getCourse()) {
            return false;
        }

        // Check if user is enrolled
        $enrollment = $this->entityManager->getRepository(Enrollment::class)->findOneBy([
            'user' => $user,
            'course' => $part->getCourse(),
        ]);

        if (!$enrollment) {
            return false;
        }

        // First part is always unlocked for enrolled users
        if ($part->getPartOrder() === 1) {
            return true;
        }

        $previousPart = $this->entityManager->getRepository(Part::class)->findOneBy([
            'course' => $part->getCourse(),
            'partOrder' => $part->getPartOrder() - 1,
        ]);

        if (!$previousPart) {
            return true;
        }

        $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => $previousPart]);
        if (!$quiz) {
            return true;
        }

        $attempt = $this->entityManager->getRepository(QuizAttempt::class)->findOneBy([
            'user' => $user,
            'quiz' => $quiz,
        ], ['takenAt' => 'DESC']);

        return $attempt && $attempt->getScore() >= 70;
    }

    public function getCurrentPart(User $user, Course $course): ?Part
    {
        $parts = $course->getParts()->toArray();
        usort($parts, fn($a, $b) => $a->getPartOrder() <=> $b->getPartOrder());

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
        if (!$part->getCourse()) {
            return;
        }

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
        $progress->setCompletedAt(new \DateTime());
        $this->entityManager->flush();
    }
}