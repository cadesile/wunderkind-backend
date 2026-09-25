<?php
namespace App\Tests\Form;

use App\Form\Type\AppearanceType;
use Symfony\Component\Form\Test\TypeTestCase;

class AppearanceTypeTest extends TypeTestCase
{
    // Real HTTP form submissions simply omit an unchecked checkbox's key
    // entirely — 'glasses' is intentionally absent here to match that, rather
    // than an empty-string value (which Symfony's CheckboxType does not treat
    // as "unchecked").
    private const SUBMITTED = [
        'hair' => 'quiff', 'hairColor' => '#4a2c1a', 'headband' => '1', 'skin' => 's3',
        'face' => 'focused', 'facial' => 'none', 'lip' => '#c9575e',
        'primary' => '#c8202f', 'secondary' => '#f4f3ee',
        'kit' => 'stripes', 'shorts' => 'black', 'socks' => 'primary',
        'outfit' => 'coat', 'trousers' => 'black',
    ];

    public function testSubmitMapsToAppearanceArray(): void
    {
        $form = $this->factory->create(AppearanceType::class);
        $form->submit(self::SUBMITTED);

        $this->assertTrue($form->isSynchronized());
        $data = $form->getData();
        $this->assertTrue($data['headband']);   // checked → true
        $this->assertFalse($data['glasses']);   // unchecked → false
        $this->assertSame('quiff', $data['hair']);
        $this->assertSame('s3', $data['skin']);
    }

    public function testPrefillFromModel(): void
    {
        $model = [
            'hair' => 'bald', 'hairColor' => '#8a5a2b', 'headband' => true, 'skin' => 's6',
            'face' => 'cool', 'facial' => 'beard', 'lip' => '#b8302f',
            'primary' => '#1a1a1a', 'secondary' => '#d94040',
            'kit' => 'hoops', 'shorts' => 'white', 'socks' => 'secondary',
            'outfit' => 'suit', 'trousers' => 'primary', 'glasses' => true,
        ];
        $form = $this->factory->create(AppearanceType::class, $model);
        $this->assertSame('bald', $form->get('hair')->getData());
        $this->assertSame('beard', $form->get('facial')->getData());
        $this->assertTrue($form->get('glasses')->getData());
    }

    public function testPersonTypeOptionIsExposedToTheView(): void
    {
        $form = $this->factory->create(AppearanceType::class, null, ['person_type' => 'player']);
        $view = $form->createView();
        $this->assertSame('player', $view->vars['person_type']);
    }

    public function testPersonTypeDefaultsToStaff(): void
    {
        $form = $this->factory->create(AppearanceType::class);
        $view = $form->createView();
        $this->assertSame('staff', $view->vars['person_type']);
    }
}
