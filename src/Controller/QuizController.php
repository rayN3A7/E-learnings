<?php

namespace App\Controller;

use App\Entity\Quiz;
use App\Entity\QuizAttempt;
use App\Entity\User;
use App\Service\CourseProgressService;
use App\Service\QuizService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class QuizController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private QuizService $quizService;
    private CourseProgressService $progressService;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        QuizService $quizService,
        CourseProgressService $progressService,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->quizService = $quizService;
        $this->progressService = $progressService;
        $this->logger = $logger;
    }

    #[Route('/quiz/{id}/submit', name: 'app_quiz_submit', methods: ['POST'])]
    #[IsGranted('ROLE_CLIENT')]
    public function submit(int $id, Request $request): Response
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

$attempt = new QuizAttempt();
$attempt->setUser($user);
$attempt->setQuiz($quiz);
$attempt->setAnswers($result['feedback'] ?? []); // Ensure feedback is an array
$attempt->setScore($result['score']);
$attempt->setTakenAt(new \DateTime());
$attempt->setAttemptNumber($result['attemptNumber']);

        $this->entityManager->persist($attempt);
        if ($result['score'] >= 70 || $result['attemptNumber'] >= 3) {
            $this->progressService->markPartCompleted($user, $quiz->getPart());
            $this->logger->info('Part marked as completed', [
                'quiz_id' => $id,
                'user_id' => $user->getId(),
                'score' => $result['score'],
                'attempt_number' => $result['attemptNumber']
            ]);
        }
        $this->entityManager->flush();

        $this->logger->info('Quiz attempt recorded', [
            'quiz_id' => $id,
            'user_id' => $user->getId(),
            'score' => $result['score'],
            'attempt_number' => $result['attemptNumber']
        ]);

        return $this->redirectToRoute('app_part_details', ['id' => $quiz->getPart()->getId()]);
    }
}