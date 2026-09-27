<?php
namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserOwnerIdentityTest extends TestCase
{
    public function testOwnerIdentityFieldsDefaultToNull(): void
    {
        $user = new User('owner@example.com');

        $this->assertNull($user->getName());
        $this->assertNull($user->getNationality());
        $this->assertNull($user->getGender());
        $this->assertNull($user->getDob());
        $this->assertNull($user->getAppearance());
    }

    public function testOwnerIdentityFieldsRoundTrip(): void
    {
        $user = new User('owner@example.com');
        $dob  = new \DateTimeImmutable('1990-05-14');
        $appearance = [
            'hair' => 'quiff', 'hairColor' => '#4a2c1a', 'headband' => false, 'skin' => 's3',
            'face' => 'neutral', 'facial' => 'none', 'lip' => '#c9575e',
            'primary' => '#c8202f', 'secondary' => '#f4f3ee',
            'kit' => 'stripes', 'shorts' => 'black', 'socks' => 'primary',
            'outfit' => 'coat', 'trousers' => 'black', 'glasses' => false,
        ];

        $user->setName('Alex Owner');
        $user->setNationality('English');
        $user->setGender('male');
        $user->setDob($dob);
        $user->setAppearance($appearance);

        $this->assertSame('Alex Owner', $user->getName());
        $this->assertSame('English', $user->getNationality());
        $this->assertSame('male', $user->getGender());
        $this->assertSame($dob, $user->getDob());
        $this->assertSame($appearance, $user->getAppearance());
    }
}
