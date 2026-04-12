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
            ])
            ->add('completion_event_slug', ChoiceType::class, [
                'label'       => 'Completion Event',
                'choices'     => $slugChoices,
                'placeholder' => '— select event —',
                'required'    => false,
            ])
            ->add('tick_effect', StatChangeType::class, [
                'label'    => 'Tick Effect (optional stat change each tick)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
