<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

class JsonTextareaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            fn ($value) => is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : ($value ?? ''),
            fn ($value) => is_string($value) && $value !== '' ? json_decode($value, true) : []
        ));
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }
}
