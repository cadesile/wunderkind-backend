<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\GameConfig;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\Location\PlotClaim;
use App\Entity\User;
use App\Repository\GameConfigRepository;
use App\Repository\Location\PlotClaimRepository;
use App\Service\Location\PlotSellBackService;
use App\Service\Location\RichListPlotService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * The Rich List's plot rows: one per live claim, keyed by owner, with the sell-back figure
 * from PlotSellBackService::quote() and the same inactivity clock the app is shown.
 */
class RichListPlotServiceTest extends TestCase
{
    private const USER_ID = '0193f2c6-0000-7000-8000-0000000000f1';

    private function service(array $claims, int $reclaimDays = 30): RichListPlotService
    {
        $repo = $this->createStub(PlotClaimRepository::class);
        $repo->method('findActiveForUserIds')->willReturn($claims);

        $config = new GameConfig();
        $config->setPlotDormancyReclaimDays($reclaimDays);

        $configRepo = $this->createStub(GameConfigRepository::class);
        $configRepo->method('getConfig')->willReturn($config);

        // Final, so not stubbable. An EMPTY plot's quote touches none of its collaborators
        // (no club to value), so an uninitialised instance runs the real formula.
        $sellBack = (new \ReflectionClass(PlotSellBackService::class))->newInstanceWithoutConstructor();

        return new RichListPlotService($repo, $sellBack, $configRepo);
    }

    private function emptyClaim(int $landPencePaid, \DateTimeImmutable $claimedAt): PlotClaim
    {
        $location = new Location(new CountryProfile('EN', 'England'), 'Leeds', 'leeds', 'leeds');
        (new \ReflectionProperty(Location::class, 'region'))->setValue($location, 'West Yorkshire');

        $user = new User('owner@rich.test');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, UuidV7::fromString(self::USER_ID));

        return new PlotClaim(new LandPlot($location, 1), $user, $landPencePaid, null, $claimedAt);
    }

    public function testNoClaimsMeansNoRows(): void
    {
        $this->assertSame([], $this->service([])->forUsers([self::USER_ID]));
    }

    public function testAnEmptyPlotIsKeyedByOwnerWithTheBuildDeadline(): void
    {
        $claimedAt = new \DateTimeImmutable('2026-11-01T00:00:00+00:00');

        $rows = $this->service([$this->emptyClaim(1_000_000_00, $claimedAt)], 30)->forUsers([self::USER_ID]);
        $plot = $rows[self::USER_ID][0];

        $this->assertSame('Leeds', $plot['town']);
        $this->assertSame('West Yorkshire', $plot['region']);
        $this->assertSame('EN', $plot['countryCode']);
        $this->assertNull($plot['clubName']);
        // Unvalued town: today's value falls back to what was paid, as the sell-back does.
        $this->assertSame(1_000_000_00, $plot['landValuePence']);
        // 90% land return, no ground, no club.
        $this->assertSame(900_000_00, $plot['sellBackPence']);
        // Empty since the claim + the 30-day window.
        $this->assertSame((new \DateTimeImmutable('2026-12-01T00:00:00+00:00'))->getTimestamp(), $plot['reclaimAtTs']);
    }
}
