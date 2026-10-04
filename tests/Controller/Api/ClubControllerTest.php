<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ClubControllerTest extends WebTestCase
{
    /**
     * ClubInitRequest no longer declares a `manager` property (owner identity
     * moved to User, see OwnerAvatarController) — a client still sending one
     * in the body must not error; Symfony's #[MapRequestPayload] denormalizes
     * against the DTO's own declared properties and silently ignores unmapped
     * JSON keys.
     */
    public function testInitializeIgnoresLegacyManagerPayload(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User('legacy-manager-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user, 'api');
        $client->request(
            'POST',
            '/api/club/initialize',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'clubName' => 'Legacy FC ' . uniqid('', true),
                'country'  => 'EN',
                'manager'  => [
                    'name'        => 'Old Field',
                    'dateOfBirth' => '1980-01-01',
                    'gender'      => 'male',
                    'nationality' => 'English',
                ],
            ]),
        );

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
    }

    /**
     * Regression test: a mis-cased country code used to get persisted onto Club::$country
     * verbatim, which silently broke StarterPackService's nationality resolution later
     * (Country::tryFrom() is case-sensitive) — the club would end up with an empty starter
     * squad with no way to recover. Must now be normalized to the canonical uppercase code.
     */
    public function testInitializeNormalizesALowercaseCountryCode(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User('lowercase-country-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user, 'api');
        $client->request(
            'POST',
            '/api/club/initialize',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'clubName' => 'Lowercase FC ' . uniqid('', true),
                'country'  => 'en',
            ]),
        );

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);

        $em->clear();
        $club = $em->getRepository(\App\Entity\Club::class)->find($data['id']);
        $this->assertSame('EN', $club->getCountry(), 'Country must be normalized to uppercase.');
    }

    public function testInitializeRejectsAnUnknownCountryCode(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User('bad-country-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user, 'api');
        $client->request(
            'POST',
            '/api/club/initialize',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'clubName' => 'Bad Country FC ' . uniqid('', true),
                'country'  => 'XX',
            ]),
        );

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('invalid_country_code', $data['error']);
    }

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

    public function testNameOptionsCitiesAndSuffixesAreAlphabetized(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/club/name-options?country=EN');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);

        $sortedCities = $data['cities'];
        usort($sortedCities, fn ($a, $b) => strcmp($a, $b));
        $this->assertSame($sortedCities, $data['cities'], 'Expected cities to already be alphabetically sorted');

        $sortedSuffixes = $data['suffixes'];
        usort($sortedSuffixes, fn ($a, $b) => strcmp($a, $b));
        $this->assertSame($sortedSuffixes, $data['suffixes'], 'Expected suffixes to already be alphabetically sorted');
    }

    public function testNameOptionsSortsAccentedCharactersCorrectly(): void
    {
        // Plain byte-order sort() would push 'Ávila' (multi-byte UTF-8) past
        // every single-byte ASCII entry, including 'Zaragoza' — a locale-aware
        // sort places it with its unaccented neighbors ('Avilés' etc.) instead.
        $client = static::createClient();
        $client->request('GET', '/api/club/name-options?country=ES');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);

        $avilaIndex     = array_search('Ávila', $data['cities'], true);
        $zaragozaIndex  = array_search('Zaragoza', $data['cities'], true);

        $this->assertNotFalse($avilaIndex);
        $this->assertNotFalse($zaragozaIndex);
        $this->assertLessThan($zaragozaIndex, $avilaIndex, "'Ávila' should sort before 'Zaragoza' under Spanish collation");
    }
}
