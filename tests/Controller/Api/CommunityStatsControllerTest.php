<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CommunityStatsControllerTest extends WebTestCase
{
    public function testMostTransfersIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-transfers');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('period', $data);
        $this->assertArrayHasKey('results', $data);
    }

    public function testMostDevelopmentIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-development');

        $this->assertResponseStatusCodeSame(200);
    }

    public function testMostSeasonsIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-seasons');

        $this->assertResponseStatusCodeSame(200);
    }

    public function testMostTrophiesIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-trophies');

        $this->assertResponseStatusCodeSame(200);
    }

    public function testInvalidPeriodReturns400(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-transfers?period=bogus');

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }
}
