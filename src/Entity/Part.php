<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'part')]
class Part
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $duration = null;

    #[ORM\Column(type: 'integer', name: 'partOrder', nullable: true)]
    private ?int $partOrder = null;

    #[ORM\ManyToOne(targetEntity: Course::class, inversedBy: 'parts')]
    #[ORM\JoinColumn(name: 'courseId', referencedColumnName: 'id')]
    private ?Course $course = null;

    #[ORM\OneToOne(targetEntity: Video::class, mappedBy: 'part', cascade: ['persist', 'remove'])]
    private ?Video $video = null;

    #[ORM\OneToOne(targetEntity: WrittenSection::class, mappedBy: 'part', cascade: ['persist', 'remove'])]
    private ?WrittenSection $writtenSection = null;

    #[ORM\OneToOne(targetEntity: Quiz::class, mappedBy: 'part', cascade: ['persist', 'remove'])]
    private ?Quiz $quiz = null;

    public function __construct()
    {
        // Removed $this->questions = new ArrayCollection();
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

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(?int $duration): self
    {
        $this->duration = $duration;
        return $this;
    }

    public function getPartOrder(): ?int
    {
        return $this->partOrder;
    }

    public function setPartOrder(?int $partOrder): self
    {
        $this->partOrder = $partOrder;
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

    public function getVideo(): ?Video
    {
        return $this->video;
    }

    public function setVideo(?Video $video): self
    {
        $this->video = $video;
        return $this;
    }

    public function getWrittenSection(): ?WrittenSection
    {
        return $this->writtenSection;
    }

    public function setWrittenSection(?WrittenSection $writtenSection): self
    {
        $this->writtenSection = $writtenSection;
        return $this;
    }

    public function getQuiz(): ?Quiz
    {
        return $this->quiz;
    }

    public function setQuiz(?Quiz $quiz): self
    {
        $this->quiz = $quiz;
        return $this;
    }
}