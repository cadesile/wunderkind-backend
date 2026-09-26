<?php
namespace App\Form\Type;

use App\Enum\Appearance\BadgeCentre;
use App\Enum\Appearance\BadgePattern;
use App\Enum\Appearance\BadgeShape;
use App\Enum\Appearance\KitColor;
use App\Enum\Appearance\KitPart;
use App\Enum\Appearance\KitStyle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Compound form for editing an NpcClub's kit+badge identity array. Stored
 * shape is nested (`home`/`away` kit variants, each {kit, primary, secondary,
 * shorts, socks}, plus flat badge* keys shared by both), but the form's own
 * children are flat and prefixed (`homeKit`, `awayPrimary`, ...) — simpler
 * than Symfony's nested-form-group machinery given this already has a custom
 * DataMapper. `home`'s primary/secondary become the club's canonical
 * primaryColor/secondaryColor (see NpcClub::setIdentity()) — there's no
 * separate admin field for those any more.
 *
 * `kit`/`primary`/`secondary`/`shorts`/`socks` (for both variants) reuse the
 * exact same enums as the player sprite's AppearanceType so a club's kit
 * colors/styles never drift from a player's.
 */
final class KitIdentityType extends AbstractType implements DataMapperInterface
{
    private const KIT_VARIANT_DEFAULTS = [
        'kit' => 'stripes', 'primary' => '#c8202f', 'secondary' => '#f4f3ee',
        'shorts' => 'black', 'socks' => 'primary',
    ];

    private const BADGE_DEFAULTS = [
        'badgeShape' => 'shield', 'badgePattern' => 'plain', 'badgeCentre' => 'initials',
        'initials' => 'FC', 'badgeFill' => '#c8202f', 'badgeTrim' => '#f4f3ee', 'badgeSymbol' => '#f2c230',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['home', 'away'] as $variant) {
            $builder
                ->add($variant . 'Kit', ChoiceType::class, [
                    'choices' => $this->enumChoices(KitStyle::cases()),
                ])
                ->add($variant . 'Primary', ChoiceType::class, [
                    'choices' => $this->enumChoices(KitColor::cases()),
                ])
                ->add($variant . 'Secondary', ChoiceType::class, [
                    'choices' => $this->enumChoices(KitColor::cases()),
                ])
                ->add($variant . 'Shorts', ChoiceType::class, [
                    'choices' => $this->enumChoices(KitPart::cases()),
                ])
                ->add($variant . 'Socks', ChoiceType::class, [
                    'choices' => $this->enumChoices(KitPart::cases()),
                ]);
        }

        $builder
            ->add('badgeShape', ChoiceType::class, [
                'choices' => $this->enumChoices(BadgeShape::cases()),
            ])
            ->add('badgePattern', ChoiceType::class, [
                'choices' => $this->enumChoices(BadgePattern::cases()),
            ])
            ->add('badgeCentre', ChoiceType::class, [
                'choices' => $this->enumChoices(BadgeCentre::cases()),
            ])
            ->add('initials', TextType::class, ['required' => false])
            ->add('badgeFill', ChoiceType::class, [
                'choices' => $this->enumChoices(KitColor::cases()),
            ])
            ->add('badgeTrim', ChoiceType::class, [
                'choices' => $this->enumChoices(KitColor::cases()),
            ])
            ->add('badgeSymbol', ChoiceType::class, [
                'choices' => $this->enumChoices(KitColor::cases()),
            ])
            ->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);

        // Same EasyAdmin CollectionType-injection tolerance as AppearanceType —
        // `identity` is a Doctrine `json` column too.
        $resolver->setDefined(['allow_add', 'allow_delete', 'delete_empty', 'entry_options', 'entry_type']);
    }

    /** @param \BackedEnum[] $cases @return array<string,string> label=>value */
    private function enumChoices(array $cases): array
    {
        $out = [];
        foreach ($cases as $c) {
            $out[ucwords(strtolower(str_replace('_', ' ', $c->name)))] = $c->value;
        }
        return $out;
    }

    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        $data = is_array($viewData) ? $viewData : [];
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        foreach (['home', 'away'] as $variant) {
            $kitData = is_array($data[$variant] ?? null) ? $data[$variant] : [];
            foreach (self::KIT_VARIANT_DEFAULTS as $key => $default) {
                $forms[$variant . ucfirst($key)]->setData($kitData[$key] ?? $default);
            }
        }

        foreach (self::BADGE_DEFAULTS as $name => $default) {
            $forms[$name]->setData($data[$name] ?? $default);
        }
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $result = [];
        foreach (['home', 'away'] as $variant) {
            $kit = [];
            foreach (array_keys(self::KIT_VARIANT_DEFAULTS) as $key) {
                $kit[$key] = $forms[$variant . ucfirst($key)]->getData();
            }
            $result[$variant] = $kit;
        }
        foreach (array_keys(self::BADGE_DEFAULTS) as $name) {
            $result[$name] = $forms[$name]->getData();
        }

        $result['initials'] = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($result['initials'] ?? '')));
        $result['initials'] = substr($result['initials'], 0, 3);
        $viewData = $result;
    }
}
