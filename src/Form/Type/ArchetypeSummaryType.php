<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Read-only display of the positive/negative archetypes a personality matrix resolves to.
 *
 * Renders nothing editable — it is a widget purely so it can sit inside the EasyAdmin
 * Personality fieldset on the edit form (EasyAdmin renders form pages as form widgets,
 * so a template-only field would not appear there). Always `mapped => false`.
 * See templates/admin/form/archetype_summary_theme.html.twig for the block.
 */
final class ArchetypeSummaryType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'mapped'    => false,
            'required'  => false,
            'label'     => false,
            'catalogue' => [],
            'resolved'  => ['positive' => null, 'negative' => null],
        ]);

        $resolver->setAllowedTypes('catalogue', 'array');
        $resolver->setAllowedTypes('resolved', 'array');
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['catalogue'] = $options['catalogue'];
        $view->vars['resolved']  = $options['resolved'];
    }
}
