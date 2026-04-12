<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RelationshipEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label'   => 'Type',
                'choices' => ['Rivalry' => 'rivalry', 'Friendship' => 'friendship'],
            ])
            ->add('player_1_ref', TextType::class, [
                'label' => 'Player 1 Ref (e.g. player_1)',
                'attr'  => ['placeholder' => 'player_1'],
            ])
            ->add('player_2_ref', TextType::class, [
                'label' => 'Player 2 Ref (e.g. player_2)',
                'attr'  => ['placeholder' => 'player_2'],
            ])
            ->add('intensity', NumberType::class, [
                'label' => 'Intensity',
                'scale' => 0,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
