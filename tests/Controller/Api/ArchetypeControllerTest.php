<?php

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ArchetypeControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $application = new Application(static::$kernel);
        (new CommandTester($application->find('app:seed-archetypes')))->execute([]);
    }

    private function fetch(): array
    {
        $this->client->request('GET', '/api/archetypes');
        $this->assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testIsReachableWithoutAJwt(): void
    {
        // The catalogue is static reference data the client needs before it has a token.
        $this->client->request('GET', '/api/archetypes');
        $this->assertResponseStatusCodeSame(200);
    }

    public function testReturnsTwentyArchetypesWithTheContractedKeys(): void
    {
        $payload = $this->fetch();

        $this->assertArrayHasKey('versionHash', $payload);
        $this->assertNotSame('', $payload['versionHash']);
        $this->assertCount(20, $payload['archetypes']);

        foreach ($payload['archetypes'] as $archetype) {
            $this->assertSame(
                ['id', 'slug', 'name', 'description', 'polarity', 'traitWeights'],
                array_keys($archetype),
                'Response keys are a contract with the client TS types — a rename fails silently there.',
            );
            $this->assertContains($archetype['polarity'], ['positive', 'negative']);
            $this->assertArrayHasKey('formula', $archetype['traitWeights']);
            $this->assertArrayHasKey('threshold', $archetype['traitWeights']);
        }
    }

    public function testBothPolaritiesArePopulated(): void
    {
        $counts = array_count_values(
            array_column($this->fetch()['archetypes'], 'polarity')
        );

        // The client resolves one positive AND one negative per player, so neither list may empty.
        $this->assertSame(10, $counts['positive'] ?? 0);
        $this->assertSame(10, $counts['negative'] ?? 0);
    }

    public function testVersionHashIsStableAcrossRequests(): void
    {
        $this->assertSame($this->fetch()['versionHash'], $this->fetch()['versionHash']);
    }

    public function testMatchingEtagReturns304(): void
    {
        $this->client->request('GET', '/api/archetypes');
        $etag = $this->client->getResponse()->headers->get('ETag');
        $this->assertNotNull($etag, 'ETag drives the client cache check.');

        $this->client->request('GET', '/api/archetypes', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        $this->assertResponseStatusCodeSame(304);
    }
}
