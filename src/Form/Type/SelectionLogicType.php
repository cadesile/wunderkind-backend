<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SelectionLogicType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('target_type', ChoiceType::class, [
                'label'   => 'Target Type',
                'choices' => [
                    'Player'     => 'player',
                    'Facility'   => 'facility',
                    'Staff'      => 'staff',
                    'Squad Wide' => 'squad_wide',
                ],
            ])
            ->add('count', IntegerType::class, [
                'label' => 'Count',
                'attr'  => ['min' => 1],
            ])
            ->add('filter', SelectionLogicFilterType::class, [
                'label'    => 'Filter',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
