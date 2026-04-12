<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ManagerShiftType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('temperament', NumberType::class, ['label' => 'Temperament', 'scale' => 0])
            ->add('discipline',  NumberType::class, ['label' => 'Discipline',  'scale' => 0])
            ->add('ambition',    NumberType::class, ['label' => 'Ambition',    'scale' => 0]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
