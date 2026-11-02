<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Service\SetupTutorialValidator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `GET /api/starter-config` must carry the `tutorial` block the app's new-game setup reads.
 *
 * The app treats the key as optional and a malformed value as "no tutorial", so nothing on
 * either side fails loudly if this regresses — the briefings just stop appearing. That is
 * what these assertions are for.
 */
class StarterConfigTutorialTest extends WebTestCase
{
    /** @return array<string, mixed> */
    private function fetch(): array
    {
        $client = static::createClient();
        $client->request('GET', '/api/starter-config');

        $this->assertResponseStatusCodeSame(200);

        return json_decode($client->getResponse()->getContent(), true);
    }

    public function testTheEndpointIsStillPublic(): void
    {
        // Called before the player has credentials, so it can never require a token — and
        // that is also why the tutorial payload is the same for everyone.
        $data = $this->fetch();
        $this->assertArrayHasKey('startingBalance', $data);
    }

    public function testTheTutorialBlockIsServed(): void
    {
        $data = $this->fetch();

        $this->assertArrayHasKey('tutorial', $data, 'the app reads `tutorial`; renaming the key silently disables the feature');
        $this->assertIsArray($data['tutorial']);
        $this->assertArrayHasKey('version', $data['tutorial']);
        $this->assertArrayHasKey('items', $data['tutorial']);
    }

    public function testTheServedBlockPassesTheRulesTheClientApplies(): void
    {
        // Whatever an admin has saved, what the API hands out must survive `parseTutorial`.
        // The client drops bad items in silence, so this is the only place it is visible.
        $this->assertSame([], SetupTutorialValidator::problems($this->fetch()['tutorial']));
    }

    public function testTheSeededDefaultCarriesTheFiveBriefingsAndTenNotes(): void
    {
        $items = $this->fetch()['tutorial']['items'];

        $kinds = array_count_values(array_column($items, 'kind'));
        $this->assertSame(5, $kinds['briefing'] ?? 0);
        $this->assertSame(10, $kinds['note'] ?? 0);
    }

    public function testEveryBriefingAnchorsOnTheFirstStepOfItsChapter(): void
    {
        // The chapter boundaries in the app's five-chapter rail. A briefing introduces a
        // chapter, so it anchors on that chapter's FIRST step — `owner` opens on `avatar`,
        // not on `owner`. A wrong-but-valid anchor renders in the wrong place and reports
        // nothing, which no structural check can catch.
        $items = $this->fetch()['tutorial']['items'];

        $anchors = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'briefing') {
                $anchors[] = $item['anchor']['before'];
            }
        }

        $this->assertSame(['avatar', 'country', 'badge', 'hire:chairman', 'overview'], $anchors);
    }

    public function testEveryCopyKeyUsesTheRenamedNgsTutPrefix(): void
    {
        // Renamed from a bare `tut.*` at integration. The old spelling resolves to nothing
        // and the item is dropped without a word.
        foreach ($this->fetch()['tutorial']['items'] as $item) {
            foreach (['titleKey', 'bodyKey', 'ctaKey'] as $field) {
                if (isset($item[$field])) {
                    $this->assertStringStartsWith('ngs.tut.', $item[$field]);
                }
            }
            foreach ($item['pointKeys'] ?? [] as $key) {
                $this->assertStringStartsWith('ngs.tut.', $key);
            }
        }
    }
}
