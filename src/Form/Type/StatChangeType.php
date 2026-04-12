<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class StatChangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('target', TextType::class, [
                'label' => 'Target',
                'attr'  => ['placeholder' => 'player_1'],
                'help'  => 'Who is affected. Use player_1, player_2, or squad_wide. For pair events the actor is player_1.',
            ])
            ->add('field', TextType::class, [
                'label' => 'Field',
                'attr'  => ['placeholder' => 'morale'],
                'help'  => 'The stat property to modify, e.g. morale, overallRating, condition.',
            ])
            ->add('operator', ChoiceType::class, [
                'label'   => 'Operator',
                'choices' => ['Add' => 'add', 'Subtract' => 'subtract', 'Set' => 'set'],
                'help'    => 'add / subtract adjusts relative to the current value. set forces an absolute value.',
            ])
            ->add('value', NumberType::class, [
                'label' => 'Value',
                'scale' => 0,
                'help'  => 'Integer amount to add, subtract, or assign.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
