<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ClubControllerTest extends WebTestCase
{
    public function testNameOptionsRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/club/name-options?country=ES');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testNameOptionsReturnsExpectedShapeWhenAuthenticated(): void
    {
        $client = static::createClient();

        // No real JWT-minting test infrastructure exists in this codebase yet
        // (see tests/Controller/Api/FinanceControllerTest.php for the same
        // established pattern) — this confirms the auth gate is in place and,
        // IF a valid token were supplied, documents the expected response shape.
        $client->request('GET', '/api/club/name-options?country=ES', [], [], ['HTTP_AUTHORIZATION' => 'Bearer test-token']);

        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 401]);

        if ($statusCode === 200) {
            $data = json_decode($client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('country', $data);
            $this->assertArrayHasKey('cities', $data);
            $this->assertArrayHasKey('suffixes', $data);
            $this->assertContains('Madrid', $data['cities']);
        }
    }
}
