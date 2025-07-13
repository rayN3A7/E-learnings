<?php

namespace App\Entity;

use App\Repository\ProgressRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProgressRepository::class)]
#[ORM\Table(name: 'progress')]
class Progress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'userId', referencedColumnName: 'id')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Part::class)]
    #[ORM\JoinColumn(name: 'partId', referencedColumnName: 'id')]
    private ?Part $part = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $completed = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getPart(): ?Part
    {
        return $this->part;
    }

    public function setPart(?Part $part): self
    {
        $this->part = $part;
        return $this;
    }

    public function isCompleted(): ?bool
    {
        return $this->completed;
    }

    public function setCompleted(?bool $completed): self
    {
        $this->completed = $completed;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }
}