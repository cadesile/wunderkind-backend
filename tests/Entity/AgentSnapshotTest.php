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
            ['id', 'name', 'commissionRate', 'reputation', 'experience', 'rating', 'nationality', 'dateOfBirth'],
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
    }

    public function testDateOfBirthIsNullWhenUnset(): void
    {
        $snap = (new Agent('No DOB'))->toSnapshotArray();
        $this->assertNull($snap['dateOfBirth']);
    }
}
