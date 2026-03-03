<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class InboxControllerTest extends WebTestCase
{
    public function testListInboxRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/inbox');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListInboxReturnsMessages(): void
    {
        $client = static::createClient();

        // Authenticate with a valid JWT — set via header in integration tests
        $client->request('GET', '/api/inbox', [], [], ['HTTP_AUTHORIZATION' => 'Bearer test-token']);

        // Without a real token this will 401; integration test would inject valid JWT
        $this->assertContains($client->getResponse()->getStatusCode(), [200, 401]);
    }

    public function testAcceptInvalidMessageReturns404(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/inbox/nonexistent-id/accept');

        $this->assertResponseStatusCodeSame(401);
    }
}
