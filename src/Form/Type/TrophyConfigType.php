<?php
namespace App\Form\Type;

use App\Enum\Appearance\TrophyFinish;
use App\Enum\Appearance\TrophyStyle;
use App\Service\TrophyConfigFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Compound form for editing a League's or CompetitionTemplate's `trophy` config
 * — the 16-bit trophy sprite's own 5-key TrophyConfig, stored verbatim in a
 * Doctrine `json` column. Sibling of KitIdentityType and AppearanceType, and
 * rendered by the same style of custom widget (see
 * templates/admin/field/trophy_config.html.twig).
 *
 * The stored shape is flat, so unlike KitIdentityType there is no prefixing or
 * nesting to do — the DataMapper exists for two other reasons:
 *
 *  1. NULL IS A REAL VALUE. An empty `style` means "this division/cup awards no
 *     trophy" and maps the whole field back to null. Several call sites depend
 *     on that staying null rather than being defaulted into a visible trophy:
 *     the honours board lists a title with no artwork, and GameConfig's
 *     league-tier defaults ship with none set. A plain nested form would write
 *     a complete config the moment the page was saved.
 *  2. Everything else goes through TrophyConfigFactory, the same validator the
 *     API import and the quick-edit screens use, so a tampered POST falls back
 *     per key instead of storing something the sprite cannot draw.
 *
 * `ribbonPrimary`/`ribbonSecondary` reuse App\Enum\Appearance\KitColor — the
 * sprite's own ribbon palette IS the shared 12-colour kit palette, so a trophy's
 * ribbons can never drift from the kit vocabulary a club's colours come from.
 */
final class TrophyConfigType extends AbstractType implements DataMapperInterface
{
    /** Rendered as the empty choice on `style`; selecting it clears the whole config. */
    public const NO_TROPHY = '';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('style', ChoiceType::class, [
                'choices'     => $this->styleChoices(),
                'required'    => false,
                'placeholder' => 'No trophy',
            ])
            ->add('finish', ChoiceType::class, [
                'choices' => $this->finishChoices(),
            ])
            ->add('ribbons', CheckboxType::class, ['required' => false])
            ->add('ribbonPrimary', ChoiceType::class, [
                'choices' => $this->ribbonColourChoices(),
            ])
            ->add('ribbonSecondary', ChoiceType::class, [
                'choices' => $this->ribbonColourChoices(),
            ])
            ->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);

        // Same EasyAdmin CollectionType-injection tolerance as AppearanceType and
        // KitIdentityType — `trophy` is a Doctrine `json` column too, so EasyAdmin
        // auto-configures the field as a collection and injects these options.
        $resolver->setDefined(['allow_add', 'allow_delete', 'delete_empty', 'entry_options', 'entry_type']);
    }

    /** @return array<string,string> label => value */
    private function styleChoices(): array
    {
        $out = [];
        foreach (TrophyStyle::cases() as $case) {
            $out[$case->label()] = $case->value;
        }

        return $out;
    }

    /** @return array<string,string> label => value */
    private function finishChoices(): array
    {
        $out = [];
        foreach (TrophyFinish::cases() as $case) {
            $out[$case->label()] = $case->value;
        }

        return $out;
    }

    /** @return array<string,string> label => hex */
    private function ribbonColourChoices(): array
    {
        $out = [];
        foreach (TrophyConfigFactory::ribbonColours() as $colour) {
            $out[$colour['label']] = $colour['value'];
        }

        return $out;
    }

    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        // A null config still populates every control with the defaults, so the
        // widget has something to draw the instant an admin picks a style —
        // only `style` itself reflects the "no trophy" state.
        $config = TrophyConfigFactory::validate($viewData);
        $hasTrophy = TrophyConfigFactory::validateNullable($viewData) !== null;

        $forms['style']->setData($hasTrophy ? $config['style'] : self::NO_TROPHY);
        $forms['finish']->setData($config['finish']);
        $forms['ribbons']->setData($config['ribbons']);
        $forms['ribbonPrimary']->setData($config['ribbonPrimary']);
        $forms['ribbonSecondary']->setData($config['ribbonSecondary']);
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $style = $forms['style']->getData();
        if ($style === null || $style === self::NO_TROPHY) {
            $viewData = null;

            return;
        }

        $viewData = TrophyConfigFactory::validate([
            'style'           => $style,
            'finish'          => $forms['finish']->getData(),
            'ribbons'         => $forms['ribbons']->getData(),
            'ribbonPrimary'   => $forms['ribbonPrimary']->getData(),
            'ribbonSecondary' => $forms['ribbonSecondary']->getData(),
        ]);
    }
}
