<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class JsonTextareaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            fn ($value) => is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : ($value ?? ''),
            fn ($value) => is_string($value) && $value !== '' ? json_decode($value, true) : []
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // EasyAdmin auto-configures a custom form type bound to a Doctrine `json` column as a
        // collection and injects these options, which would otherwise throw
        // "The options ... do not exist". See CLAUDE.md, EasyAdmin gotchas.
        $resolver->setDefined([
            'allow_add',
            'allow_delete',
            'delete_empty',
            'entry_options',
            'entry_type',
        ]);
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }
}
