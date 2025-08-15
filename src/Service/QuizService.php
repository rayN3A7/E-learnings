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
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class QuizService
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private string $geminiApiKey;
    private string $geminiApiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent';
    private FilesystemAdapter $cache;

    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        string $geminiApiKey
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->geminiApiKey = $geminiApiKey;
        $this->cache = new FilesystemAdapter();
    }

    public function evaluateQuiz(Quiz $quiz, array $answers, User $user): array
    {
        $correctAnswers = 0;
        $totalQuestions = count($quiz->getQuestions());
        $feedback = [];
        foreach ($quiz->getQuestions() as $question) {
            $userAnswer = $answers[$question->getId()] ?? null;
            if ($userAnswer === null) {
                $feedback[$question->getId()] = ['isCorrect' => false, 'correctAnswer' => $question->getCorrectAnswer(), 'userAnswer' => null, 'explanation' => $question->getExplanation()];
                continue;
            }
            $isCorrect = $question->getType() === QuestionType::MCQ->value
                ? $userAnswer === $question->getCorrectAnswer()
                : abs((float) $userAnswer - (float) $question->getCorrectAnswer()) <= 0.01;
            if ($isCorrect) $correctAnswers++;
            $feedback[$question->getId()] = [
                'isCorrect' => $isCorrect,
                'correctAnswer' => $question->getCorrectAnswer(),
                'userAnswer' => $userAnswer,
                'explanation' => $isCorrect ? null : $question->getExplanation() // Only show for incorrect
            ];
        }
        $score = $totalQuestions > 0 ? ($correctAnswers / $totalQuestions) * 100 : 0;
        $attemptNumber = $this->getAttemptCount($user, $quiz) + 1;
        return ['score' => $score, 'feedback' => $feedback, 'attemptNumber' => $attemptNumber];
    }

    public function getAttemptCount(User $user, Quiz $quiz): int
    {
        return $this->entityManager->getRepository(QuizAttempt::class)->count(['user' => $user, 'quiz' => $quiz]);
    }

    public function canAttemptQuiz(User $user, Quiz $quiz): bool
    {
        return $this->getAttemptCount($user, $quiz) < 3;
    }
    public function getOrGeneratePartQuiz(?User $user, ?Part $part, string $quizMode = 'ai'): ?Quiz
    {
        if (!$part || !$part->getCourse() || $part->getTitle() === null) {
            $this->logger->warning('Invalid part, course, or missing part title for quiz generation: Part ID ' . ($part ? $part->getId() : 'NULL'));
            return null;
        }

        $cacheKey = 'part_' . $part->getId() . '_' . $quizMode;
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($part, $quizMode) {
            $item->expiresAfter(3600); // Cache for 1 hour
            $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => $part]);
            if ($quiz && $quiz->isGeneratedByAI() === ($quizMode === 'ai')) {
                $this->logger->info('Reusing existing quiz for part ID ' . $part->getId() . ', Quiz ID: ' . $quiz->getId());
                return $quiz;
            }

            if ($quizMode === 'manual') {
                $this->logger->info('Manual quiz mode selected for part ID ' . $part->getId() . ', no generation needed');
                return null;
            }

            $this->logger->debug('Generating AI quiz for part ID ' . $part->getId());
            $content = $this->extractRelevantContent($part->getCourse()->getTitle(), $part->getTitle(), $part);
            $quizData = $this->generateQuizFromGemini([
                'course_title' => $part->getCourse()->getTitle(),
                'part_title' => $part->getTitle(),
                'content' => $content,
                'context' => 'part'
            ]);

            return $quizData ? $this->buildQuiz($part, $quizData['questions'], 'Quiz for Part: ' . $part->getTitle() . ' (Course: ' . $part->getCourse()->getTitle() . ')')
                : $this->createFallbackQuiz($part);
        });
    }

   public function getOrGenerateFinalQuiz(?User $user, Course $course, string $quizMode = 'ai'): ?Quiz
{
    if (!$course) {
        $this->logger->warning('Invalid course for final quiz generation');
        return null;
    }

    $cacheKey = 'final_' . $course->getId() . '_' . $quizMode;
    return $this->cache->get($cacheKey, function (ItemInterface $item) use ($user, $course, $quizMode) {
        $item->expiresAfter(3600);
        $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['course' => $course, 'title' => 'Final Quiz for Course: ' . $course->getTitle()]);
        if ($quiz && $quiz->isGeneratedByAI() === ($quizMode === 'ai')) {
            $this->logger->info('Reusing existing final quiz for course ID ' . $course->getId() . ', Quiz ID: ' . $quiz->getId());
            return $quiz;
        }

        if ($quizMode === 'manual') {
            $this->logger->info('Manual final quiz mode selected for course ID ' . $course->getId() . ', no generation needed');
            return null;
        }

        $studentPerformance = 'medium';
        if ($user) {
            $parts = $course->getParts();
            $allQuizzesPassed = true;
            $totalScore = $quizCount = 0;

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
                $this->logger->info('Not all part quizzes passed for user ID ' . ($user ? $user->getId() : 'null') . ' in course ID ' . $course->getId());
                return null;
            }

            $averageScore = $quizCount > 0 ? $totalScore / $quizCount : 0;
            $studentPerformance = $averageScore >= 85 ? 'high' : ($averageScore >= 70 ? 'medium' : 'low');
        }

        $this->logger->debug('Generating AI final quiz for course ID ' . $course->getId());
        $content = $this->extractRelevantContent($course->getTitle(), '', $course);
        $quizData = $this->generateQuizFromGemini([
            'course_title' => $course->getTitle(),
            'content' => $content,
            'student_performance' => $studentPerformance,
            'context' => 'final'
        ]);

        if ($quizData) {
            $quiz = $this->buildQuiz(null, $quizData['questions'], 'Final Quiz for Course: ' . $course->getTitle(), $course);
            $quiz->setCourse($course); // Ensure the existing course is used
            return $quiz;
        }

        return $this->createFallbackFinalQuiz($course, $studentPerformance);
    });
}

    private function generateQuizFromGemini(array $inputData, int $maxRetries = 1): ?array
{
    $client = HttpClient::create(['timeout' => 10]);
    $isPartQuiz = $inputData['context'] === 'part';
    $promptFile = $isPartQuiz ? __DIR__ . '/prompts/part_quiz_prompt.txt' : __DIR__ . '/prompts/final_quiz_prompt.txt';

    if (!file_exists($promptFile)) {
        $this->logger->error('Prompt file not found: ' . $promptFile);
        return null;
    }

    $promptTemplate = file_get_contents($promptFile);
    $content = $inputData['content'];
    if ($isPartQuiz && isset($inputData['part'])) {
        if ($inputData['part']->getWrittenSection()) {
            $content .= "\nWritten Section: " . strip_tags($inputData['part']->getWrittenSection()->getContent()) . "\n";
        }
        if ($inputData['part']->getVideo()) {
            $content .= "\nVideo Description: " . strip_tags($inputData['part']->getVideo()->getDescription()) . "\n";
        }
    } elseif (isset($inputData['course'])) {
        foreach ($inputData['course']->getParts() as $part) {
            if ($part->getWrittenSection()) {
                $content .= "\nPart {$part->getPartOrder()} Written Section: " . strip_tags($part->getWrittenSection()->getContent()) . "\n";
            }
            if ($part->getVideo()) {
                $content .= "\nPart {$part->getPartOrder()} Video Description: " . strip_tags($part->getVideo()->getDescription()) . "\n";
            }
        }
    }

    $prompt = str_replace(
        ['{{course_title}}', '{{part_title}}', '{{content}}', '{{student_performance}}'],
        [$inputData['course_title'], $inputData['part_title'] ?? '', $content, $inputData['student_performance'] ?? 'medium'],
        $promptTemplate
    );

        $this->logger->debug('Gemini API prompt: ' . substr($prompt, 0, 200) . '...');

        try {
            $response = $client->request('POST', $this->geminiApiUrl, [
                'headers' => ['Content-Type' => 'application/json'],
                'query' => ['key' => $this->geminiApiKey],
                'json' => [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 1024,
                        'response_mime_type' => 'application/json'
                    ]
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Gemini API returned non-200 status: ' . $response->getStatusCode());
                return null;
            }

            $quizText = $response->toArray(false)['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $quizText = trim(preg_replace('/^```(?:json)?\n|\n```$/', '', $quizText));
            $quizData = json_decode($quizText, true);

            if (json_last_error() !== JSON_ERROR_NONE || !isset($quizData['questions']) || !is_array($quizData['questions'])) {
                $this->logger->error('Invalid JSON or quiz data format: ' . json_last_error_msg());
                return null;
            }

            $validQuestions = array_filter($quizData['questions'], fn($q) => isset($q['type'], $q['text'], $q['correctAnswer'], $q['explanation']) && in_array(strtolower($q['type']), ['mcq', 'numeric']));
            if (count($validQuestions) >= 5) {
                $this->logger->info('Successfully generated quiz with ' . count($validQuestions) . ' valid questions');
                return ['questions' => array_values($validQuestions)];
            }
        } catch (TransportExceptionInterface | \Exception $e) {
            $this->logger->error('Error during Gemini API call: ' . $e->getMessage());
        }

        return null;
    }

    private function buildQuiz(?Part $part, array $questions, string $title, ?Course $course = null): Quiz
    {
        $quiz = new Quiz();
        $quiz->setPart($part);
        $quiz->setCourse($course); // Set course for final quiz
        $quiz->setTitle($title);
        $quiz->setGeneratedByAI(true);
        $quiz->setCreatedAt(new \DateTime());
        $quiz->setScoreWeight(1.0);

        $mcqQuestions = array_filter($questions, fn($q) => strtolower($q['type']) === 'mcq');
        $numericQuestions = array_filter($questions, fn($q) => strtolower($q['type']) === 'numeric');
        $mcqCount = min(5, count($mcqQuestions));
        $numericCount = min(5, count($numericQuestions));

        for ($i = 0; $i < $mcqCount; $i++) {
            $qData = array_values($mcqQuestions)[$i];
            $question = (new Question())
                ->setQuiz($quiz)
                ->setType(QuestionType::MCQ->value)
                ->setText($qData['text'])
                ->setOptions($qData['options'] ?? ['Option A', 'Option B', 'Option C', 'Option D'])
                ->setCorrectAnswer($qData['correctAnswer'])
                ->setExplanation($qData['explanation'] ?? '')
                ->setGeneratedByAI(true);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }
        for ($i = 0; $i < $numericCount; $i++) {
            $qData = array_values($numericQuestions)[$i];
            $question = (new Question())
                ->setQuiz($quiz)
                ->setType(QuestionType::Numeric->value)
                ->setText($qData['text'])
                ->setCorrectAnswer((string) $qData['correctAnswer'])
                ->setExplanation($qData['explanation'] ?? '')
                ->setGeneratedByAI(true);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        while ($mcqCount < 5) {
            $question = (new Question())
                ->setQuiz($quiz)
                ->setType(QuestionType::MCQ->value)
                ->setText('Default MCQ question')
                ->setOptions(['Option A', 'Option B', 'Option C', 'Option D'])
                ->setCorrectAnswer('Option A')
                ->setExplanation('This is a default explanation for the MCQ question.')
                ->setGeneratedByAI(true);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
            $mcqCount++;
        }
        while ($numericCount < 5) {
            $question = (new Question())
                ->setQuiz($quiz)
                ->setType(QuestionType::Numeric->value)
                ->setText('Default numeric question')
                ->setCorrectAnswer('0')
                ->setExplanation('This is a default explanation for the numeric question.')
                ->setGeneratedByAI(true);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
            $numericCount++;
        }

        $this->entityManager->persist($quiz);
        return $quiz; // Let the controller handle flush
    }

    private function createFallbackQuiz(Part $part): Quiz
    {
        $quiz = (new Quiz())
            ->setPart($part)
            ->setTitle('Fallback Quiz for Part: ' . ($part->getTitle() ?? 'Untitled Part') . ' (Course: ' . $part->getCourse()->getTitle() . ')')
            ->setGeneratedByAI(false)
            ->setCreatedAt(new \DateTime())
            ->setScoreWeight(1.0);
        $content = $this->extractRelevantContent($part->getCourse()->getTitle(), $part->getTitle(), $part);
        $baseTopic = strpos(strtolower($content), 'lagrange') !== false ? 'Lagrange Interpolation' : 'Polynomial Interpolation';

        $questions = [
            'mcq' => [
                ['text' => "What is the main purpose of $baseTopic?", 'options' => ['Construct polynomials', 'Solve equations', 'Optimize', 'Classify'], 'correctAnswer' => 'Construct polynomials', 'explanation' => 'Interpolation constructs polynomials that pass through given data points exactly. Common mistake: confusing with approximation methods.'],
                ['text' => "What does $baseTopic use?", 'options' => ['Data points', 'Random samples', 'Derivatives', 'Integrals'], 'correctAnswer' => 'Data points', 'explanation' => 'It uses known data points (x,y pairs). Step: Build basis polynomials for each point.'],
                ['text' => "Key feature of $baseTopic?", 'options' => ['Exact fit', 'Linear only', 'Optimization', 'Reduction'], 'correctAnswer' => 'Exact fit', 'explanation' => 'The polynomial fits all points exactly. Mistake: Thinking it minimizes error like regression.'],
                ['text' => "Polynomials in $baseTopic?", 'options' => ['Basis', 'Orthogonal', 'Chebyshev', 'Fourier'], 'correctAnswer' => 'Basis', 'explanation' => 'Uses Lagrange basis polynomials. Step: l_i(x) = product over j≠i of (x - x_j)/(x_i - x_j).'],
                ['text' => "Result at interpolation point?", 'options' => ['Matches data', 'Approximates derivative', 'Zero', 'Minimizes'], 'correctAnswer' => 'Matches data', 'explanation' => 'P(x_i) = y_i exactly. Common mistake: Expecting smooth curves beyond points (Runge phenomenon).'],
            ],
            'numeric' => [
                ['text' => "Points for linear polynomial?", 'correctAnswer' => '2', 'explanation' => 'Linear (degree 1) needs 2 points. General: n points for degree n-1.'],
                ['text' => "Degree for 3 points?", 'correctAnswer' => '2', 'explanation' => '3 points fit a quadratic (degree 2). Mistake: Overestimating degree.'],
                ['text' => "Basis polynomials for 4 points?", 'correctAnswer' => '4', 'explanation' => 'One basis per point. Sum to 1 at each x_i.'],
                ['text' => "Sum of basis at point?", 'correctAnswer' => '1', 'explanation' => 'By construction, sum l_i(x) = 1 for Lagrange.'],
                ['text' => "Terms for 5 points?", 'correctAnswer' => '5', 'explanation' => 'Degree 4 polynomial has 5 terms (a0 + a1x + ... + a4x^4).'],
            ],
        ];

        foreach ($questions['numeric'] as $qData) {
            $question = (new Question())
                ->setQuiz($quiz)
                ->setType(QuestionType::Numeric->value)
                ->setText($qData['text'])
                ->setCorrectAnswer($qData['correctAnswer'])
                ->setExplanation($qData['explanation'])
                ->setGeneratedByAI(false);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }
        foreach ($questions['mcq'] as $qData) {
            $question = (new Question())
                ->setQuiz($quiz)
                ->setType(QuestionType::MCQ->value)
                ->setText($qData['text'])
                ->setOptions($qData['options'])
                ->setCorrectAnswer($qData['correctAnswer'])
                ->setExplanation($qData['explanation'])
                ->setGeneratedByAI(false);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        $this->entityManager->persist($quiz);
        $this->logger->info('Generated fallback quiz for part ID ' . $part->getId());
        return $quiz; // Let the controller handle flush
    }

    private function createFallbackFinalQuiz(Course $course, string $studentPerformance): Quiz
    {
        $quiz = (new Quiz())
            ->setCourse($course)
            ->setTitle('Fallback Final Quiz for Course: ' . $course->getTitle())
            ->setGeneratedByAI(false)
            ->setCreatedAt(new \DateTime())
            ->setScoreWeight(1.0);
        $content = $this->extractRelevantContent($course->getTitle(), '', $course);
        $baseTopic = strpos(strtolower($content), 'lagrange') !== false ? 'Lagrange Interpolation' : 'Polynomial Interpolation';

        $mcqQuestions = [
            ['text' => "Primary goal of $baseTopic?", 'options' => ['Fit polynomials', 'Solve equations', 'Approximate', 'Optimize'], 'correctAnswer' => 'Fit polynomials', 'explanation' => 'Aims to fit a polynomial exactly through points. Step: Use basis to weight y-values.'],
            ['text' => "Method for $baseTopic?", 'options' => ['Lagrange', 'Gaussian', 'Fourier', 'Least squares'], 'correctAnswer' => 'Lagrange', 'explanation' => 'Lagrange method is direct. Mistake: Confusing with Newton (divided differences).'],
            ['text' => "Ensures at points?", 'options' => ['Exact match', 'Minimum error', 'Linear', 'Constant'], 'correctAnswer' => 'Exact match', 'explanation' => 'P(x_i) = y_i. Common issue: Oscillation between points.'],
            ['text' => "Based on?", 'options' => ['Data points', 'Random', 'Derivatives', 'Integrals'], 'correctAnswer' => 'Data points', 'explanation' => 'Only needs (x,y) pairs, no derivatives.'],
            ['text' => "Challenge in $baseTopic?", 'options' => ['Runge’s phenomenon', 'Overfitting', 'Underfitting', 'Variance'], 'correctAnswer' => 'Runge’s phenomenon', 'explanation' => 'High-degree polynomials oscillate. Solution: Use splines or Chebyshev points.'],
        ];

        $numericQuestions = $studentPerformance === 'high' ? [
            ['text' => "Degree for 4 points?", 'correctAnswer' => '3', 'explanation' => 'n points need degree at most n-1.'],
            ['text' => "Points for cubic?", 'correctAnswer' => '4', 'explanation' => 'Cubic is degree 3, needs 4 points.'],
            ['text' => "Basis sum at point?", 'correctAnswer' => '1', 'explanation' => 'Property of Lagrange basis.'],
            ['text' => "Terms for 6 points?", 'correctAnswer' => '6', 'explanation' => 'Degree 5 has 6 coefficients.'],
            ['text' => "Degree for 2 points?", 'correctAnswer' => '1', 'explanation' => 'Linear interpolation.'],
        ] : [
            ['text' => "Points for linear?", 'correctAnswer' => '2', 'explanation' => 'Basic: 2 points for straight line.'],
            ['text' => "Degree for 3 points?", 'correctAnswer' => '2', 'explanation' => 'Quadratic curve through 3 points.'],
            ['text' => "Basis for 4 points?", 'correctAnswer' => '4', 'explanation' => 'One per point.'],
            ['text' => "Basis sum at point?", 'correctAnswer' => '1', 'explanation' => 'Ensures interpolation property.'],
            ['text' => "Points for quadratic?", 'correctAnswer' => '3', 'explanation' => 'Degree 2 needs 3 points.'],
        ];

        foreach ($numericQuestions as $qData) {
            $question = (new Question())
                ->setQuiz($quiz)
                ->setType(QuestionType::Numeric->value)
                ->setText($qData['text'])
                ->setCorrectAnswer($qData['correctAnswer'])
                ->setExplanation($qData['explanation'])
                ->setGeneratedByAI(false);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }
        foreach ($mcqQuestions as $qData) {
            $question = (new Question())
                ->setQuiz($quiz)
                ->setType(QuestionType::MCQ->value)
                ->setText($qData['text'])
                ->setOptions($qData['options'])
                ->setCorrectAnswer($qData['correctAnswer'])
                ->setExplanation($qData['explanation'])
                ->setGeneratedByAI(false);
            $quiz->addQuestion($question);
            $this->entityManager->persist($question);
        }

        $this->entityManager->persist($quiz);
        $this->logger->info('Generated fallback final quiz for course ID ' . $course->getId());
        return $quiz; // Let the controller handle flush
    }

    private function extractRelevantContent(string $courseTitle, ?string $partTitle, $entity): string
    {
        $content = $courseTitle . "\n" . ($partTitle ?? '') . "\n";
        if ($entity instanceof Part) {
            if ($entity->getDescription()) $content .= $entity->getDescription() . "\n";
            if ($entity->getVideo() && $entity->getVideo()->getDescription()) $content .= $entity->getVideo()->getDescription() . "\n";
            if ($entity->getWrittenSection()) $content .= "Written Section: " . strip_tags($entity->getWrittenSection()->getContent()) . "\n";
        } elseif ($entity instanceof Course) {
            if ($entity->getDescription()) $content .= $entity->getDescription() . "\n";
            foreach ($entity->getParts() as $part) {
                $content .= ($part->getTitle() ?? 'Untitled Part') . "\n";
                if ($part->getDescription()) $content .= $part->getDescription() . "\n";
                if ($part->getWrittenSection()) $content .= "Part {$part->getPartOrder()} Written Section: " . strip_tags($part->getWrittenSection()->getContent()) . "\n";
            }
        }
        return trim($content);
    }
}