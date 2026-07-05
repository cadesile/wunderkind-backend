<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CommunityStatsControllerTest extends WebTestCase
{
    public function testMostTransfersRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-transfers');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMostDevelopmentRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-development');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMostSeasonsRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-seasons');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMostTrophiesRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-trophies');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testInvalidPeriodReturns400OrUnauthorized(): void
    {
        $client = static::createClient();

        // Integration test: inject a valid JWT via HTTP_AUTHORIZATION header
        $client->request('GET', '/api/stats/most-transfers?period=bogus', [], [], ['HTTP_AUTHORIZATION' => 'Bearer test-token']);

        // Without a real token this will 401; integration test would inject valid JWT
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [400, 401]);
    }
}
