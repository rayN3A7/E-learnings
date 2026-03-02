<?php

namespace App\Form;

use App\Entity\Course;
use App\Entity\Quiz;
use App\Form\ManualQuizType;
use App\Form\PartType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\File;

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
                'constraints' => [
                    new File(
                        maxSize: '1024k',
                        mimeTypes: ['image/*'],
                        mimeTypesMessage: 'Please upload a valid image'
                    ),
                ],
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
            ->add('regenerateFinalQuiz', CheckboxType::class, [
                'label' => 'Regenerate AI-Generated Quiz (if unsatisfied with current one)',
                'mapped' => false,
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('finalQuiz', ManualQuizType::class, [
                'label' => 'Final Quiz',
                'required' => false,
                'validation_groups' => function (FormInterface $form) {
                    $mode = $form->getParent()->get('quizMode')->getData();
                    return $mode === 'manual' ? ['Default'] : [];
                },
                'data_class' => Quiz::class,
            ]);

        // Set initial quizMode and ensure quiz data is properly loaded
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $course = $event->getData();
            if ($course instanceof Course && $course->getFinalQuiz()) {
                $quiz = $course->getFinalQuiz();
                $form->get('quizMode')->setData($quiz->isGeneratedByAI() ? 'ai' : 'manual');
                $form->get('finalQuiz')->setData($quiz);
            } else {
                $form->get('quizMode')->setData('ai'); // Default to AI-generated
            }
        });

        // Clear quiz data if mode is 'ai' and reset manual quiz fields
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            if (is_array($data)) {
                if (isset($data['parts'])) {
                    foreach ($data['parts'] as &$partData) {
                        if (isset($partData['quizMode']) && $partData['quizMode'] === 'ai') {
                            $partData['quiz'] = null; // Clear manual quiz data
                        }
                    }
                }
                if (isset($data['quizMode']) && $data['quizMode'] === 'ai') {
                    $data['finalQuiz'] = null; // Clear final quiz data
                }
                $event->setData($data);
            }
        });

        // Preserve AI-generated quiz data or set generatedByAI for manual
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $form = $event->getForm();
            $course = $form->getData();
            if (is_array($data) && isset($data['quizMode'])) {
                if ($data['quizMode'] === 'ai' && !($data['regenerateFinalQuiz'] ?? false) && $course instanceof Course && $course->getFinalQuiz()) {
                    $existingQuiz = $course->getFinalQuiz();
                    $data['finalQuiz'] = [
                        'title' => $existingQuiz->getTitle(),
                        'generatedByAI' => $existingQuiz->isGeneratedByAI() ? '1' : '0',
                        'questions' => array_map(function ($question) {
                            return [
                                'text' => $question->getText(),
                                'type' => strtolower($question->getType()),
                                'options' => strtolower($question->getType()) === 'mcq' ? ($question->getOptions() ?? []) : [],
                                'correctAnswer' => $question->getCorrectAnswer(),
                                'generatedByAI' => $question->isGeneratedByAI() ? '1' : '0',
                                'explanation' => $question->getExplanation(),
                                'hint' => $question->getHint(),
                            ];
                        }, $existingQuiz->getQuestions()->toArray()),
                    ];
                } elseif ($data['quizMode'] === 'manual' && isset($data['finalQuiz'])) {
                    $data['finalQuiz']['generatedByAI'] = '0';
                }
                $event->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Course::class,
        ]);
    }
}