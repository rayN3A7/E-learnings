<?php

namespace App\Command;

use App\Entity\Course;
use App\Entity\Part;
use App\Entity\WrittenSection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:seed-database')]
class SeedDatabaseCommand extends Command
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $course = new Course();
        $course->setTitle('Introduction to Numerical Analysis');
        $course->setDescription('Learn numerical methods like bisection, Newton-Raphson, and interpolation.');
        $course->setCreatedAt(new \DateTime());
        // Note: createdBy requires a User entity. Comment out or set a user if needed.
        // $course->setCreatedBy($this->entityManager->getRepository(\App\Entity\User::class)->find(1));

        $part1 = new Part();
        $part1->setCourse($course);
        $part1->setTitle('Bisection Method');
        $part1->setDescription('The bisection method finds roots by bisecting intervals.');
        $part1->setOrder(1);

        $writtenSection1 = new WrittenSection();
        $writtenSection1->setPart($part1);
        $writtenSection1->setContent('The bisection method repeatedly bisects an interval and selects the subinterval where the function changes sign.');

        $part2 = new Part();
        $part2->setCourse($course);
        $part2->setTitle('Newton-Raphson Method');
        $part2->setDescription('The Newton-Raphson method uses derivatives to find roots.');
        $part2->setOrder(2);

        $writtenSection2 = new WrittenSection();
        $writtenSection2->setPart($part2);
        $writtenSection2->setContent('The Newton-Raphson method uses the formula x_{n+1} = x_n - f(x_n)/f\'(x_n) to find roots.');

        $this->entityManager->persist($course);
        $this->entityManager->persist($part1);
        $this->entityManager->persist($writtenSection1);
        $this->entityManager->persist($part2);
        $this->entityManager->persist($writtenSection2);
        $this->entityManager->flush();

        $output->writeln('Database seeded with sample course and parts.');

        return Command::SUCCESS;
    }
}