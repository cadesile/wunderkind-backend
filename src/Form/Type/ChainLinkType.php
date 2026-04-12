<?php

namespace App\Form\Type;

use App\Repository\GameEventTemplateRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ChainLinkType extends AbstractType
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
            ->add('nextEventSlug', ChoiceType::class, [
                'label'       => 'Next Event Slug',
                'choices'     => $slugChoices,
                'placeholder' => '— select event —',
                'help'        => 'The follow-up event whose weight will be boosted for the same player pair after this event fires.',
            ])
            ->add('boostMultiplier', NumberType::class, [
                'label' => 'Boost Multiplier',
                'scale' => 2,
                'attr'  => ['min' => 1.0, 'step' => 0.5],
                'help'  => 'Weight multiplier applied to the next event for this player pair. e.g. 2.0 = twice as likely to fire within the window.',
            ])
            ->add('windowWeeks', IntegerType::class, [
                'label' => 'Window (weeks)',
                'attr'  => ['min' => 1],
                'help'  => 'How many game weeks the boost stays active after this event fires.',
            ])
            ->add('note', TextType::class, [
                'label'    => 'Note (admin only)',
                'required' => false,
                'help'     => 'Internal reminder — stripped from the API response, never sent to the app.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
