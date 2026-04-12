<?php

namespace App\Form\Type;

use App\Repository\GameEventTemplateRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DurationConfigType extends AbstractType
{
    public function __construct(
        private readonly GameEventTemplateRepository $templates,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $slugChoices = [];
        foreach ($this->templates->findAll() as $t) {
            $slugChoices[$t->getSlug()] = $t->getSlug();
        }
        ksort($slugChoices);

        $builder
            ->add('ticks', IntegerType::class, [
                'label' => 'Duration (ticks)',
                'attr'  => ['min' => 1],
                'help'  => 'Number of weekly ticks this event\'s effect persists. 1 tick = 1 in-game week.',
            ])
            ->add('completion_event_slug', ChoiceType::class, [
                'label'       => 'Completion Event',
                'choices'     => $slugChoices,
                'placeholder' => '— none —',
                'required'    => false,
                'help'        => 'Optional event that fires automatically when the duration expires.',
            ])
            ->add('tick_effect', StatChangeType::class, [
                'label'    => 'Tick Effect',
                'required' => false,
                'help'     => 'Optional stat change applied once per tick for the duration of this event.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
