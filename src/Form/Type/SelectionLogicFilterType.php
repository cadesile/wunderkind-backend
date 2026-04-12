<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SelectionLogicFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('position', ChoiceType::class, [
                'label'       => 'Position',
                'required'    => false,
                'placeholder' => '— any —',
                'choices'     => ['GK' => 'GK', 'DEF' => 'DEF', 'MID' => 'MID', 'FWD' => 'FWD'],
                'help'        => 'Restrict eligibility to players of this position. Leave blank for any.',
            ])
            ->add('active_only', CheckboxType::class, [
                'label'    => 'Active only',
                'required' => false,
                'help'     => 'When checked, only players with status = active are eligible (excludes loaned, injured, etc.).',
            ])
            ->add('min_age', IntegerType::class, [
                'label'    => 'Min Age',
                'required' => false,
                'help'     => 'Minimum player age in years. Leave blank for no lower bound.',
            ])
            ->add('max_age', IntegerType::class, [
                'label'    => 'Max Age',
                'required' => false,
                'help'     => 'Maximum player age in years. Leave blank for no upper bound.',
            ])
            ->add('max_level', IntegerType::class, [
                'label'    => 'Max Level',
                'required' => false,
                'help'     => 'Only include players whose overall level is at or below this value. Leave blank for no cap.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
