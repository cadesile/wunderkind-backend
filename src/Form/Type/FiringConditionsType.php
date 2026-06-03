<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FiringConditionsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ── Morale-based conditions (Feature 61) ─────────────────────────
            ->add('subject', ChoiceType::class, [
                'label'    => 'Subject',
                'choices'  => ['Player' => 'player', 'Staff' => 'staff'],
                'required' => false,
                'placeholder' => '— any —',
                'help'     => 'Scope the morale check to a specific subject type. Required when using morale_below / morale_above.',
            ])
            ->add('morale_below', NumberType::class, [
                'label'    => 'Morale Below',
                'required' => false,
                'help'     => 'Event fires if the subject\'s morale is below this value (0–100). Pairs with subject.',
            ])
            ->add('morale_above', NumberType::class, [
                'label'    => 'Morale Above',
                'required' => false,
                'help'     => 'Event fires if the subject\'s morale is above this value (0–100). Pairs with subject. Use both morale_above and morale_below for a range check.',
            ])
            ->add('staff_role', ChoiceType::class, [
                'label'    => 'Staff Role (optional)',
                'choices'  => [
                    'Coach'               => 'coach',
                    'Scout'               => 'scout',
                    'Director of Football'=> 'director_of_football',
                    'Manager'             => 'manager',
                ],
                'required'    => false,
                'placeholder' => '— any role —',
                'help'        => 'When subject is "staff", optionally filter by role.',
            ])
            // ── Squad / pair conditions ───────────────────────────────────────
            ->add('minSquadMorale', NumberType::class, [
                'label'    => 'Min Squad Morale',
                'required' => false,
                'help'     => 'Overall squad morale must be at or above this for the event to be eligible (0–100). Leave blank for no lower bound.',
            ])
            ->add('maxSquadMorale', NumberType::class, [
                'label'    => 'Max Squad Morale',
                'required' => false,
                'help'     => 'Overall squad morale must be at or below this for the event to be eligible (0–100). Leave blank for no upper bound.',
            ])
            ->add('minPairRelationship', NumberType::class, [
                'label'    => 'Min Pair Relationship',
                'required' => false,
                'help'     => 'Current relationship score between the two selected players must be at or above this (-100–100). Leave blank for no lower bound.',
            ])
            ->add('maxPairRelationship', NumberType::class, [
                'label'    => 'Max Pair Relationship',
                'required' => false,
                'help'     => 'Current relationship score between the two selected players must be at or below this (-100–100). Leave blank for no upper bound.',
            ])
            ->add('requiresCoLocation', CheckboxType::class, [
                'label'    => 'Requires Co-Location',
                'required' => false,
                'help'     => 'When checked, both players must share the same training coach for this event to fire.',
            ])
            ->add('actorTraitRequirements', CollectionType::class, [
                'label'        => 'Actor Trait Requirements',
                'entry_type'   => TraitRequirementType::class,
                'allow_add'    => true,
                'allow_delete' => true,
                'required'     => false,
                'by_reference' => false,
                'help'         => 'Personality trait conditions the primary actor (player_1) must satisfy.',
            ])
            ->add('subjectTraitRequirements', CollectionType::class, [
                'label'        => 'Subject Trait Requirements',
                'entry_type'   => TraitRequirementType::class,
                'allow_add'    => true,
                'allow_delete' => true,
                'required'     => false,
                'by_reference' => false,
                'help'         => 'Personality trait conditions the secondary actor (player_2) must satisfy.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'    => null,
            // EasyAdmin injects CollectionType options when the bound property is a PHP array.
            // Declare them as accepted (and ignored) so Symfony doesn't throw.
            'allow_add'     => false,
            'allow_delete'  => false,
            'delete_empty'  => false,
            'entry_options' => [],
            'entry_type'    => null,
        ]);
        $resolver->setAllowedTypes('entry_type', ['null', 'string']);
        $resolver->setAllowedTypes('entry_options', 'array');
    }
}
