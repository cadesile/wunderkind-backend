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
            ])
            ->add('active_only', CheckboxType::class, [
                'label'    => 'Active only',
                'required' => false,
            ])
            ->add('min_age', IntegerType::class, [
                'label'    => 'Min Age',
                'required' => false,
            ])
            ->add('max_age', IntegerType::class, [
                'label'    => 'Max Age',
                'required' => false,
            ])
            ->add('max_level', IntegerType::class, [
                'label'    => 'Max Level',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
