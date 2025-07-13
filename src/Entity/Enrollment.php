<?php

namespace App\Entity;

use App\Repository\EnrollmentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EnrollmentRepository::class)]
#[ORM\Table(name: 'enrollment')]
class Enrollment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime', name: 'enrolledAt')] // Explicitly set column name
    private ?\DateTimeInterface $enrolledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'enrollments')]
    #[ORM\JoinColumn(name: 'userId', nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Course::class, inversedBy: 'enrollments')]
    #[ORM\JoinColumn(name: 'courseId', nullable: false)]
    private ?Course $course = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnrolledAt(): ?\DateTimeInterface
    {
        return $this->enrolledAt;
    }

    public function setEnrolledAt(\DateTimeInterface $enrolledAt): self
    {
        $this->enrolledAt = $enrolledAt;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getCourse(): ?Course
    {
        return $this->course;
    }

    public function setCourse(?Course $course): self
    {
        $this->course = $course;
        return $this;
    }
}