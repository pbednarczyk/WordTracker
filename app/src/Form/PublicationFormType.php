<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\PublicationType as PublicationTypeEnum;
use App\Form\Model\PublicationInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PublicationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
            ])
            ->add('author', TextType::class, [
                'label' => 'Author',
                'required' => false,
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type',
                'choices' => PublicationTypeEnum::cases(),
                'choice_label' => static fn (PublicationTypeEnum $type): string => ucfirst(strtolower($type->value)),
                'choice_value' => static fn (?PublicationTypeEnum $type): string => $type?->value ?? '',
            ])
            ->add('language', TextType::class, [
                'label' => 'Language',
            ])
            ->add('rawText', TextareaType::class, [
                'label' => 'Text',
                'attr' => [
                    'rows' => 16,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PublicationInput::class,
        ]);
    }
}
