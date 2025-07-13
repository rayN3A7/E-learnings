<?php

namespace App\Service;

use App\Entity\Course;
use App\Entity\Question;
use App\Entity\Quiz;
use App\Entity\User;
use App\Enum\QuestionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\Exception\JsonException;

class QuizService
{
    private EntityManagerInterface $entityManager;
    private HttpClientInterface $httpClient;
    private string $geminiApiKey;

    public function __construct(EntityManagerInterface $entityManager, HttpClientInterface $httpClient, string $geminiApiKey)
    {
        $this->entityManager = $entityManager;
        $this->httpClient = $httpClient;
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
                // For numeric questions, allow a tolerance of ±0.01
                $correctValue = (float) $question->getCorrectAnswer();
                $userValue = (float) $userAnswer;
                if (abs($correctValue - $userValue) <= 0.01) {
                    $correctAnswers++;
                }
            }
        }

        return $totalQuestions > 0 ? ($correctAnswers / $totalQuestions) * 100 : 0;
    }

    public function getOrGenerateFinalQuiz(User $user, Course $course): ?Quiz
    {
        $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy([
            'course' => $course,
            'part' => null, // Final quiz has no part
        ]);

        if ($quiz) {
            return $quiz;
        }

        // Collect course content for quiz generation
        $content = $course->getTitle() . "\n" . $course->getDescription() . "\n";
        foreach ($course->getParts() as $part) {
            $content .= $part->getTitle() . "\n";
            if ($part->getDescription()) {
                $content .= $part->getDescription() . "\n";
            }
            if ($part->getVideo() && $part->getVideo()->getDescription()) {
                $content .= $part->getVideo()->getDescription() . "\n";
            }
            if ($part->getWrittenSection() && $part->getWrittenSection()->getContent()) {
                $content .= $part->getWrittenSection()->getContent() . "\n";
            }
        }

        try {
            // Prepare prompt for Gemini API
            $prompt = <<<EOD
Generate a quiz with 2 questions based on the following course content. Return the response in JSON format with the structure:
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
                        'temperature' => 0.7, // Balanced creativity for quiz generation
                        'maxOutputTokens' => 500, // Limit output size
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
            $quiz->setCourse($course);
            $quiz->setTitle('Final Course Quiz');
            $quiz->setGeneratedByAI(true);
            $quiz->setCreatedAt(new \DateTime());

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
            // Fallback to a default quiz if API fails
            $quiz = new Quiz();
            $quiz->setCourse($course);
            $quiz->setTitle('Final Course Quiz (Fallback)');
            $quiz->setGeneratedByAI(false);
            $quiz->setCreatedAt(new \DateTime());

            $question1 = new Question();
            $question1->setQuiz($quiz);
            $question1->setType(QuestionType::MCQ->value);
            $question1->setText('What is the purpose of the bisection method in numerical analysis?');
            $question1->setOptions([
                'To find roots of a function',
                'To interpolate data points',
                'To solve differential equations',
                'To optimize functions',
            ]);
            $question1->setCorrectAnswer('To find roots of a function');
            $question1->setGeneratedByAI(false);

            $question2 = new Question();
            $question2->setQuiz($quiz);
            $question2->setType(QuestionType::Numeric->value);
            $question2->setText('Using the Newton-Raphson method, what is the next approximation for the root of f(x) = x^2 - 4 starting at x_0 = 3?');
            $question2->setCorrectAnswer('2.5');
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
}