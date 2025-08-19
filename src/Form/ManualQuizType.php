<?php

namespace App\Form;

use App\Entity\Quiz;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormError;

class ManualQuizType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Quiz Title',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Enter quiz title'
                ],
                'required' => true,
            ])
            ->add('generatedByAI', HiddenType::class, [
                'mapped' => true,
                'required' => false,
            ])
            ->add('questions', CollectionType::class, [
                'entry_type' => QuestionType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => 'Questions',
                'required' => false,
                'attr' => ['class' => 'question-collection', 'data-max-questions' => 10],
                'entry_options' => ['label' => false],
                'prototype' => true,
            ]);

        // Initialize generatedByAI based on quiz data
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $quiz = $event->getData();
            if ($quiz instanceof Quiz) {
                $form->get('generatedByAI')->setData($quiz->isGeneratedByAI() ? '1' : '0');
                // Ensure questions are initialized
                $form->get('questions')->setData($quiz->getQuestions());
            }
        });

        // Preserve generatedByAI on submission
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $quiz = $event->getForm()->getData();
            if (is_array($data) && $quiz instanceof Quiz) {
                $data['generatedByAI'] = $quiz->isGeneratedByAI() ? '1' : '0';
                $event->setData($data);
            }
        });

        // Validate quiz has exactly 10 questions only if questions are provided (skip for AI/empty)
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $quiz = $form->getData();
            if ($quiz instanceof Quiz && count($quiz->getQuestions()) > 0 && count($quiz->getQuestions()) !== 10) {
                $form->addError(new FormError('Quiz must have exactly 10 questions.'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Quiz::class,
        ]);
    }
}