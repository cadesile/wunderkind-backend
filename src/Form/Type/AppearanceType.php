<?php
namespace App\Form\Type;

use App\Enum\Appearance\Face;
use App\Enum\Appearance\FacialHair;
use App\Enum\Appearance\HairColor;
use App\Enum\Appearance\HairStyle;
use App\Enum\Appearance\Headwear;
use App\Enum\Appearance\KitColor;
use App\Enum\Appearance\KitPart;
use App\Enum\Appearance\KitStyle;
use App\Enum\Appearance\LipColor;
use App\Enum\Appearance\Neckwear;
use App\Enum\Appearance\Outfit;
use App\Enum\Appearance\SkinId;
use App\Enum\Appearance\Tattoo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Compound form for editing an Appearance array (the sprite's 15-key
 * SpriteConfig shape, plus the seven AvatarExtras keys stored flat beside it) plus a live SVG preview (see
 * templates/admin/field/appearance.html.twig). Every entity stores all 15
 * keys regardless of role — the sprite itself ignores whichever group
 * (kit/shorts/socks vs outfit/trousers/glasses) doesn't apply to its `type` —
 * so this form's field list never varies; only the admin widget's live
 * preview varies its rendered body shape by the `person_type` option below.
 */
final class AppearanceType extends AbstractType implements DataMapperInterface
{
    private const DEFAULTS = [
        'hair' => 'crop', 'hairColor' => '#c8602a', 'headband' => false, 'skin' => 's1',
        'face' => 'neutral', 'facial' => 'none', 'lip' => '#c9575e',
        'primary' => '#c8202f', 'secondary' => '#f4f3ee',
        'kit' => 'stripes', 'shorts' => 'black', 'socks' => 'primary',
        'outfit' => 'coat', 'trousers' => 'black', 'glasses' => false,
        // AvatarExtras, stored FLAT beside the config — see the class docblock.
        'earring' => false, 'armband' => false, 'wristband' => false, 'snood' => false,
        'headwear' => 'none', 'neckwear' => 'none', 'tattoos' => [],
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('hair', ChoiceType::class, [
                'choices' => $this->enumChoices(HairStyle::cases()),
            ])
            ->add('hairColor', ChoiceType::class, [
                'choices' => $this->enumChoices(HairColor::cases()),
            ])
            ->add('headband', CheckboxType::class, ['required' => false])
            ->add('skin', ChoiceType::class, [
                'choices' => $this->enumChoices(SkinId::cases()),
            ])
            ->add('face', ChoiceType::class, [
                'choices' => $this->enumChoices(Face::cases()),
            ])
            ->add('facial', ChoiceType::class, [
                'choices' => $this->enumChoices(FacialHair::cases()),
            ])
            ->add('lip', ChoiceType::class, [
                'choices' => $this->enumChoices(LipColor::cases()),
            ])
            ->add('primary', ChoiceType::class, [
                'choices' => $this->enumChoices(KitColor::cases()),
            ])
            ->add('secondary', ChoiceType::class, [
                'choices' => $this->enumChoices(KitColor::cases()),
            ])
            ->add('kit', ChoiceType::class, [
                'choices' => $this->enumChoices(KitStyle::cases()),
            ])
            ->add('shorts', ChoiceType::class, [
                'choices' => $this->enumChoices(KitPart::cases()),
            ])
            ->add('socks', ChoiceType::class, [
                'choices' => $this->enumChoices(KitPart::cases()),
            ])
            ->add('outfit', ChoiceType::class, [
                'choices' => $this->enumChoices(Outfit::cases()),
            ])
            ->add('trousers', ChoiceType::class, [
                'choices' => $this->enumChoices(KitPart::cases()),
            ])
            ->add('glasses', CheckboxType::class, ['required' => false])
            // ── AvatarExtras ────────────────────────────────────────────────
            // Every one of these is role-gated by the SPRITE, not here: it ignores
            // armband/wristband/snood on a staff body and headwear/neckwear on a
            // player, exactly as it already ignores the kit group on staff. So the
            // field list stays invariant, like the fifteen above, and the widget
            // only varies which sections it SHOWS.
            ->add('earring', CheckboxType::class, ['required' => false])
            ->add('armband', CheckboxType::class, ['required' => false])
            ->add('wristband', CheckboxType::class, ['required' => false])
            ->add('snood', CheckboxType::class, ['required' => false])
            ->add('headwear', ChoiceType::class, [
                'choices' => $this->enumChoices(Headwear::cases()),
            ])
            ->add('neckwear', ChoiceType::class, [
                'choices' => $this->enumChoices(Neckwear::cases()),
            ])
            // A list, not a single value — any combination is legal. `multiple`
            // makes Symfony hand back an array, which lands in the json as one.
            ->add('tattoos', ChoiceType::class, [
                'choices'  => array_combine(
                    array_map(static fn (Tattoo $t): string => $t->label(), Tattoo::cases()),
                    array_map(static fn (Tattoo $t): string => $t->value, Tattoo::cases()),
                ),
                'multiple' => true,
                'required' => false,
            ])
            ->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);

        // Which body shape the admin widget's live preview renders — doesn't
        // affect which fields exist or what's stored (see class docblock).
        $resolver
            ->setDefined('person_type')
            ->setAllowedValues('person_type', ['player', 'staff'])
            ->setDefault('person_type', 'staff');

        // Real measurements for the previewed subject, when it has any. The 16-bit
        // sprite draws three build tiers; these are mapped to one by the compositor
        // (window.AVATAR_BODY_SIZE), NOT stored — a Player already owns its height
        // and weight, and copying a derived tier into `appearance` would be a second
        // source of truth that silently goes stale the moment the Player changes.
        //
        // Null for Staff/Scout/Agent/User, which have no such attributes; the
        // preview then draws the neutral medium build, exactly as before.
        $resolver
            ->setDefined('height_cm')
            ->setAllowedTypes('height_cm', ['int', 'null'])
            ->setDefault('height_cm', null);

        $resolver
            ->setDefined('weight_kg')
            ->setAllowedTypes('weight_kg', ['int', 'null'])
            ->setDefault('weight_kg', null);

        // The `appearance` entity property is a Doctrine `json` (array) column, so
        // EasyAdmin auto-configures the field as an array/collection field and
        // injects CollectionType options (allow_add, allow_delete, delete_empty,
        // entry_options, entry_type) into whatever form type is set via
        // ->setFormType(), including this one. AppearanceType is a compound form,
        // not a collection, so these options are meaningless here — just accept
        // and ignore them so OptionsResolver doesn't reject them as undefined.
        $resolver->setDefined(['allow_add', 'allow_delete', 'delete_empty', 'entry_options', 'entry_type']);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['person_type'] = $options['person_type'];
        $view->vars['height_cm']   = $options['height_cm'];
        $view->vars['weight_kg']   = $options['weight_kg'];
    }

    /**
     * Build label=>value choices from the enum CASE NAME (not the value) — several
     * of these enums (SkinId, HairColor, LipColor, KitColor) back onto ids/hexes,
     * so labels sourced from ->value would render as raw codes. Case names give
     * readable labels for every enum, e.g. DARK_BROWN -> "Dark Brown".
     *
     * @param \BackedEnum[] $cases @return array<string,string> label=>value
     */
    private function enumChoices(array $cases): array
    {
        $out = [];
        foreach ($cases as $c) {
            $out[ucwords(strtolower(str_replace('_', ' ', $c->name)))] = $c->value;
        }
        return $out;
    }

    /** model array → child forms */
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        $data = is_array($viewData) ? $viewData : [];
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);
        foreach ($forms as $name => $form) {
            $form->setData($data[$name] ?? self::DEFAULTS[$name] ?? null);
        }
    }

    /** child forms → model array */
    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);
        $result = [];
        foreach ($forms as $name => $form) {
            $result[$name] = $form->getData();
        }
        foreach (['headband', 'glasses', 'earring', 'armband', 'wristband', 'snood'] as $flag) {
            $result[$flag] = (bool) ($result[$flag] ?? false);
        }

        // Re-indexed and filtered to known values: the json column is read back
        // verbatim by every serializer, and a sparse array would encode as a JSON
        // object rather than a list.
        $tattoos = is_array($result['tattoos'] ?? null) ? $result['tattoos'] : [];
        $result['tattoos'] = array_values(array_filter(
            $tattoos,
            static fn (mixed $t): bool => is_string($t) && Tattoo::tryFrom($t) !== null,
        ));

        $viewData = $result;
    }
}
