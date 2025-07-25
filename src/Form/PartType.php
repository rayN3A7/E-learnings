<?php

namespace App\Form;

use App\Entity\Part;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class PartType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Part Title',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Part Description',
                'attr' => ['class' => 'form-control', 'rows' => 4],
                'required' => false,
            ])
            ->add('duration', IntegerType::class, [
                'label' => 'Duration (minutes)',
                'attr' => ['class' => 'form-control', 'min' => 0],
                'required' => false,
            ])
            ->add('partOrder', IntegerType::class, [
                'label' => 'Order',
                'attr' => ['class' => 'form-control', 'min' => 1],
                'required' => false,
            ])
            ->add('videoFile', FileType::class, [
                'label' => 'Video File (MP4, max 100MB)',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File([
                        'maxSize' => '100M',
                        'mimeTypes' => ['video/mp4'],
                        'mimeTypesMessage' => 'Please upload a valid MP4 video',
                    ]),
                ],
                'attr' => ['class' => 'form-control'],
            ])
            ->add('videoDescription', TextareaType::class, [
                'label' => 'Video Description',
                'mapped' => false,
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 4],
            ])
            ->add('writtenSection', WrittenSectionType::class, [
                'label' => 'Written Section',
                'required' => false,
            ])
            ->add('geogebraMaterialId', TextType::class, [
                'label' => 'GeoGebra Material ID',
                'required' => false,
                'attr' => ['class' => 'form-control'],
                'help' => 'Enter the GeoGebra material ID (e.g., g12345678) to embed an interactive applet.'
            ])
            ->add('tutorialContent', TextareaType::class, [
                'label' => 'Tutorial Content (HTML/Markdown)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 4],
                'help' => 'Provide instructions for using the GeoGebra applet.'
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Part::class,
        ]);
    }
}