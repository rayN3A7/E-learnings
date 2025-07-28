<?php

namespace App\Service;

use App\Entity\Part;
use App\Entity\Course;
use App\Entity\Question;
use App\Entity\Quiz;
use App\Entity\User;
use App\Entity\QuizAttempt;
use App\Enum\QuestionType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class QuizService
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private string $geminiApiKey;
    private string $geminiApiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent';

    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        string $geminiApiKey
    ) {
        $this->entityManager = $entityManager;
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

    public function getOrGeneratePartQuiz(?User $user, ?Part $part): ?Quiz
    {
        if (!$part || !$part->getCourse()) {
            $this->logger->warning('Invalid part or course for quiz generation: Part ID ' . ($part ? $part->getId() : 'NULL'));
            return null;
        }

        $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => $part]);
        if ($quiz) {
            $this->logger->info('Reusing existing quiz for part ID ' . $part->getId() . ', Quiz ID: ' . $quiz->getId());
            return $quiz;
        }

        $this->logger->debug('No existing quiz found for part ID ' . $part->getId() . ', proceeding to generate new quiz');

        $content = $part->getCourse()->getTitle() . "\n" . $part->getTitle() . "\n";
        if ($part->getDescription()) {
            $content .= $part->getDescription() . "\n";
        }
        if ($part->getVideo() && $part->getVideo()->getDescription()) {
            $content .= $part->getVideo()->getDescription() . "\n";
        }
        if ($part->getWrittenSection()) {
            $content .= "Written Section: " . strip_tags($part->getWrittenSection()->getContent()) . "\n";
        }

        $quizData = $this->generateQuizFromGemini([
            'course_title' => $part->getCourse()->getTitle(),
            'part_title' => $part->getTitle(),
            'content' => $content,
            'num_questions' => 10,
            'part' => $part
        ]);

        if (!$quizData) {
            $this->logger->warning('First API attempt failed, retrying with adjusted parameters for part ID ' . $part->getId());
            $quizData = $this->generateQuizFromGemini([
                'course_title' => $part->getCourse()->getTitle(),
                'part_title' => $part->getTitle(),
                'content' => $content,
                'num_questions' => 10,
                'part' => $part,
                'retry' => true, // Adjust parameters for retry
            ], 3);
        }

        if (!$quizData || !isset($quizData['questions']) || count($quizData['questions']) < 5) {
            $this->logger->error('Invalid or insufficient quiz data after retries for part ID ' . $part->getId() . ': ' . json_encode($quizData));
            return $this->createFallbackQuiz($part);
        }

        $quiz = new Quiz();
        $quiz->setPart($part);
        $quiz->setTitle('Quiz for Part: ' . $part->getTitle() . ' (Course: ' . $part->getCourse()->getTitle() . ')');
        $quiz->setGeneratedByAI(true);
        $quiz->setCreatedAt(new \DateTime());
        $quiz->setScoreWeight(1.0);

        foreach ($quizData['questions'] as $qData) {
            $question = new Question();
            $question->setQuiz($quiz);
            $question->setType(strtolower($qData['type']) === 'mcq' ? QuestionType::MCQ->value : QuestionType::Numeric->value);
            $question->setText($qData['text']);
            if ($qData['type'] === 'mcq') {
                $question->setOptions($qData['options']);
            }
            $question->setCorrectAnswer((string) $qData['correctAnswer']);
            $question->setGeneratedByAI(true);

            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        $this->entityManager->persist($quiz);
        $this->entityManager->flush();
        $this->logger->info('Generated and persisted new quiz for part ID ' . $part->getId() . ' with ' . count($quiz->getQuestions()) . ' questions');

        return $quiz;
    }

    public function getOrGenerateFinalQuiz(?User $user, Course $course): ?Quiz
    {
        if (!$user || !$course) {
            $this->logger->warning('Invalid user or course for final quiz generation');
            return null;
        }

        $parts = $course->getParts();
        $allQuizzesPassed = true;
        $totalScore = 0;
        $quizCount = 0;

        foreach ($parts as $part) {
            $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => $part]);
            if ($quiz) {
                $latestAttempt = $this->entityManager->getRepository(QuizAttempt::class)->findOneBy(
                    ['quiz' => $quiz, 'user' => $user],
                    ['takenAt' => 'DESC']
                );
                if (!$latestAttempt || $latestAttempt->getScore() < 70) {
                    $allQuizzesPassed = false;
                    break;
                }
                $totalScore += $latestAttempt->getScore();
                $quizCount++;
            } else {
                $allQuizzesPassed = false;
                break;
            }
        }

        if (!$allQuizzesPassed) {
            $this->logger->info('Not all part quizzes passed for user ID ' . $user->getId() . ' in course ID ' . $course->getId());
            return null;
        }

        $averageScore = $quizCount > 0 ? $totalScore / $quizCount : 0;
        $studentPerformance = $averageScore >= 85 ? 'high' : ($averageScore >= 70 ? 'medium' : 'low');

        $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => null, 'title' => 'Final Quiz for Course: ' . $course->getTitle()]);
        if ($quiz) {
            $this->logger->info('Reusing existing final quiz for course ID ' . $course->getId() . ', Quiz ID: ' . $quiz->getId());
            return $quiz;
        }

        $content = $course->getTitle() . "\n" . ($course->getDescription() ?? '') . "\n";
        foreach ($course->getParts() as $part) {
            $content .= $part->getTitle() . "\n" . ($part->getDescription() ?? '') . "\n";
            if ($part->getWrittenSection()) {
                $content .= "Part {$part->getPartOrder()} Written Section: " . strip_tags($part->getWrittenSection()->getContent()) . "\n";
            }
        }

        $quizData = $this->generateQuizFromGemini([
            'course_title' => $course->getTitle(),
            'content' => $content,
            'num_questions' => 10,
            'course' => $course,
            'student_performance' => $studentPerformance
        ]);

        if (!$quizData) {
            $this->logger->warning('First API attempt failed, retrying with adjusted parameters for course ID ' . $course->getId());
            $quizData = $this->generateQuizFromGemini([
                'course_title' => $course->getTitle(),
                'content' => $content,
                'num_questions' => 10,
                'course' => $course,
                'student_performance' => $studentPerformance,
                'retry' => true,
            ], 3);
        }

        if (!$quizData || !isset($quizData['questions']) || count($quizData['questions']) < 5) {
            $this->logger->error('Invalid or insufficient final quiz data after retries for course ID ' . $course->getId() . ': ' . json_encode($quizData));
            return $this->createFallbackFinalQuiz($course, $studentPerformance);
        }

        $quiz = new Quiz();
        $quiz->setTitle('Final Quiz for Course: ' . $course->getTitle());
        $quiz->setGeneratedByAI(true);
        $quiz->setCreatedAt(new \DateTime());
        $quiz->setScoreWeight(1.0);

        foreach ($quizData['questions'] as $qData) {
            $question = new Question();
            $question->setQuiz($quiz);
            $question->setType(strtolower($qData['type']) === 'mcq' ? QuestionType::MCQ->value : QuestionType::Numeric->value);
            $question->setText($qData['text']);
            if ($qData['type'] === 'mcq') {
                $question->setOptions($qData['options']);
            }
            $question->setCorrectAnswer((string) $qData['correctAnswer']);
            $question->setGeneratedByAI(true);

            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        $this->entityManager->persist($quiz);
        $this->entityManager->flush();
        $this->logger->info('Generated and persisted new final quiz for course ID ' . $course->getId() . ' with ' . count($quiz->getQuestions()) . ' questions');

        return $quiz;
    }

    private function generateQuizFromGemini(array $inputData, int $maxRetries = 3): ?array
    {
        $client = HttpClient::create();
        $isPartQuiz = isset($inputData['part_title']);
        $promptFile = $isPartQuiz
            ? __DIR__ . '/prompts/part_quiz_prompt.txt'
            : __DIR__ . '/prompts/final_quiz_prompt.txt';

        if (!file_exists($promptFile)) {
            $this->logger->error('Prompt file not found: ' . $promptFile);
            return null;
        }

        $promptTemplate = file_get_contents($promptFile);
        if ($promptTemplate === false) {
            $this->logger->error('Failed to read prompt file: ' . $promptFile);
            return null;
        }

        $content = $inputData['content'];
        if ($isPartQuiz && isset($inputData['part'])) {
            $part = $inputData['part'];
            if ($part->getWrittenSection()) {
                $content .= "\nWritten Section: " . strip_tags($part->getWrittenSection()->getContent()) . "\n";
            }
        } else if (isset($inputData['course'])) {
            $course = $inputData['course'];
            foreach ($course->getParts() as $part) {
                if ($part->getWrittenSection()) {
                    $content .= "\nPart {$part->getPartOrder()} Written Section: " . strip_tags($part->getWrittenSection()->getContent()) . "\n";
                }
            }
        }

        $prompt = str_replace(
            ['{{course_title}}', '{{part_title}}', '{{content}}', '{{student_performance}}'],
            [$inputData['course_title'], $inputData['part_title'] ?? '', $content, $inputData['student_performance'] ?? 'medium'],
            $promptTemplate
        );

        $this->logger->debug('Gemini API prompt: ' . $prompt);

        $retryCount = 0;
        while ($retryCount < $maxRetries) {
            try {
                $response = $client->request('POST', $this->geminiApiUrl, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'query' => [
                        'key' => $this->geminiApiKey,
                    ],
                    'json' => [
                        'contents' => [
                            [
                                'parts' => [
                                    [
                                        'text' => $prompt
                                    ]
                                ]
                            ]
                        ],
                        'generationConfig' => [
                            'temperature' => isset($inputData['retry']) ? 0.5 : 0.7, // Lower temperature on retry
                            'maxOutputTokens' => isset($inputData['retry']) ? 1536 : 2048, // Reduce tokens on retry
                            'response_mime_type' => 'application/json'
                        ]
                    ],
                ]);

                $statusCode = $response->getStatusCode();
                $responseData = $response->toArray(false);
                $this->logger->info("Attempt $retryCount - Gemini API response status: $statusCode, raw response: " . json_encode($responseData, JSON_PRETTY_PRINT));

                if ($statusCode !== 200) {
                    $this->logger->error("Attempt $retryCount - Gemini API returned non-200 status: $statusCode, response: " . json_encode($responseData));
                    if ($retryCount < $maxRetries - 1) {
                        $retryCount++;
                        sleep(2);
                        continue;
                    }
                    return null;
                }

                if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                    $this->logger->error("Attempt $retryCount - Invalid response structure from Gemini API: " . json_encode($responseData));
                    if ($retryCount < $maxRetries - 1) {
                        $retryCount++;
                        sleep(2);
                        continue;
                    }
                    return null;
                }

                $quizText = $responseData['candidates'][0]['content']['parts'][0]['text'];
                $quizText = preg_replace('/^```(?:json)?\n|\n```$/', '', trim($quizText));
                $this->logger->debug("Attempt $retryCount - Raw quiz text from API: " . $quizText);

                $quizData = json_decode($quizText, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->error("Attempt $retryCount - Failed to decode Gemini response: " . json_last_error_msg() . ", raw text: " . $quizText);
                    if ($retryCount < $maxRetries - 1) {
                        $retryCount++;
                        sleep(2);
                        continue;
                    }
                    return null;
                }

                if (!isset($quizData['questions']) || !is_array($quizData['questions']) || count($quizData['questions']) < 5) {
                    $this->logger->error("Attempt $retryCount - Invalid quiz data format: Expected at least 5 questions, got " . (count($quizData['questions'] ?? [])) . ", data: " . json_encode($quizData));
                    if ($retryCount < $maxRetries - 1) {
                        $retryCount++;
                        sleep(2);
                        continue;
                    }
                    return null;
                }

                $validQuestions = [];
                foreach ($quizData['questions'] as $qData) {
                    if (
                        isset($qData['type'], $qData['text'], $qData['correctAnswer']) &&
                        in_array(strtolower($qData['type']), ['mcq', 'numeric']) &&
                        ($qData['type'] === 'numeric' || (isset($qData['options']) && is_array($qData['options']) && count($qData['options']) === 4))
                    ) {
                        $validQuestions[] = $qData;
                    } else {
                        $this->logger->warning("Attempt $retryCount - Skipping invalid question data: " . json_encode($qData));
                    }
                }

                if (count($validQuestions) < 5) {
                    $this->logger->error("Attempt $retryCount - Insufficient valid questions: " . count($validQuestions));
                    if ($retryCount < $maxRetries - 1) {
                        $retryCount++;
                        sleep(2);
                        continue;
                    }
                    return null;
                }

                $this->logger->info("Successfully generated quiz with " . count($validQuestions) . " valid questions on attempt $retryCount");
                return ['questions' => $validQuestions];
            } catch (TransportExceptionInterface $e) {
                $this->logger->error("Attempt $retryCount - Gemini API request failed: " . $e->getMessage() . ", URL: " . $this->geminiApiUrl);
                if ($retryCount < $maxRetries - 1) {
                    $retryCount++;
                    sleep(2);
                    continue;
                }
                return null;
            } catch (\Exception $e) {
                $this->logger->error("Attempt $retryCount - Unexpected error during Gemini API call: " . $e->getMessage() . ", Trace: " . $e->getTraceAsString());
                if ($retryCount < $maxRetries - 1) {
                    $retryCount++;
                    sleep(2);
                    continue;
                }
                return null;
            }
        }

        $this->logger->error("Failed to generate quiz after $maxRetries attempts");
        return null;
    }

    private function createFallbackQuiz(Part $part): ?Quiz
    {
        $quiz = new Quiz();
        $quiz->setPart($part);
        $quiz->setTitle('Fallback Quiz for Part: ' . $part->getTitle() . ' (Course: ' . $part->getCourse()->getTitle() . ')');
        $quiz->setGeneratedByAI(false);
        $quiz->setCreatedAt(new \DateTime());
        $quiz->setScoreWeight(1.0);

        $content = $part->getCourse()->getTitle() . "\n" . $part->getTitle() . "\n";
        if ($part->getDescription()) {
            $content .= $part->getDescription() . "\n";
        }
        if ($part->getWrittenSection()) {
            $content .= strip_tags($part->getWrittenSection()->getContent());
        }
        $isLagrange = strpos(strtolower($content), 'lagrange') !== false;
        $baseTopic = $isLagrange ? 'Lagrange Interpolation' : 'Polynomial Interpolation';

        $mcqTemplates = [
            [
                'text' => "What is the main purpose of $baseTopic as described in the content?",
                'options' => ['Construct polynomials through points', 'Solve differential equations', 'Optimize functions', 'Classify data'],
                'correctAnswer' => 'Construct polynomials through points'
            ],
            [
                'text' => "What does $baseTopic use to build polynomials?",
                'options' => ['Given data points', 'Random samples', 'Derivatives', 'Integrals'],
                'correctAnswer' => 'Given data points'
            ],
            [
                'text' => "What is a key feature of $baseTopic mentioned in the content?",
                'options' => ['Fits data exactly at points', 'Always linear', 'Uses optimization', 'Reduces dimensions'],
                'correctAnswer' => 'Fits data exactly at points'
            ],
            [
                'text' => "In $baseTopic, what are the polynomials called?",
                'options' => ['Basis polynomials', 'Orthogonal polynomials', 'Chebyshev polynomials', 'Fourier polynomials'],
                'correctAnswer' => 'Basis polynomials'
            ],
            [
                'text' => "What is the result of $baseTopic at an interpolation point?",
                'options' => ['Matches the data value', 'Approximates the derivative', 'Zeroes the function', 'Minimizes error'],
                'correctAnswer' => 'Matches the data value'
            ]
        ];

        $numericTemplates = [
            [
                'text' => "How many points are needed for a linear polynomial in $baseTopic?",
                'correctAnswer' => '2'
            ],
            [
                'text' => "What is the degree of a polynomial interpolating 3 points in $baseTopic?",
                'correctAnswer' => '2'
            ],
            [
                'text' => "How many basis polynomials are used for 4 points in $baseTopic?",
                'correctAnswer' => '4'
            ],
            [
                'text' => "What is the sum of Lagrange basis polynomials at an interpolation point?",
                'correctAnswer' => '1'
            ],
            [
                'text' => "How many terms are in a polynomial interpolating 5 points in $baseTopic?",
                'correctAnswer' => '5'
            ]
        ];

        for ($i = 0; $i < 5; $i++) {
            $question = new Question();
            $question->setQuiz($quiz);
            $question->setType(QuestionType::MCQ->value);
            $question->setText($mcqTemplates[$i]['text']);
            $question->setOptions($mcqTemplates[$i]['options']);
            $question->setCorrectAnswer($mcqTemplates[$i]['correctAnswer']);
            $question->setGeneratedByAI(false);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        for ($i = 0; $i < 5; $i++) {
            $question = new Question();
            $question->setQuiz($quiz);
            $question->setType(QuestionType::Numeric->value);
            $question->setText($numericTemplates[$i]['text']);
            $question->setCorrectAnswer($numericTemplates[$i]['correctAnswer']);
            $question->setGeneratedByAI(false);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        $this->entityManager->persist($quiz);
        $this->entityManager->flush();
        $this->logger->info('Generated fallback quiz for part ID ' . $part->getId() . ' with content-based questions');

        return $quiz;
    }

    private function createFallbackFinalQuiz(Course $course, string $studentPerformance): ?Quiz
    {
        $quiz = new Quiz();
        $quiz->setTitle('Fallback Final Quiz for Course: ' . $course->getTitle());
        $quiz->setGeneratedByAI(false);
        $quiz->setCreatedAt(new \DateTime());
        $quiz->setScoreWeight(1.0);

        $content = $course->getTitle() . "\n" . ($course->getDescription() ?? '') . "\n";
        foreach ($course->getParts() as $part) {
            $content .= $part->getTitle() . "\n" . ($part->getDescription() ?? '') . "\n";
            if ($part->getWrittenSection()) {
                $content .= strip_tags($part->getWrittenSection()->getContent()) . "\n";
            }
        }
        $isLagrange = strpos(strtolower($content), 'lagrange') !== false;
        $baseTopic = $isLagrange ? 'Lagrange Interpolation' : 'Polynomial Interpolation';

        $mcqTemplates = [
            [
                'text' => "What is the primary goal of $baseTopic in the course?",
                'options' => ['Fit polynomials to data points', 'Solve equations', 'Approximate derivatives', 'Optimize algorithms'],
                'correctAnswer' => 'Fit polynomials to data points'
            ],
            [
                'text' => "What method is used in $baseTopic to construct polynomials?",
                'options' => ['Lagrange method', 'Gaussian elimination', 'Fourier transform', 'Least squares'],
                'correctAnswer' => 'Lagrange method'
            ],
            [
                'text' => "What does $baseTopic ensure at interpolation points?",
                'options' => ['Exact data matching', 'Minimum error', 'Linear functions', 'Constant values'],
                'correctAnswer' => 'Exact data matching'
            ],
            [
                'text' => "What are the polynomials in $baseTopic based on?",
                'options' => ['Data points', 'Random values', 'Derivatives', 'Integrals'],
                'correctAnswer' => 'Data points'
            ],
            [
                'text' => "What is a challenge mentioned in the course for $baseTopic?",
                'options' => ['Runge’s phenomenon', 'Overfitting', 'Underfitting', 'High variance'],
                'correctAnswer' => 'Runge’s phenomenon'
            ]
        ];

        $numericTemplates = $studentPerformance === 'high' ? [
            [
                'text' => "What is the degree of a polynomial interpolating 4 points in $baseTopic?",
                'correctAnswer' => '3'
            ],
            [
                'text' => "How many points are needed for a cubic polynomial in $baseTopic?",
                'correctAnswer' => '4'
            ],
            [
                'text' => "What is the sum of Lagrange basis polynomials at an interpolation point?",
                'correctAnswer' => '1'
            ],
            [
                'text' => "How many terms are in a polynomial interpolating 6 points in $baseTopic?",
                'correctAnswer' => '6'
            ],
            [
                'text' => "What is the degree of a polynomial fitting 2 points in $baseTopic?",
                'correctAnswer' => '1'
            ]
        ] : [
            [
                'text' => "How many points are needed for a linear polynomial in $baseTopic?",
                'correctAnswer' => '2'
            ],
            [
                'text' => "What is the degree of a polynomial interpolating 3 points in $baseTopic?",
                'correctAnswer' => '2'
            ],
            [
                'text' => "How many basis polynomials are used for 4 points in $baseTopic?",
                'correctAnswer' => '4'
            ],
            [
                'text' => "What is the sum of Lagrange basis polynomials at an interpolation point?",
                'correctAnswer' => '1'
            ],
            [
                'text' => "How many points define a quadratic polynomial in $baseTopic?",
                'correctAnswer' => '3'
            ]
        ];

        for ($i = 0; $i < 5; $i++) {
            $question = new Question();
            $question->setQuiz($quiz);
            $question->setType(QuestionType::MCQ->value);
            $question->setText($mcqTemplates[$i]['text']);
            $question->setOptions($mcqTemplates[$i]['options']);
            $question->setCorrectAnswer($mcqTemplates[$i]['correctAnswer']);
            $question->setGeneratedByAI(false);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        for ($i = 0; $i < 5; $i++) {
            $question = new Question();
            $question->setQuiz($quiz);
            $question->setType(QuestionType::Numeric->value);
            $question->setText($numericTemplates[$i]['text']);
            $question->setCorrectAnswer($numericTemplates[$i]['correctAnswer']);
            $question->setGeneratedByAI(false);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        $this->entityManager->persist($quiz);
        $this->entityManager->flush();
        $this->logger->info('Generated fallback final quiz for course ID ' . $course->getId() . ' with content-based questions');

        return $quiz;
    }
}