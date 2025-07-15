<?php

   namespace App\Entity;

   use Doctrine\ORM\Mapping as ORM;

   #[ORM\Entity]
   #[ORM\Table(name: 'course_like')]
   class CourseLike
   {
       #[ORM\Id]
       #[ORM\GeneratedValue]
       #[ORM\Column(type: 'integer')]
       private ?int $id = null;

       #[ORM\ManyToOne(targetEntity: User::class)]
       #[ORM\JoinColumn(name: 'userId', referencedColumnName: 'id', onDelete: 'SET NULL')]
       private ?User $user = null;

       #[ORM\ManyToOne(targetEntity: Course::class)]
       #[ORM\JoinColumn(name: 'courseId', referencedColumnName: 'id', onDelete: 'SET NULL')]
       private ?Course $course = null;

       #[ORM\Column(type: 'datetime', name: 'likedAt', nullable: true)]
       private ?\DateTimeInterface $likedAt = null;

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

       public function getCourse(): ?Course
       {
           return $this->course;
       }

       public function setCourse(?Course $course): self
       {
           $this->course = $course;
           return $this;
       }

       public function getLikedAt(): ?\DateTimeInterface
       {
           return $this->likedAt;
       }

       public function setLikedAt(?\DateTimeInterface $likedAt): self
       {
           $this->likedAt = $likedAt;
           return $this;
       }
   }
