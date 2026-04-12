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
                'help'    => 'rivalry creates a negative dynamic between the players; friendship creates a positive one.',
            ])
            ->add('player_1_ref', TextType::class, [
                'label' => 'Player 1 Ref',
                'attr'  => ['placeholder' => 'player_1'],
                'help'  => 'Reference token for the first player. Use player_1 or player_2 (the actors selected by selection logic).',
            ])
            ->add('player_2_ref', TextType::class, [
                'label' => 'Player 2 Ref',
                'attr'  => ['placeholder' => 'player_2'],
                'help'  => 'Reference token for the second player. Use player_1 or player_2.',
            ])
            ->add('intensity', NumberType::class, [
                'label' => 'Intensity',
                'scale' => 0,
                'help'  => 'Relationship strength on a 1–100 scale. Higher values produce stronger in-game behavioural effects.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
