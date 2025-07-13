<?php

namespace App\Controller;

use App\Entity\Quiz;
use App\Entity\QuizAttempt;
use App\Entity\User;
use App\Service\CourseProgressService;
use App\Service\QuizService;
use Doctrine\ORM\EntityManagerInterface;
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

    public function __construct(EntityManagerInterface $entityManager, QuizService $quizService, CourseProgressService $progressService)
    {
        $this->entityManager = $entityManager;
        $this->quizService = $quizService;
        $this->progressService = $progressService;
    }

    #[Route('/quiz/{id}/submit', name: 'app_quiz_submit', methods: ['POST'])]
    #[IsGranted('ROLE_CLIENT')]
    public function submit(int $id, Request $request): Response
    {
        $quiz = $this->entityManager->getRepository(Quiz::class)->find($id);
        if (!$quiz) {
            throw $this->createNotFoundException('Quiz not found');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User not authenticated');
        }

        $answers = $request->request->all('answers');
        $score = $this->quizService->evaluateQuiz($quiz, $answers);

        $attempt = new QuizAttempt();
        $attempt->setUser($user);
        $attempt->setQuiz($quiz);
        $attempt->setAnswers($answers);
        $attempt->setScore($score);
        $attempt->setTakenAt(new \DateTime());

        $this->entityManager->persist($attempt);
        if ($score >= 70) {
            $this->progressService->markPartCompleted($user, $quiz->getPart());
        }
        $this->entityManager->flush();

        return $this->redirectToRoute('app_course_details', ['id' => $quiz->getPart()->getCourse()->getId()]);
    }
}