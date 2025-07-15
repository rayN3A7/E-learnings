<?php

namespace App\Service;

use App\Entity\Part;
use App\Entity\Question;
use App\Entity\Quiz;
use App\Entity\User;
use App\Enum\QuestionType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class QuizService
{
    private EntityManagerInterface $entityManager;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private string $geminiApiKey;

    public function __construct(EntityManagerInterface $entityManager, HttpClientInterface $httpClient, LoggerInterface $logger, string $geminiApiKey)
    {
        $this->entityManager = $entityManager;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->geminiApiKey = $geminiApiKey;
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

    public function getOrGenerateFinalQuiz(?User $user, ?Part $part): ?Quiz
    {
        if (!$part || !$part->getCourse()) {
            $this->logger->warning('Invalid part or course for quiz generation: Part ID ' . ($part ? $part->getId() : 'NULL'));
            return null;
        }

        try {
            $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => $part]);
            if ($quiz) {
                return $quiz;
            }

            // Collect part content for quiz generation
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

            // Prepare prompt for Gemini API
            $prompt = <<<EOD
Generate a quiz with 2 questions based on the following part content for course "{$part->getCourse()->getTitle()}". Return the response in JSON format with the structure:
{
  "questions": [
    {
      "type": "MCQ",
      "text": "Question text",
      "options": ["Option 1", "Option 2", "Option 3", "Option 4"],
      "correct_answer": "Correct option"
    },
    {
      "type": "Numeric",
      "text": "Question text",
      "correct_answer": "Numeric value"
    }
  ]
}
Content:
$content
EOD;

            // Call Gemini API
            $response = $this->httpClient->request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent', [
                'headers' => [
                    'x-goog-api-key' => $this->geminiApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'temperature' => 0.7,
                        'maxOutputTokens' => 500,
                    ],
                ],
            ]);

            $data = $response->toArray();
            $jsonContent = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $questionsData = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);

            if (!isset($questionsData['questions']) || !is_array($questionsData['questions'])) {
                throw new \Exception('Invalid quiz data format from API');
            }

            // Create Quiz entity
            $quiz = new Quiz();
            $quiz->setPart($part);
            $quiz->setTitle('Quiz for Part: ' . $part->getTitle() . ' (Course: ' . $part->getCourse()->getTitle() . ')');
            $quiz->setGeneratedByAI(true);
            $quiz->setCreatedAt(new \DateTime());
            $quiz->setScoreWeight(1.0);

            // Parse and create Question entities
            foreach ($questionsData['questions'] as $qData) {
                $question = new Question();
                $question->setQuiz($quiz);
                $question->setType($qData['type'] === 'MCQ' ? QuestionType::MCQ->value : QuestionType::Numeric->value);
                $question->setText($qData['text']);
                if ($qData['type'] === 'MCQ') {
                    $question->setOptions($qData['options'] ?? []);
                }
                $question->setCorrectAnswer($qData['correct_answer']);
                $question->setGeneratedByAI(true);

                $quiz->addQuestion($question);
                $this->entityManager->persist($question);
            }

            $this->entityManager->persist($quiz);
            $this->entityManager->flush();

            return $quiz;
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate quiz for part ID ' . $part->getId() . ' (Course ID ' . $part->getCourse()->getId() . '): ' . $e->getMessage());

            // Fallback quiz
            try {
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
            } catch (\Exception $fallbackException) {
                $this->logger->error('Failed to generate fallback quiz for part ID ' . $part->getId() . ' (Course ID ' . $part->getCourse()->getId() . '): ' . $fallbackException->getMessage());
                return null;
            }
        }
    }
}