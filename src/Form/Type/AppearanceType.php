<?php
namespace App\Form\Type;

use App\Enum\Appearance\Face;
use App\Enum\Appearance\FacialHair;
use App\Enum\Appearance\HairColor;
use App\Enum\Appearance\HairStyle;
use App\Enum\Appearance\KitColor;
use App\Enum\Appearance\KitPart;
use App\Enum\Appearance\KitStyle;
use App\Enum\Appearance\LipColor;
use App\Enum\Appearance\Outfit;
use App\Enum\Appearance\SkinId;
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
 * SpriteConfig shape) plus a live SVG preview (see
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
        $result['headband'] = (bool) $result['headband'];
        $result['glasses']  = (bool) $result['glasses'];
        $viewData = $result;
    }
}
