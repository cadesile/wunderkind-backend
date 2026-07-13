<?php
namespace App\Tests\Form;

use App\Form\Type\AppearanceType;
use Symfony\Component\Form\Test\TypeTestCase;

class AppearanceTypeTest extends TypeTestCase
{
    public function testSubmitMapsToAppearanceArray(): void
    {
        $form = $this->factory->create(AppearanceType::class);
        $form->submit([
            'skinTone' => '#dfaa80', 'hairStyle' => 'messy', 'hairColor' => 'dark_brown',
            'accessory' => '', 'kitTrim' => '#3a8fd4', 'facialHair' => 'none',
            'faceShape' => 'oval', 'eyeShape' => 'narrow', 'noseType' => 'normal', 'jerseyVariant' => '2',
        ]);

        $this->assertTrue($form->isSynchronized());
        $data = $form->getData();
        $this->assertNull($data['accessory']);          // '' → null
        $this->assertSame(2, $data['jerseyVariant']);   // '2' → int
        $this->assertSame('messy', $data['hairStyle']);
    }

    public function testPrefillFromModel(): void
    {
        $model = [
            'skinTone' => '#5c2d0a', 'hairStyle' => 'bald', 'hairColor' => 'brown',
            'accessory' => 'glasses', 'kitTrim' => '#d94040', 'facialHair' => 'beard',
            'faceShape' => 'square', 'eyeShape' => 'round', 'noseType' => 'small', 'jerseyVariant' => 3,
        ];
        $form = $this->factory->create(AppearanceType::class, $model);
        $this->assertSame('bald', $form->get('hairStyle')->getData());
        $this->assertSame('glasses', $form->get('accessory')->getData());
        $this->assertSame(3, $form->get('jerseyVariant')->getData());
    }
}
