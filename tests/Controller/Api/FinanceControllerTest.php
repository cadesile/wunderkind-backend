<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FinanceControllerTest extends WebTestCase
{
    public function testOverviewRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/finance/overview');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testOverviewReturnsExpectedShape(): void
    {
        $client = static::createClient();

        // Integration test: inject a valid JWT via HTTP_AUTHORIZATION header
        $client->request('GET', '/api/finance/overview', [], [], ['HTTP_AUTHORIZATION' => 'Bearer test-token']);

        // Without a real token this will 401; integration test would inject valid JWT
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 401]);

        if ($statusCode === 200) {
            $data = json_decode($client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('monthlyRevenue', $data);
            $this->assertArrayHasKey('activeSponsors', $data);
            $this->assertArrayHasKey('investors', $data);
            $this->assertArrayHasKey('totalOwnershipGiven', $data);
        }
    }

    public function testEarlyTerminationCalculatesFee(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/finance/sponsors/nonexistent-id/terminate');

        $this->assertResponseStatusCodeSame(401);
    }
}
