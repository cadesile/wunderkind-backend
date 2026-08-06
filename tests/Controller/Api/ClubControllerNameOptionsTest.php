<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ClubControllerNameOptionsTest extends WebTestCase
{
    public function testNameOptionsMergesCuratedAndRemainingCities(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/club/name-options?country=EN');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);

        // Curated cities should be present
        $this->assertContains('London', $data['cities']);
        $this->assertContains('Manchester', $data['cities']);
        $this->assertContains('Liverpool', $data['cities']);

        // Remaining cities (not in curated list) should also be present
        $this->assertContains('Prestwich', $data['cities']);
        $this->assertContains('Belper', $data['cities']);
    }
}
