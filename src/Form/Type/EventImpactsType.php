<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EventImpactsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('selection_logic', SelectionLogicType::class, [
                'label'    => 'Selection Logic',
                'required' => false,
            ])
            ->add('stat_changes', CollectionType::class, [
                'label'        => 'Stat Changes',
                'entry_type'   => StatChangeType::class,
                'allow_add'    => true,
                'allow_delete' => true,
                'required'     => false,
                'by_reference' => false,
            ])
            ->add('relationships', CollectionType::class, [
                'label'        => 'Relationships',
                'entry_type'   => RelationshipEntryType::class,
                'allow_add'    => true,
                'allow_delete' => true,
                'required'     => false,
                'by_reference' => false,
            ])
            ->add('duration_config', DurationConfigType::class, [
                'label'    => 'Duration Config',
                'required' => false,
            ])
            ->add('choices', CollectionType::class, [
                'label'        => 'Choices',
                'entry_type'   => EventChoiceType::class,
                'allow_add'    => true,
                'allow_delete' => true,
                'required'     => false,
                'by_reference' => false,
            ]);

        // Strip null/empty sub-objects so the entity stays clean
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data)) return;
            foreach (['selection_logic', 'duration_config'] as $key) {
                if (isset($data[$key]) && array_filter($data[$key]) === []) {
                    $data[$key] = null;
                }
            }
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
