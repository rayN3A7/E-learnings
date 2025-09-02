<?php

namespace App\Form;

use App\Entity\Question;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\CallbackTransformer;

class QuestionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('text', TextType::class, [
                'label' => 'Question Text',
                'attr' => [
                    'class' => 'form-control rich-text-editor',
                    'placeholder' => 'Enter question text (e.g., What is 2 + 2?)'
                ],
                'required' => true,
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Question Type',
                'choices' => [
                    'Multiple Choice' => 'mcq',
                    'Numeric' => 'numeric',
                    'Text' => 'text',
                ],
                'choice_value' => function ($value) {
                    return $value;
                },
                'attr' => ['class' => 'form-control question-type'],
                'required' => true,
            ])
            ->add('correctAnswer', TextType::class, [
                'label' => 'Correct Answer',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'e.g., A or 42 or exact text'
                ],
                'required' => true,
            ])
            ->add('generatedByAI', HiddenType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('options', CollectionType::class, [
                'entry_type' => TextType::class,
                'entry_options' => [
                    'label' => false,
                    'attr' => [
                        'class' => 'form-control',
                        'placeholder' => 'Enter option (e.g., Option A)'
                    ],
                ],
                'allow_add' => false,
                'allow_delete' => false,
                'prototype' => false,
                'required' => false,
                'by_reference' => false,
                'attr' => ['class' => 'options-collection'],
                'label' => 'Options (for MCQ)',
            ])
            ->add('explanation', TextareaType::class, [
                'label' => 'Explanation (optional)',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Provide a step-by-step explanation for the correct answer and common mistakes',
                    'rows' => 4,
                ],
                'required' => false,
            ])
            ->add('hint', TextareaType::class, [
                'label' => 'Hint (optional)',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Provide a helpful hint for the question (shown on hover)',
                    'rows' => 3,
                ],
                'required' => false,
            ]);

        // Transform generatedByAI to ensure boolean values
        $builder->get('generatedByAI')->addModelTransformer(new CallbackTransformer(
            function ($generatedByAI) {
                return $generatedByAI ? '1' : '0';
            },
            function ($generatedByAI) {
                return $generatedByAI === '1' || $generatedByAI === true;
            }
        ));

        // Initialize question type and options
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $question = $event->getData();
            $type = $question instanceof Question ? strtolower($question->getType()) : 'mcq';

            $optionsConfig = [
                'entry_type' => TextType::class,
                'entry_options' => [
                    'label' => false,
                    'attr' => [
                        'class' => 'form-control',
                        'placeholder' => 'Enter option (e.g., Option A)'
                    ],
                ],
                'allow_add' => false,
                'allow_delete' => false,
                'prototype' => false,
                'by_reference' => false,
                'attr' => ['class' => 'options-collection'],
                'label' => 'Options (for MCQ)',
            ];

            if ($type === 'mcq') {
                $optionsConfig['required'] = true;
                $options = $question instanceof Question ? $question->getOptions() ?? [] : [];
                while (count($options) < 4) {
                    $options[] = '';
                }
                $options = array_slice($options, 0, 4);
                $optionsConfig['data'] = $options;
            } else {
                $optionsConfig['required'] = false;
                $optionsConfig['data'] = [];
            }

            $form->add('options', CollectionType::class, $optionsConfig);
            if ($question instanceof Question) {
                $form->get('type')->setData($type);
                $form->get('generatedByAI')->setData($question->isGeneratedByAI() ? '1' : '0');
                $form->get('explanation')->setData($question->getExplanation());
                $form->get('hint')->setData($question->getHint());
            }
        });

        // Normalize type to lowercase on submission and handle null options
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            if (is_array($data) && isset($data['type'])) {
                $data['type'] = strtolower($data['type']);
                if (!isset($data['options'])) {
                    $data['options'] = [];
                }
                if ($data['type'] !== 'mcq') {
                    $data['options'] = []; // Clear options for non-MCQ
                } else {
                    // Trim and filter empty options, then pad with defaults if less than 4
                    $data['options'] = array_values(array_filter(array_map('trim', $data['options']), fn($o) => $o !== ''));
                    $defaultOptions = ['Option A', 'Option B', 'Option C', 'Option D'];
                    while (count($data['options']) < 4) {
                        $data['options'][] = $defaultOptions[count($data['options'])];
                    }
                    $data['options'] = array_slice($data['options'], 0, 4);
                }
                $event->setData($data);
            }
        });

        // Validate question based on type
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $question = $form->getData();
            if ($question instanceof Question) {
                $type = strtolower($question->getType());
                if ($type === 'mcq') {
                    $options = $question->getOptions() ?? [];
                    if (count($options) !== 4) {
                        $form->get('options')->addError(new FormError('MCQ questions must have exactly 4 options.'));
                    } else {
                        foreach ($options as $option) {
                            if (trim($option) === '') {
                                $form->get('options')->addError(new FormError('All options must be filled for MCQ questions.'));
                                break;
                            }
                        }
                    }
                } elseif ($type === 'numeric') {
                    if (!is_numeric($question->getCorrectAnswer())) {
                        $form->get('correctAnswer')->addError(new FormError('Correct answer must be numeric for numeric questions.'));
                    }
                } elseif ($type === 'text') {
                    // No specific validation for text questions
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Question::class,
        ]);
    }
}