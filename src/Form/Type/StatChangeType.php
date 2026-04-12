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
                'label' => 'Target (e.g. player_1, squad_wide)',
                'attr'  => ['placeholder' => 'player_1'],
            ])
            ->add('field', TextType::class, [
                'label' => 'Field (e.g. morale, overallRating)',
                'attr'  => ['placeholder' => 'morale'],
            ])
            ->add('operator', ChoiceType::class, [
                'label'   => 'Operator',
                'choices' => ['Add' => 'add', 'Subtract' => 'subtract', 'Set' => 'set'],
            ])
            ->add('value', NumberType::class, [
                'label' => 'Value',
                'scale' => 0,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
