<?php

namespace App\Form;

use App\Entity\Question;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\CallbackTransformer;

class QuestionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('text', TextType::class, [
                'label' => 'Question Text',
                'attr' => ['class' => 'form-control rich-text-editor'],
                'required' => true,
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Question Type',
                'choices' => [
                    'Multiple Choice' => 'mcq',
                    'Numeric' => 'numeric',
                ],
                'attr' => ['class' => 'form-control question-type'],
                'required' => true,
            ])
            ->add('correctAnswer', TextType::class, [
                'label' => 'Correct Answer',
                'attr' => ['class' => 'form-control', 'placeholder' => 'e.g., A or 42'],
                'required' => true,
            ])
            ->add('generatedByAI', HiddenType::class, [
                'label' => false,
                'required' => true,
            ]);

        // Add data transformer for generatedByAI to ensure boolean values
        $builder->get('generatedByAI')->addModelTransformer(new CallbackTransformer(
            // Transform boolean to string for form rendering
            function ($generatedByAI) {
                return $generatedByAI ? '1' : '0';
            },
            // Transform string to boolean for entity
            function ($generatedByAI) {
                return $generatedByAI === '1' || $generatedByAI === true;
            }
        ));

        // Add the options field initially
        $builder->add('options', CollectionType::class, [
            'entry_type' => TextType::class,
            'entry_options' => [
                'label' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Option'],
            ],
            'allow_add' => true,
            'allow_delete' => true,
            'prototype' => true,
            'required' => false,
            'by_reference' => false,
            'attr' => ['class' => 'options-collection'],
            'label' => 'Options (for MCQ)',
        ]);

        // Event listener to ensure 4 options for MCQ on form submission
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            if (is_array($data) && isset($data['type']) && $data['type'] === 'mcq') {
                $options = $data['options'] ?? [];
                $optionsCount = count($options);

                // Ensure exactly 4 options, filling with empty strings if needed
                if ($optionsCount < 4) {
                    $data['options'] = array_merge($options, array_fill(0, 4 - $optionsCount, ''));
                } elseif ($optionsCount > 4) {
                    $data['options'] = array_slice($options, 0, 4);
                }
                $event->setData($data);
            }
        });

        // Event listener to clear options for numeric type
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $question = $event->getData();
            $type = $question instanceof Question ? $question->getType() : null;

            $optionsConfig = [
                'entry_type' => TextType::class,
                'entry_options' => [
                    'label' => false,
                    'attr' => ['class' => 'form-control', 'placeholder' => 'Option'],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
                'by_reference' => false,
                'attr' => ['class' => 'options-collection'],
                'label' => 'Options (for MCQ)',
            ];

            if ($type === 'mcq') {
                $optionsConfig['required'] = true;
                if ($question instanceof Question && count($question->getOptions()) < 4) {
                    $question->setOptions(array_merge($question->getOptions(), array_fill(0, 4 - count($question->getOptions()), '')));
                }
            } else {
                $optionsConfig['required'] = false;
                if ($question instanceof Question) {
                    $question->setOptions([]);
                }
            }

            $form->add('options', CollectionType::class, $optionsConfig);
        });

        // Ensure options are cleared for numeric questions on submit
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $question = $event->getData();
            if ($question instanceof Question && $question->getType() === 'numeric') {
                $question->setOptions([]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Question::class,
            'label' => false,
        ]);
    }
}