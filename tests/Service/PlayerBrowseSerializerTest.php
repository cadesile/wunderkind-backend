<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Agent;
use App\Entity\Player;
use App\Service\PlayerBrowseSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Extracted from ScoutSearchControllerTest when serializePlayer() moved out of the controller
 * (now shared with GET /api/players/foreign) — same assertions, no reflection needed any more
 * since this is a plain public method now.
 */
class PlayerBrowseSerializerTest extends TestCase
{
    public function testSerializeNestsAgentUsingSharedShape(): void
    {
        $agent = new Agent('Jorge Mendes');
        $agent->setCommissionRate('9.50');
        $player = new Player('A', 'B');
        $player->setAgent($agent);

        $result = (new PlayerBrowseSerializer())->serialize($player);

        $this->assertArrayHasKey('agent', $result);
        $this->assertSame($agent->toSnapshotArray(), $result['agent']);
    }

    public function testSerializeAgentIsNullWhenNone(): void
    {
        $result = (new PlayerBrowseSerializer())->serialize(new Player('A', 'B'));

        $this->assertArrayHasKey('agent', $result);
        $this->assertNull($result['agent']);
    }

    public function testSerializeIncludesAppearanceVerbatim(): void
    {
        $appearance = [
            'hair' => 'afro', 'hairColor' => '#e6bd55', 'headband' => true, 'skin' => 's2',
            'face' => 'happy', 'facial' => 'none', 'lip' => '#d98a7e',
            'primary' => '#d94040', 'secondary' => '#f2c230',
            'kit' => 'sash', 'shorts' => 'primary', 'socks' => 'black',
            'outfit' => 'coat', 'trousers' => 'black', 'glasses' => false,
        ];
        $player = new Player('A', 'B');
        $player->setAppearance($appearance);

        $result = (new PlayerBrowseSerializer())->serialize($player);

        $this->assertArrayHasKey('appearance', $result);
        $this->assertSame($appearance, $result['appearance']);
    }

    /** Regression coverage for the new countryCode field, added alongside GET /api/players/foreign. */
    public function testSerializeIncludesCountryCodeDerivedFromNationality(): void
    {
        $player = new Player('A', 'B');
        $player->setNationality('English');

        $result = (new PlayerBrowseSerializer())->serialize($player);

        $this->assertSame('EN', $result['countryCode']);
    }

    public function testSerializeCountryCodeIsNullForAnUnrecognisedNationality(): void
    {
        $player = new Player('A', 'B');
        $player->setNationality('Polish'); // no Country case — see Country::fromNationality()

        $result = (new PlayerBrowseSerializer())->serialize($player);

        $this->assertNull($result['countryCode']);
    }
}
