<?php
namespace App\Tests\Form;

use App\Form\Type\KitIdentityType;
use Symfony\Component\Form\Test\TypeTestCase;

class KitIdentityTypeTest extends TypeTestCase
{
    public function testSubmitMapsToNestedIdentityArray(): void
    {
        $form = $this->factory->create(KitIdentityType::class);
        $form->submit([
            'homeKit' => 'hoops', 'homePrimary' => '#1f8a4c', 'homeSecondary' => '#f4f3ee',
            'homeShorts' => 'white', 'homeSocks' => 'primary',
            'awayKit' => 'plain', 'awayPrimary' => '#1a1a1a', 'awaySecondary' => '#f2c230',
            'awayShorts' => 'black', 'awaySocks' => 'black',
            'badgeShape' => 'round', 'badgePattern' => 'stripes', 'badgeCentre' => 'initials',
            'initials' => 'a.f!c9', 'badgeFill' => '#1b2a4a', 'badgeTrim' => '#f2c230', 'badgeSymbol' => '#f4f3ee',
        ]);

        $this->assertTrue($form->isSynchronized());
        $data = $form->getData();
        $this->assertSame('hoops', $data['home']['kit']);
        $this->assertSame('#1f8a4c', $data['home']['primary']);
        $this->assertSame('plain', $data['away']['kit']);
        $this->assertSame('#1a1a1a', $data['away']['primary']);
        $this->assertSame('AFC', $data['initials'], 'initials must be sanitised: upper-cased, punctuation stripped, max 3 chars');
    }

    public function testPrefillFromNestedModel(): void
    {
        $model = [
            'home' => ['kit' => 'sash', 'primary' => '#5b2c83', 'secondary' => '#f2c230', 'shorts' => 'black', 'socks' => 'secondary'],
            'away' => ['kit' => 'halves', 'primary' => '#f4f3ee', 'secondary' => '#1a1a1a', 'shorts' => 'white', 'socks' => 'white'],
            'badgeShape' => 'diamond', 'badgePattern' => 'quarters', 'badgeCentre' => 'star',
            'initials' => 'FC', 'badgeFill' => '#5b2c83', 'badgeTrim' => '#f2c230', 'badgeSymbol' => '#f4f3ee',
        ];
        $form = $this->factory->create(KitIdentityType::class, $model);

        $this->assertSame('sash', $form->get('homeKit')->getData());
        $this->assertSame('halves', $form->get('awayKit')->getData());
        $this->assertSame('star', $form->get('badgeCentre')->getData());
    }

    public function testDefaultsFillMissingKeysForBothVariants(): void
    {
        $form = $this->factory->create(KitIdentityType::class, ['home' => ['kit' => 'plain']]);

        $this->assertSame('plain', $form->get('homeKit')->getData());
        $this->assertSame('#c8202f', $form->get('homePrimary')->getData());
        $this->assertSame('stripes', $form->get('awayKit')->getData());
        $this->assertSame('#c8202f', $form->get('awayPrimary')->getData());
        $this->assertSame('initials', $form->get('badgeCentre')->getData());
    }
}
