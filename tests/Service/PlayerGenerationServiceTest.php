<?php
// tests/Service/PlayerGenerationServiceTest.php
namespace App\Tests\Service;

use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Service\NameGeneratorService;
use App\Service\PlayerGenerationService;
use PHPUnit\Framework\TestCase;

class PlayerGenerationServiceTest extends TestCase
{
    private function makeService(): PlayerGenerationService
    {
        $nameGen = $this->createMock(NameGeneratorService::class);
        $nameGen->method('getRandomNationality')->willReturn('English');
        $nameGen->method('generatePlayerName')->willReturn([
            'firstName' => 'Test',
            'lastName'  => 'Player',
        ]);
        return new PlayerGenerationService($nameGen);
    }

    public function testGenerateReturnsPlayerInstance(): void
    {
        $player = $this->makeService()->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
        $this->assertInstanceOf(Player::class, $player);
    }

    public function testGeneratedPlayerHasCorrectPosition(): void
    {
        $player = $this->makeService()->generate(PlayerPosition::GOALKEEPER, RecruitmentSource::SCOUTING_NETWORK);
        $this->assertSame(PlayerPosition::GOALKEEPER, $player->getPosition());
    }

    public function testGeneratedPlayerHasCorrectRecruitmentSource(): void
    {
        $player = $this->makeService()->generate(PlayerPosition::ATTACKER, RecruitmentSource::AGENT_OFFER);
        $this->assertSame(RecruitmentSource::AGENT_OFFER, $player->getRecruitmentSource());
    }

    public function testGeneratedPlayerHasNameFromNameGenerator(): void
    {
        $player = $this->makeService()->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
        $this->assertSame('Test', $player->getFirstName());
        $this->assertSame('Player', $player->getLastName());
        $this->assertSame('English', $player->getNationality());
    }
}
