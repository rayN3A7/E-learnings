<?php

namespace App\Entity;

use App\Repository\CourseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CourseRepository::class)]
#[ORM\Table(name: 'course')]
class Course
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'datetime', name: 'createdAt')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'createdBy', referencedColumnName: 'id')]
    private ?User $createdBy = null;

    #[ORM\OneToMany(mappedBy: 'course', targetEntity: Part::class, cascade: ['persist', 'remove'])]
    private Collection $parts;

    #[ORM\OneToMany(mappedBy: 'course', targetEntity: Enrollment::class)]
    private Collection $enrollments;

    public function __construct()
    {
        $this->parts = new ArrayCollection();
        $this->enrollments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getParts(): Collection
    {
        return $this->parts;
    }

    public function addPart(Part $part): self
    {
        if (!$this->parts->contains($part)) {
            $this->parts[] = $part;
            $part->setCourse($this);
        }
        return $this;
    }

    public function removePart(Part $part): self
    {
        if ($this->parts->removeElement($part)) {
            if ($part->getCourse() === $this) {
                $part->setCourse(null);
            }
        }
        return $this;
    }

    public function getEnrollments(): Collection
    {
        return $this->enrollments;
    }

    public function addEnrollment(Enrollment $enrollment): self
    {
        if (!$this->enrollments->contains($enrollment)) {
            $this->enrollments[] = $enrollment;
            $enrollment->setCourse($this);
        }
        return $this;
    }

    public function removeEnrollment(Enrollment $enrollment): self
    {
        if ($this->enrollments->removeElement($enrollment)) {
            if ($enrollment->getCourse() === $this) {
                $enrollment->setCourse(null);
            }
        }
        return $this;
    }
}