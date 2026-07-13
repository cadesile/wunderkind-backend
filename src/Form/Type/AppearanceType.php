<?php
namespace App\Form\Type;

use App\Enum\Appearance\AvatarAccessory;
use App\Enum\Appearance\EyeShape;
use App\Enum\Appearance\FaceShape;
use App\Enum\Appearance\FacialHair;
use App\Enum\Appearance\HairColor;
use App\Enum\Appearance\HairStyle;
use App\Enum\Appearance\NoseType;
use App\Enum\Appearance\SkinTone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Compound form for editing an Appearance array. Renders per-attribute dropdowns
 * plus a live SVG preview (see templates/admin/field/appearance.html.twig).
 */
final class AppearanceType extends AbstractType implements DataMapperInterface
{
    private const DEFAULTS = [
        'skinTone' => '#e8c49a', 'hairStyle' => 'classic', 'hairColor' => 'brown',
        'accessory' => null, 'kitTrim' => '#3a8fd4', 'facialHair' => 'none',
        'faceShape' => 'oval', 'eyeShape' => 'narrow', 'noseType' => 'normal', 'jerseyVariant' => 1,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('skinTone', ChoiceType::class, [
                'choices' => $this->enumChoices(SkinTone::cases()),
            ])
            ->add('hairStyle', ChoiceType::class, [
                'choices' => $this->enumChoices(HairStyle::cases()),
            ])
            ->add('hairColor', ChoiceType::class, [
                'choices' => $this->enumChoices(HairColor::cases()),
            ])
            ->add('accessory', ChoiceType::class, [
                'required'    => false,
                'placeholder' => 'None',
                'choices'     => $this->enumChoices(AvatarAccessory::cases()),
            ])
            ->add('facialHair', ChoiceType::class, [
                'choices' => $this->enumChoices(FacialHair::cases()),
            ])
            ->add('faceShape', ChoiceType::class, [
                'choices' => $this->enumChoices(FaceShape::cases()),
            ])
            ->add('eyeShape', ChoiceType::class, [
                'choices' => $this->enumChoices(EyeShape::cases()),
            ])
            ->add('noseType', ChoiceType::class, [
                'choices' => $this->enumChoices(NoseType::cases()),
            ])
            ->add('kitTrim', TextType::class)
            ->add('jerseyVariant', IntegerType::class)
            ->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }

    /** @param \BackedEnum[] $cases @return array<string,string> label=>value */
    private function enumChoices(array $cases): array
    {
        $out = [];
        foreach ($cases as $c) {
            $out[ucwords(str_replace('_', ' ', (string) $c->value))] = $c->value;
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
        // Normalise: empty accessory → null; jerseyVariant → int
        $result['accessory']     = $result['accessory'] !== '' && $result['accessory'] !== null ? $result['accessory'] : null;
        $result['jerseyVariant'] = (int) ($result['jerseyVariant'] ?? 1);
        $viewData = $result;
    }
}
