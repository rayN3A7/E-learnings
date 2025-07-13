<?php


// src/Controller/MainController.php
namespace App\Controller;

use App\Entity\Course;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MainController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        $courses = $this->entityManager->getRepository(Course::class)->findBy([], [], 3);
        return $this->render('base.html.twig', [
            'courses' => $courses,
        ]);
    }

    #[Route('/about', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('about.html.twig');
    }

    #[Route('/courses', name: 'app_courses')]
    public function courses(): Response
    {
        $courses = $this->entityManager->getRepository(Course::class)->findAll();
        return $this->render('courses.html.twig', [
            'courses' => $courses,
        ]);
    }
}