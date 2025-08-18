<?php

namespace App\Form;

use App\Entity\Part;
use App\Entity\Video;
use App\Entity\WrittenSection;
use App\Form\ManualQuizType;
use App\Form\VideoType;
use App\Form\WrittenSectionType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

class PartType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Part Title',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Enter part title'],
                'required' => true,
            ])
            ->add('partOrder', IntegerType::class, [
                'label' => 'Part Order',
                'attr' => ['class' => 'form-control'],
                'required' => true,
            ])
            ->add('duration', IntegerType::class, [
                'label' => 'Duration (minutes)',
                'attr' => ['class' => 'form-control'],
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Part Description',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Describe this part'],
                'required' => false,
            ])
            ->add('video', VideoType::class, [
                'label' => 'Video Content',
                'required' => false,
            ])
            ->add('geogebraMaterialId', TextType::class, [
                'label' => 'GeoGebra Material ID',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Enter GeoGebra material ID'],
                'required' => false,
            ])
            ->add('tutorialContent', TextareaType::class, [
                'label' => 'Tutorial Content',
                'attr' => ['class' => 'form-control rich-text-editor', 'placeholder' => 'Enter tutorial content'],
                'required' => false,
            ])
            ->add('writtenSection', WrittenSectionType::class, [
                'label' => 'Written Content',
                'required' => false,
            ])
            ->add('quizMode', ChoiceType::class, [
                'label' => 'Quiz Mode',
                'choices' => [
                    'Manual' => 'manual',
                    'AI-Generated' => 'ai',
                ],
                'attr' => ['class' => 'form-control quiz-mode'],
                'required' => true,
            ])
            ->add('quiz', ManualQuizType::class, [
                'label' => 'Quiz',
                'required' => false,
            ]);

        // Set initial quizMode and ensure quiz data is properly loaded
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $part = $event->getData();
            if ($part instanceof Part && $part->getQuiz()) {
                $quiz = $part->getQuiz();
                $form->get('quizMode')->setData($quiz->isGeneratedByAI() ? 'ai' : 'manual');
                $form->get('quiz')->setData($quiz);
            } else {
                $form->get('quizMode')->setData('ai'); // Default to AI-generated
            }
        });

        // Preserve AI-generated quiz data on submission or set generatedByAI to false for manual
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $form = $event->getForm();
            $part = $form->getData();
            if (is_array($data) && isset($data['quizMode'])) {
                if ($data['quizMode'] === 'ai' && $part instanceof Part && $part->getQuiz()) {
                    $existingQuiz = $part->getQuiz();
                    $data['quiz'] = [
                        'title' => $existingQuiz->getTitle(),
                        'generatedByAI' => $existingQuiz->isGeneratedByAI() ? '1' : '0',
                        'questions' => array_map(function ($question) {
                            return [
                                'text' => $question->getText(),
                                'type' => strtolower($question->getType()),
                                'options' => strtolower($question->getType()) === 'mcq' ? ($question->getOptions() ?? []) : [],
                                'correctAnswer' => $question->getCorrectAnswer(),
                                'generatedByAI' => $question->isGeneratedByAI() ? '1' : '0',
                            ];
                        }, $existingQuiz->getQuestions()->toArray()),
                    ];
                } elseif ($data['quizMode'] === 'manual' && isset($data['quiz'])) {
                    $data['quiz']['generatedByAI'] = '0';
                }
                $event->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Part::class,
        ]);
    }
}