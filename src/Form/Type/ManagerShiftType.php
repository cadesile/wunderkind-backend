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
            ->add('temperament', NumberType::class, [
                'label' => 'Temperament',
                'scale' => 0,
                'help'  => 'Integer shift to the manager\'s temperament rating when this choice is made. Positive = calmer, negative = more volatile.',
            ])
            ->add('discipline', NumberType::class, [
                'label' => 'Discipline',
                'scale' => 0,
                'help'  => 'Integer shift to the manager\'s discipline rating. Positive = stricter, negative = more lenient.',
            ])
            ->add('ambition', NumberType::class, [
                'label' => 'Ambition',
                'scale' => 0,
                'help'  => 'Integer shift to the manager\'s ambition rating. Positive = more driven, negative = more content.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
