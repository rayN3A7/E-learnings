<?php

namespace App\Form;

use App\Entity\Course;
use App\Entity\Quiz;
use App\Form\ManualQuizType;
use App\Form\PartType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CourseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Course Title',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Enter course title'],
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Course Description',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Describe your course'],
                'required' => false,
            ])
            ->add('image', FileType::class, [
                'label' => 'Course Image',
                'mapped' => false,
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('parts', CollectionType::class, [
                'entry_type' => PartType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => 'Course Parts',
                'required' => false,
                'attr' => ['class' => 'parts-collection'],
                'prototype' => true,
            ])
            ->add('quizMode', ChoiceType::class, [
                'label' => 'Final Quiz Mode',
                'choices' => [
                    'Manual' => 'manual',
                    'AI-Generated' => 'ai',
                ],
                'mapped' => false,
                'attr' => ['class' => 'form-control quiz-mode'],
                'required' => true,
            ])
            ->add('finalQuiz', ManualQuizType::class, [
                'label' => 'Final Quiz',
                'required' => false,
                'data_class' => Quiz::class,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Course::class,
        ]);
    }
}