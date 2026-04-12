<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TraitRequirementType extends AbstractType
{
    private const TRAITS = [
        'Determination'   => 'determination',
        'Professionalism' => 'professionalism',
        'Ambition'        => 'ambition',
        'Loyalty'         => 'loyalty',
        'Adaptability'    => 'adaptability',
        'Pressure'        => 'pressure',
        'Temperament'     => 'temperament',
        'Consistency'     => 'consistency',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('trait', ChoiceType::class, [
                'label'   => 'Trait',
                'choices' => self::TRAITS,
            ])
            ->add('min', NumberType::class, [
                'label'    => 'Min (1–20)',
                'required' => false,
                'attr'     => ['min' => 1, 'max' => 20],
            ])
            ->add('max', NumberType::class, [
                'label'    => 'Max (1–20)',
                'required' => false,
                'attr'     => ['min' => 1, 'max' => 20],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
