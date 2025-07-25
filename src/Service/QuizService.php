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

        // Explicitly check for existing quiz with part ID
        $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => $part]);
        if ($quiz) {
            $this->logger->info('Reusing existing quiz for part ID ' . $part->getId() . ', Quiz ID: ' . $quiz->getId());
            return $quiz;
        } else {
            $this->logger->debug('No existing quiz found for part ID ' . $part->getId() . ', proceeding to generate new quiz');
        }

        $content = $part->getTitle() . "\n";
        if ($part->getDescription()) {
            $content .= $part->getDescription() . "\n";
        }
        if ($part->getVideo() && $part->getVideo()->getDescription()) {
            $content .= $part->getVideo()->getDescription() . "\n";
        }

        $inputData = [
            'course_title' => $part->getCourse()->getTitle(),
            'part_title' => $part->getTitle(),
            'content' => $content,
            'part_id' => $part->getId(),
            'num_questions' => 10
        ];
        $process = new Process([$this->pythonBinary, $this->quizGeneratorScript]);
        $process->setInput(json_encode($inputData));
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error('Quiz generation failed: ' . $process->getErrorOutput());
            return $this->createFallbackQuiz($part);
        }

        $questionsData = json_decode($process->getOutput(), true);
        if (!isset($questionsData['questions']) || !is_array($questionsData['questions']) || count($questionsData['questions']) != 10) {
            $this->logger->error('Invalid or insufficient quiz data format from script: Expected 10 questions, got ' . (count($questionsData['questions'] ?? [])));
            return $this->createFallbackQuiz($part);
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
            $question->setType(strtolower($qData['type']) === 'mcq' ? QuestionType::MCQ->value : QuestionType::Numeric->value);
            $question->setText($qData['text']);
            if (strtolower($qData['type']) === 'mcq') {
                $question->setOptions($qData['options'] ?? ['Option 1', 'Option 2', 'Option 3', 'Option 4']);
            }
            $question->setCorrectAnswer($qData['correctAnswer'] ?? 'Default answer');
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
            'user_id' => $user->getId(),
            'num_questions' => 10
        ];
        $process = new Process([$this->pythonBinary, $this->finalQuizGeneratorScript]);
        $process->setInput(json_encode($inputData));
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error('Final quiz generation failed: ' . $process->getErrorOutput());
            return $this->createFallbackFinalQuiz($course);
        }

        $quizData = json_decode($process->getOutput(), true);
        if (!isset($quizData['quiz_id']) || !isset($quizData['questions']) || !is_array($quizData['questions']) || count($quizData['questions']) != 10) {
            $this->logger->error('Invalid or insufficient final quiz data format from script: Expected 10 questions, got ' . (count($quizData['questions'] ?? [])));
            return $this->createFallbackFinalQuiz($course);
        }

        $quiz = $this->entityManager->getRepository(Quiz::class)->find($quizData['quiz_id']);
        if (!$quiz) {
            $this->logger->error('Final quiz not found in database with ID: ' . $quizData['quiz_id']);
            return $this->createFallbackFinalQuiz($course);
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

        for ($i = 0; $i < 5; $i++) {
            $question = new Question();
            $question->setQuiz($quiz);
            $question->setType(QuestionType::MCQ->value);
            $question->setText("Fallback MCQ Question " . ($i + 1) . " for " . $part->getTitle());
            $question->setOptions(['Option A', 'Option B', 'Option C', 'Option D']);
            $question->setCorrectAnswer('Option A');
            $question->setGeneratedByAI(false);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        for ($i = 0; $i < 5; $i++) {
            $question = new Question();
            $question->setQuiz($quiz);
            $question->setType(QuestionType::Numeric->value);
            $question->setText("Fallback Numeric Question " . ($i + 1) . " for " . $part->getTitle());
            $question->setCorrectAnswer((string) ($i + 1));
            $question->setGeneratedByAI(false);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        $this->entityManager->persist($quiz);
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

        for ($i = 0; $i < 5; $i++) {
            $question = new Question();
            $question->setQuiz($quiz);
            $question->setType(QuestionType::MCQ->value);
            $question->setText("Fallback Final MCQ Question " . ($i + 1) . " for " . $course->getTitle());
            $question->setOptions(['Option A', 'Option B', 'Option C', 'Option D']);
            $question->setCorrectAnswer('Option A');
            $question->setGeneratedByAI(false);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        for ($i = 0; $i < 5; $i++) {
            $question = new Question();
            $question->setQuiz($quiz);
            $question->setType(QuestionType::Numeric->value);
            $question->setText("Fallback Final Numeric Question " . ($i + 1) . " for " . $course->getTitle());
            $question->setCorrectAnswer((string) ($i + 1));
            $question->setGeneratedByAI(false);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        $this->entityManager->persist($quiz);
        $this->entityManager->flush();

        return $quiz;
    }
}