<?php

namespace App\Form;

use App\Entity\WrittenSection;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WrittenSectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('content', TextareaType::class, [
                'label' => 'Written Content',
                'attr' => ['class' => 'form-control', 'rows' => 5],
                'required' => false,
            ])
            ->add('mediaUrls', CollectionType::class, [
                'entry_type' => TextType::class,
                'entry_options' => [
                    'label' => 'Media URL',
                    'attr' => ['class' => 'form-control media-url', 'placeholder' => 'e.g., https://example.com/image.jpg'],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WrittenSection::class,
        ]);
    }
}