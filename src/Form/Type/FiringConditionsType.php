<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FiringConditionsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
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
