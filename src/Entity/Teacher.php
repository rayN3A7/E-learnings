<?php

namespace App\Entity;

use App\Repository\TeacherRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TeacherRepository::class)]
#[ORM\Table(name: 'teacher')]
class Teacher
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id', referencedColumnName: 'id')]
    private User $user;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): self
    {
        $this->bio = $bio;
        return $this;
    }
}