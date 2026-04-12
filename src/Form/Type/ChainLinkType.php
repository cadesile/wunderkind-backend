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
            ])
            ->add('boostMultiplier', NumberType::class, [
                'label' => 'Boost Multiplier',
                'scale' => 2,
                'attr'  => ['min' => 1.0, 'step' => 0.5],
            ])
            ->add('windowWeeks', IntegerType::class, [
                'label' => 'Window (weeks)',
                'attr'  => ['min' => 1],
            ])
            ->add('note', TextType::class, [
                'label'    => 'Note (admin only)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
