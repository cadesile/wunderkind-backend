<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EventChoiceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('emoji', TextType::class, [
                'label' => 'Emoji',
                'help'  => 'Single emoji character displayed on the choice button in the app.',
            ])
            ->add('label', TextType::class, [
                'label' => 'Label',
                'help'  => 'Short button text shown to the manager, e.g. "Intervene" or "Ignore".',
            ])
            ->add('manager_shift', ManagerShiftType::class, [
                'label' => 'Manager Shift',
                'help'  => 'How selecting this choice adjusts the manager\'s personality ratings (positive or negative integers).',
            ])
            ->add('stat_changes', TextareaType::class, [
                'label'    => 'Stat Changes (JSON array)',
                'required' => false,
                'attr'     => ['rows' => 3, 'placeholder' => '[{"target":"player_1","field":"morale","operator":"add","value":5}]'],
                'help'     => 'JSON array of stat change objects. Full structured editing coming in a future update.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
