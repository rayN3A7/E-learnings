<?php

namespace App\Controller;

use App\Entity\Part;
use App\Entity\User;
use App\Entity\Quiz;
use App\Entity\QuizAttempt;
use App\Service\CourseProgressService;
use App\Service\QuizService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class PartController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private CourseProgressService $progressService;
    private QuizService $quizService;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        CourseProgressService $progressService,
        QuizService $quizService,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->progressService = $progressService;
        $this->quizService = $quizService;
        $this->logger = $logger;
    }

   #[Route('/part/{id}', name: 'app_part_details')]
public function show(int $id): Response
{
    $part = $this->entityManager->createQueryBuilder()
        ->select('p')
        ->from(Part::class, 'p')
        ->leftJoin('p.writtenSection', 'ws')
        ->leftJoin('ws.mediaUploads', 'mu')
        ->where('p.id = :id')
        ->setParameter('id', $id)
        ->getQuery()
        ->getOneOrNullResult();

    if (!$part) {
        $this->logger->error('Part not found', ['part_id' => $id]);
        throw $this->createNotFoundException('Part not found');
    }

    $user = $this->getUser();
    if (!$user instanceof User) {
        $this->logger->error('User not authenticated', ['part_id' => $id]);
        throw $this->createAccessDeniedException('User not authenticated');
    }

    $hasAccess = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_TEACHER') ||
                 ($this->isGranted('ROLE_CLIENT') && $this->progressService->isPartUnlocked($part, $user));

    if (!$hasAccess) {
        $this->logger->error('Access denied to part', ['part_id' => $id, 'user_id' => $user->getId()]);
        throw $this->createAccessDeniedException('You do not have permission to access this part');
    }

    $course = $part->getCourse();
    $partQuiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['part' => $part]);
    $latestAttempt = null;
    $attemptCount = 0;
    $feedback = [];
    $incorrectQuestionIds = [];

    if ($partQuiz && $user) {
        $latestAttempt = $this->entityManager->getRepository(QuizAttempt::class)
            ->findOneBy(['quiz' => $partQuiz, 'user' => $user], ['takenAt' => 'DESC']);
        $attemptCount = $this->quizService->getAttemptCount($user, $partQuiz);
        if ($latestAttempt) {
            $feedback = $latestAttempt->getAnswers() ?? [];
            $incorrectQuestionIds = is_array($feedback) ? array_keys(array_filter($feedback, fn($item) => !$item['isCorrect'])) : [];
        }
    }

    return $this->render('part_details.html.twig', [
        'part' => $part,
        'course' => $course,
        'quiz' => $partQuiz,
        'geogebraMaterialId' => $part->getGeogebraMaterialId(),
        'tutorialContent' => $part->getTutorialContent(),
        'latest_attempt' => $latestAttempt,
        'attempt_count' => $attemptCount,
        'feedback' => $feedback,
        'incorrect_question_ids' => $incorrectQuestionIds,
        'can_attempt' => $partQuiz ? $this->quizService->canAttemptQuiz($user, $partQuiz) : false
    ]);
}

    #[Route('/quiz/{id}/submit', name: 'app_quiz_submit', methods: ['POST'])]
    public function submitQuiz(int $id, Request $request): Response
    {
        $quiz = $this->entityManager->getRepository(Quiz::class)->find($id);
        if (!$quiz) {
            $this->logger->error('Quiz not found', ['quiz_id' => $id]);
            throw $this->createNotFoundException('Quiz not found');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            $this->logger->error('User not authenticated', ['quiz_id' => $id]);
            throw $this->createAccessDeniedException('User not authenticated');
        }

        $token = $request->request->get('_token');
        if (!$token) {
            $this->logger->error('CSRF token missing in quiz submission', ['quiz_id' => $id, 'user_id' => $user->getId()]);
            $this->addFlash('error', 'CSRF token missing. Please try again.');
            return $this->redirectToRoute('app_part_details', ['id' => $quiz->getPart()->getId()]);
        }

        if (!$this->isCsrfTokenValid('quiz_submit' . $id, $token)) {
            $this->logger->error('Invalid CSRF token', [
                'quiz_id' => $id,
                'user_id' => $user->getId(),
                'submitted_token' => $token
            ]);
            $this->addFlash('error', 'Invalid CSRF token. Please refresh the page and try again.');
            return $this->redirectToRoute('app_part_details', ['id' => $quiz->getPart()->getId()]);
        }

        if (!$this->quizService->canAttemptQuiz($user, $quiz)) {
            $this->logger->info('Maximum quiz attempts reached', ['quiz_id' => $id, 'user_id' => $user->getId()]);
            $this->addFlash('error', 'You have reached the maximum number of attempts (3) for this quiz.');
            return $this->redirectToRoute('app_part_details', ['id' => $quiz->getPart()->getId()]);
        }

        $answers = $request->request->all()['answers'] ?? [];
        if (!is_array($answers)) {
            $this->logger->error('Invalid answers format', ['quiz_id' => $id, 'user_id' => $user->getId(), 'answers' => $answers]);
            $this->addFlash('error', 'Invalid answers format.');
            return $this->redirectToRoute('app_part_details', ['id' => $quiz->getPart()->getId()]);
        }

        $result = $this->quizService->evaluateQuiz($quiz, $answers, $user);

        if ($result['score'] >= 70 || $result['attemptNumber'] >= 3) {
            $this->progressService->markPartCompleted($user, $quiz->getPart());
            $this->logger->info('Part marked as completed', [
                'quiz_id' => $id,
                'user_id' => $user->getId(),
                'score' => $result['score'],
                'attempt_number' => $result['attemptNumber']
            ]);
        }

        $this->logger->info('Quiz attempt recorded', [
            'quiz_id' => $id,
            'user_id' => $user->getId(),
            'score' => $result['score'],
            'attempt_number' => $result['attemptNumber']
        ]);

        return $this->redirectToRoute('app_part_details', ['id' => $quiz->getPart()->getId()]);
    }
}