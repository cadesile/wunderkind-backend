<?php

namespace App\Tests\Entity;

use App\Entity\Agent;
use PHPUnit\Framework\TestCase;

class AgentSnapshotTest extends TestCase
{
    public function testToSnapshotArrayReturnsFullAgentRecord(): void
    {
        $agent = new Agent('Jorge Mendes');
        $agent->setCommissionRate('12.50');
        $agent->setReputation(80);
        $agent->setExperience(30);
        $agent->setRating(85);
        $agent->setNationality('Portuguese');
        $agent->setDob(new \DateTimeImmutable('1966-01-07'));

        $snap = $agent->toSnapshotArray();

        $this->assertSame(
            ['id', 'name', 'commissionRate', 'reputation', 'experience', 'rating', 'nationality', 'dateOfBirth', 'appearance'],
            array_keys($snap),
        );
        $this->assertSame($agent->getId()->toRfc4122(), $snap['id']);
        $this->assertSame('Jorge Mendes', $snap['name']);
        $this->assertSame('12.50', $snap['commissionRate']);
        $this->assertSame(80, $snap['reputation']);
        $this->assertSame(30, $snap['experience']);
        $this->assertSame(85, $snap['rating']);
        $this->assertSame('Portuguese', $snap['nationality']);
        $this->assertSame('1966-01-07', $snap['dateOfBirth']);
        // Only AppearanceLifecycleSubscriber's prePersist hook fills this — a
        // plain, never-persisted Agent (as here) stays null.
        $this->assertNull($snap['appearance']);
    }

    public function testSnapshotIncludesAppearanceVerbatimWhenSet(): void
    {
        $agent = new Agent('With Appearance');
        $appearance = [
            'hair' => 'crop', 'hairColor' => '#c8602a', 'headband' => false, 'skin' => 's1',
            'face' => 'neutral', 'facial' => 'none', 'lip' => '#c9575e',
            'primary' => '#c8202f', 'secondary' => '#f4f3ee',
            'kit' => 'stripes', 'shorts' => 'black', 'socks' => 'primary',
            'outfit' => 'coat', 'trousers' => 'black', 'glasses' => false,
        ];
        $agent->setAppearance($appearance);

        $this->assertSame($appearance, $agent->toSnapshotArray()['appearance']);
    }

    public function testDateOfBirthIsNullWhenUnset(): void
    {
        $snap = (new Agent('No DOB'))->toSnapshotArray();
        $this->assertNull($snap['dateOfBirth']);
    }
}
