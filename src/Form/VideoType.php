<?php

namespace App\Form;

use App\Entity\Video;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VideoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('url', TextType::class, [
                'label' => 'Video URL',
                'attr' => ['class' => 'form-control', 'placeholder' => 'e.g., https://example.com/video.mp4'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Video Description',
                'attr' => ['class' => 'form-control', 'rows' => 3],
                'required' => false,
            ])
            ->add('duration', IntegerType::class, [
                'label' => 'Video Duration (seconds)',
                'attr' => ['class' => 'form-control', 'min' => 0],
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Video::class,
        ]);
    }
}