<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'question')]
class Question
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Quiz::class, inversedBy: 'questions')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Quiz $quiz = null;

    #[ORM\Column(type: 'text')]
    private ?string $text = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $options = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $correctAnswer = null;

    #[ORM\Column(type: 'boolean')]
    private bool $generatedByAI = false;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getOptions(): ?array
    {
        return $this->options;
    }

    public function setOptions(?array $options): self
    {
        $this->options = $options;
        return $this;
    }

    public function getCorrectAnswer(): ?string
    {
        return $this->correctAnswer;
    }

    public function setCorrectAnswer(?string $correctAnswer): self
    {
        $this->correctAnswer = $correctAnswer;
        return $this;
    }

    public function isGeneratedByAI(): bool
    {
        return $this->generatedByAI;
    }

    public function setGeneratedByAI(bool $generatedByAI): self
    {
        $this->generatedByAI = $generatedByAI;
        return $this;
    }
}