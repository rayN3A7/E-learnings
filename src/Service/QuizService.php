<?php

namespace App\Service;

use App\Entity\Part;
use App\Entity\Course;
use App\Entity\Question;
use App\Entity\Quiz;
use App\Entity\User;
use App\Enum\QuestionType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class QuizService
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private string $pythonBinary;
    private string $quizGeneratorScript;
    private string $finalQuizGeneratorScript;

    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        string $pythonBinary,
        string $quizGeneratorScript,
        string $finalQuizGeneratorScript
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->pythonBinary = $pythonBinary;
        $this->quizGeneratorScript = $quizGeneratorScript;
        $this->finalQuizGeneratorScript = $finalQuizGeneratorScript;
    }

    public function evaluateQuiz(Quiz $quiz, array $answers): float
    {
        $correctAnswers = 0;
        $totalQuestions = count($quiz->getQuestions());

        foreach ($quiz->getQuestions() as $question) {
            $userAnswer = $answers[$question->getId()] ?? null;
            if (!$userAnswer) {
                continue;
            }

            if ($question->getType() === QuestionType::MCQ->value) {
                if ($userAnswer === $question->getCorrectAnswer()) {
                    $correctAnswers++;
                }
            } else {
                $correctValue = (float) $question->getCorrectAnswer();
                $userValue = (float) $userAnswer;
                if (abs($correctValue - $userValue) <= 0.01) {
                    $correctAnswers++;
                }
            }
        }

        return $totalQuestions > 0 ? ($correctAnswers / $totalQuestions) * 100 : 0;
    }

    public function getOrGeneratePartQuiz(?User $user, ?Part $part): ?Quiz
    {
        if (!$part || !$part->getCourse()) {
            $this->logger->warning('Invalid part or course for quiz generation: Part ID ' . ($part ? $part->getId() : 'NULL'));
            return null;
        }

        $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => $part]);
        if ($quiz) {
            return $quiz;
        }

        $content = $part->getTitle() . "\n";
        if ($part->getDescription()) {
            $content .= $part->getDescription() . "\n";
        }
        if ($part->getVideo() && $part->getVideo()->getDescription()) {
            $content .= $part->getVideo()->getDescription() . "\n";
        }
        if ($part->getWrittenSection() && $part->getWrittenSection()->getContent()) {
            $content .= $part->getWrittenSection()->getContent() . "\n";
        }

        $inputData = [
            'course_title' => $part->getCourse()->getTitle(),
            'part_title' => $part->getTitle(),
            'content' => $content,
            'part_id' => $part->getId()
        ];
        $process = new Process([$this->pythonBinary, $this->quizGeneratorScript]);
        $process->setInput(json_encode($inputData));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('Quiz generation failed: ' . $process->getErrorOutput());
        }

        $questionsData = json_decode($process->getOutput(), true);
        if (!isset($questionsData['questions']) || !is_array($questionsData['questions'])) {
            throw new \Exception('Invalid quiz data format from script');
        }

        $quiz = new Quiz();
        $quiz->setPart($part);
        $quiz->setTitle('Quiz for Part: ' . $part->getTitle() . ' (Course: ' . $part->getCourse()->getTitle() . ')');
        $quiz->setGeneratedByAI(true);
        $quiz->setCreatedAt(new \DateTime());
        $quiz->setScoreWeight(1.0);

        foreach ($questionsData['questions'] as $qData) {
            $question = new Question();
            $question->setQuiz($quiz);
            $question->setType($qData['type'] === 'MCQ' ? QuestionType::MCQ->value : QuestionType::Numeric->value);
            $question->setText($qData['text']);
            if ($qData['type'] === 'MCQ') {
                $question->setOptions($qData['options'] ?? []);
            }
            $question->setCorrectAnswer($qData['correctAnswer']);
            $question->setGeneratedByAI(true);

            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        $this->entityManager->persist($quiz);
        $this->entityManager->flush();

        return $quiz;
    }

    public function getOrGenerateFinalQuiz(?User $user, Course $course): ?Quiz
    {
        if (!$user || !$course) {
            $this->logger->warning('Invalid user or course for final quiz generation');
            return null;
        }

        $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => null, 'title' => 'Final Quiz for Course: ' . $course->getTitle()]);
        if ($quiz) {
            return $quiz;
        }

        $inputData = [
            'course_id' => $course->getId(),
            'course_title' => $course->getTitle(),
            'user_id' => $user->getId()
        ];
        $process = new Process([$this->pythonBinary, $this->finalQuizGeneratorScript]);
        $process->setInput(json_encode($inputData));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('Final quiz generation failed: ' . $process->getErrorOutput());
        }

        $quizData = json_decode($process->getOutput(), true);
        if (!isset($quizData['quiz_id']) || !isset($quizData['questions']) || !is_array($quizData['questions'])) {
            throw new \Exception('Invalid final quiz data format from script');
        }

        $quiz = $this->entityManager->getRepository(Quiz::class)->find($quizData['quiz_id']);
        if (!$quiz) {
            throw new \Exception('Final quiz not found in database');
        }

        return $quiz;
    }

    private function createFallbackQuiz(Part $part): ?Quiz
    {
        $quiz = new Quiz();
        $quiz->setPart($part);
        $quiz->setTitle('Fallback Quiz for Part: ' . $part->getTitle() . ' (Course: ' . $part->getCourse()->getTitle() . ')');
        $quiz->setGeneratedByAI(false);
        $quiz->setCreatedAt(new \DateTime());
        $quiz->setScoreWeight(1.0);

        $question1 = new Question();
        $question1->setQuiz($quiz);
        $question1->setType(QuestionType::MCQ->value);
        $question1->setText('What is the main goal of interpolation in numerical analysis?');
        $question1->setOptions([
            'To approximate functions between known points',
            'To solve differential equations',
            'To optimize functions',
            'To find eigenvalues',
        ]);
        $question1->setCorrectAnswer('To approximate functions between known points');
        $question1->setGeneratedByAI(false);

        $question2 = new Question();
        $question2->setQuiz($quiz);
        $question2->setType(QuestionType::Numeric->value);
        $question2->setText('Using linear interpolation between points (1, 2) and (3, 4), what is the value at x = 2?');
        $question2->setCorrectAnswer('3');
        $question2->setGeneratedByAI(false);

        $quiz->addQuestion($question1);
        $quiz->addQuestion($question2);

        $this->entityManager->persist($quiz);
        $this->entityManager->persist($question1);
        $this->entityManager->persist($question2);
        $this->entityManager->flush();

        return $quiz;
    }

    private function createFallbackFinalQuiz(Course $course): ?Quiz
    {
        $quiz = new Quiz();
        $quiz->setTitle('Fallback Final Quiz for Course: ' . $course->getTitle());
        $quiz->setGeneratedByAI(false);
        $quiz->setCreatedAt(new \DateTime());
        $quiz->setScoreWeight(1.0);

        $question1 = new Question();
        $question1->setQuiz($quiz);
        $question1->setType(QuestionType::MCQ->value);
        $question1->setText('What is the primary purpose of numerical analysis?');
        $question1->setOptions([
            'To approximate mathematical solutions',
            'To write software code',
            'To design hardware',
            'To analyze data structures',
        ]);
        $question1->setCorrectAnswer('To approximate mathematical solutions');
        $question1->setGeneratedByAI(false);

        $question2 = new Question();
        $question2->setQuiz($quiz);
        $question2->setType(QuestionType::Numeric->value);
        $question2->setText('What is the result of linear interpolation between points (0, 0) and (2, 4) at x = 1?');
        $question2->setCorrectAnswer('2');
        $question2->setGeneratedByAI(false);

        $quiz->addQuestion($question1);
        $quiz->addQuestion($question2);

        $this->entityManager->persist($quiz);
        $this->entityManager->persist($question1);
        $this->entityManager->persist($question2);
        $this->entityManager->flush();

        return $quiz;
    }
}