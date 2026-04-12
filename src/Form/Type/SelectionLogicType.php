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
                'help'    => 'What kind of entity the engine draws actors from for this event.',
            ])
            ->add('count', IntegerType::class, [
                'label' => 'Count',
                'attr'  => ['min' => 1],
                'help'  => 'How many actors to select. For pair events this is always 2 — set to 2 to be explicit.',
            ])
            ->add('filter', SelectionLogicFilterType::class, [
                'label'    => 'Filter',
                'required' => false,
                'help'     => 'Optional constraints to narrow which actors are eligible.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
