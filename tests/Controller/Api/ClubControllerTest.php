<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ClubControllerTest extends WebTestCase
{
    public function testNameOptionsIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/club/name-options?country=ES');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('ES', $data['country']);
        $this->assertContains('Madrid', $data['cities']);
        $this->assertContains('Barcelona', $data['cities']);
        $this->assertNotEmpty($data['suffixes']);
    }

    public function testNameOptionsCoversAllNineGenerationCapableCountries(): void
    {
        $client = static::createClient();

        foreach (['ES', 'EN', 'DE', 'IT', 'FR', 'BR', 'AR', 'NL', 'PT'] as $country) {
            $client->request('GET', "/api/club/name-options?country={$country}");

            $this->assertResponseStatusCodeSame(200);
            $data = json_decode($client->getResponse()->getContent(), true);
            $this->assertNotEmpty($data['cities'], "Expected cities for {$country}");
            $this->assertNotEmpty($data['suffixes'], "Expected suffixes for {$country}");
        }
    }

    public function testNameOptionsFallsBackToEnglandForUnsupportedCountry(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/club/name-options?country=XX');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('XX', $data['country']);
        $this->assertNotEmpty($data['cities']);
        $this->assertContains('London', $data['cities']);
    }
}
