<?php

namespace App\Entity;

use App\Enum\QuestionType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'quiz')]
class Quiz
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $title = null;

    
    #[ORM\Column(type: 'boolean', name: 'generatedByAI')] // Explicitly map to the database column name
    private ?bool $generatedByAI = null;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'createdAt')] // Explicitly map to the database column name
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'float', nullable: true, name: 'scoreWeight')]
    private ?float $scoreWeight = null;

    #[ORM\OneToOne(targetEntity: Part::class)]
    #[ORM\JoinColumn(name: 'partId', referencedColumnName: 'id', unique: true)]
    private ?Part $part = null;

    #[ORM\OneToMany(targetEntity: Question::class, mappedBy: 'quiz', cascade: ['persist', 'remove'])]
    private Collection $questions;

    #[ORM\OneToMany(targetEntity: QuizAttempt::class, mappedBy: 'quiz')]
    private Collection $quizAttempts;

    public function __construct()
    {
        $this->questions = new ArrayCollection();
        $this->quizAttempts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function isGeneratedByAI(): ?bool
    {
        return $this->generatedByAI;
    }

    public function setGeneratedByAI(?bool $generatedByAI): self
    {
        $this->generatedByAI = $generatedByAI;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getScoreWeight(): ?float
    {
        return $this->scoreWeight;
    }

    public function setScoreWeight(?float $scoreWeight): self
    {
        $this->scoreWeight = $scoreWeight;
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

    /**
     * @return Collection|Question[]
     */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(Question $question): self
    {
        if (!$this->questions->contains($question)) {
            $this->questions[] = $question;
            $question->setQuiz($this);
        }
        return $this;
    }

    public function removeQuestion(Question $question): self
    {
        if ($this->questions->removeElement($question)) {
            if ($question->getQuiz() === $this) {
                $question->setQuiz(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection|QuizAttempt[]
     */
    public function getQuizAttempts(): Collection
    {
        return $this->quizAttempts;
    }

    public function addQuizAttempt(QuizAttempt $quizAttempt): self
    {
        if (!$this->quizAttempts->contains($quizAttempt)) {
            $this->quizAttempts[] = $quizAttempt;
            $quizAttempt->setQuiz($this);
        }
        return $this;
    }

    public function removeQuizAttempt(QuizAttempt $quizAttempt): self
    {
        if ($this->quizAttempts->removeElement($quizAttempt)) {
            if ($quizAttempt->getQuiz() === $this) {
                $quizAttempt->setQuiz(null);
            }
        }
        return $this;
    }
}