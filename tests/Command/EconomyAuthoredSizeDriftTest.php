<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\League;
use App\Entity\Location\CountryProfile;
use App\Entity\NpcClub;
use App\Entity\CountryWorldPackCache;
use App\Entity\WorldPack\WorldPackSet;
use App\Enum\WorldPack\WorldPackSetStatus;
use App\Service\WorldInitializationService;
use App\Repository\GameConfigRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:economy:wage-table`'s check 9 — the authored division size against the clubs that exist.
 *
 * ── WHAT IS BEING GUARDED ─────────────────────────────────────────────────────────────────
 *
 * `league.slots` is authored on purpose: a divisor derived from live NPC rows moves whenever
 * somebody clicks Generate, which silently re-prices a tier's TV money for every shipped build.
 * The cost of that decision is that the authored number can drift from reality, and until this
 * check nothing anywhere compared the two. Regenerate a tier at 20 clubs against `slots = 16`
 * and the world pays 16-club money to a 20-club division — silently.
 *
 * ── ⚠️ THE CASE THIS TEST EXISTS FOR IS THE ONE THAT MUST STAY SILENT ────────────────────
 *
 * `slots` is NOT supposed to equal the row count at a country's deepest tier: the generator
 * leaves one place for the player's club, so England runs 16 rows at tiers 1-7 and 15 at tier 8
 * against an authored 16 throughout. A naive `slots == rows` check fires on every bottom tier in
 * the world, by design, and would be pure noise.
 *
 * **And the reservation is not universal** — on the development clone EN reserves while CA, JP,
 * NG, PL, US and SC hold the same count at every tier. So the check DETECTS the reservation
 * (deepest tier holding exactly one fewer row than the tier above) rather than assuming it; an
 * unconditional `+1` would have corrupted six countries' rows when `Version20261119120000` was
 * dry-run. Both shapes are asserted here, not eyeballed.
 */
class EconomyAuthoredSizeDriftTest extends KernelTestCase
{
    /** Fresh synthetic codes — nothing else in the suite fixtures a `Y_` country. */
    private const RESERVED   = 'YA';   // agrees, with a reserved bottom tier
    private const OVERFILLED = 'YB';   // more rows than slots  -> must FAIL
    private const UNDERFILLED = 'YC';  // fewer rows than slots -> must WARN
    private const DERIVING   = 'YD';   // slots = 0, unreserved bottom tier
    private const POOLED     = 'YE';   // fewer rows than slots, WITH a pool -> must FAIL
    private const PACKED     = 'YF';   // rows agree with slots, but the cached PACK does not
    private const LIVE_EN    = 'YG';   // the deployed England shape, measured 2026-11-19
    private const LIVE_SC    = 'YH';   // the deployed Scotland shape, measured 2026-11-19

    /** The message fragments the three outcomes are distinguished by. */
    private const TABLE_HEADING = 'Authored division size against the NPC club rows that exist';
    private const FAIL_PHRASE   = 'NPC club rows exist against a';
    private const WARN_PHRASE   = 'nothing is being divided yet';

    /**
     * The turnover table as the suite found it, stashed so `seed()`'s cannot outlive this file.
     *
     * ⚠️ `game_config` IS A SINGLE SHARED ROW in a test database that is not migration-tracked,
     * and `seed()` has to author a turnover table or no tier resolves and the preview
     * short-circuits before the checks this file is about are reached. So the row is owned for
     * the duration and put back, rather than left holding a figure the next test reads.
     *
     * ⚠️ RESTORED IN `tearDown()`, NOT AT THE END OF EACH TEST, AND THAT IS THE POINT: PHPUnit
     * runs `tearDown()` after a failed assertion too, so the row goes back exactly when
     * something has already gone wrong — which is when a dirty shared row costs the most,
     * because the next failure is then someone else's to attribute.
     *
     * @var list<int>
     */
    private array $shippedTurnoverPence = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->shippedTurnoverPence = self::getContainer()
            ->get(GameConfigRepository::class)
            ->getConfig(flush: true)
            ->getEconomyBaselineTurnoverPence();

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->restoreTurnoverTable();
        $this->purge();

        parent::tearDown();
    }

    /** Put the shared turnover table back, whatever happened to the test. */
    private function restoreTurnoverTable(): void
    {
        if ($this->shippedTurnoverPence === []) {
            return; // setUp() never got as far as stashing it; nothing authored, nothing to undo.
        }

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::getContainer()
            ->get(GameConfigRepository::class)
            ->getConfig(flush: true)
            ->setEconomyBaselineTurnoverPence($this->shippedTurnoverPence);

        $em->flush();
    }

    /** @return list<string> */
    private function codes(): array
    {
        return [
            self::RESERVED, self::OVERFILLED, self::UNDERFILLED, self::DERIVING,
            self::POOLED, self::PACKED, self::LIVE_EN, self::LIVE_SC,
        ];
    }

    private function purge(): void
    {
        $c = self::getContainer()->get(Connection::class);

        $c->executeStatement(
            'DELETE FROM country_world_pack_cache WHERE country IN (:c)',
            ['c' => $this->codes()],
            ['c' => ArrayParameterType::STRING],
        );
        $c->executeStatement(
            'DELETE FROM world_pack_set WHERE country IN (:c)',
            ['c' => $this->codes()],
            ['c' => ArrayParameterType::STRING],
        );
        $c->executeStatement(
            'DELETE FROM npc_club WHERE country IN (:c)',
            ['c' => $this->codes()],
            ['c' => ArrayParameterType::STRING],
        );
        $c->executeStatement(
            'DELETE FROM league WHERE country IN (:c)',
            ['c' => $this->codes()],
            ['c' => ArrayParameterType::STRING],
        );
        $c->executeStatement(
            'DELETE FROM country_profile WHERE code IN (:c)',
            ['c' => $this->codes()],
            ['c' => ArrayParameterType::STRING],
        );

        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @param array<int, int> $npcPerTier   tier => how many NPC club rows to create in it
     * @param array<int, int> $slotsPerTier tier => the authored column (0 = derive)
     */
    private function seed(string $country, array $npcPerTier, array $slotsPerTier, ?int $poolPence = null): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $profile = new CountryProfile($country, 'Probeland ' . $country);
        $profile->setEconomyFactorBp(10000);
        $profile->setLeagueTierCount(count($npcPerTier));
        $em->persist($profile);

        foreach ($npcPerTier as $tier => $count) {
            $league = new League($country, $tier, 'Probe ' . $tier);
            $league->setSlots($slotsPerTier[$tier]);
            $league->setTvPoolPence($poolPence);
            $em->persist($league);

            for ($i = 0; $i < $count; $i++) {
                $club = new NpcClub(
                    sprintf('Probe %s-%d-%d', $country, $tier, $i),
                    $country,
                    $tier,
                    40,
                    '#101010',
                    '#202020',
                    1_000_000,
                    [],
                );
                $club->setLeague($league);
                $em->persist($club);
            }
        }

        // A turnover table, or no tier resolves and the preview short-circuits before the
        // per-country checks are reached.
        // Stashed in setUp() and put back in tearDown() — see $shippedTurnoverPence.
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig(flush: true);
        $config->setEconomyBaselineTurnoverPence([38_866_153_952, 0, 0, 0, 0, 0, 0, 0]);

        $em->flush();
        $em->clear();
    }

    /**
     * The command's output with runs of whitespace collapsed.
     *
     * ⚠️ NOT COSMETIC. `SymfonyStyle::warning()` wraps its banner to the terminal width and pads
     * every line, so a message long enough to matter is split mid-sentence and no assertion on
     * it would ever match the raw display. Collapsing whitespace lets the tests assert on the
     * sentence a human reads rather than on wherever the renderer happened to break it.
     */
    private function normalised(CommandTester $tester): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $tester->getDisplay()));
    }

    private function runGate(string $country): CommandTester
    {
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:economy:wage-table'),
        );
        $tester->execute(['countries' => [$country]]);

        return $tester;
    }

    /** A READY pack set with one cached tier holding `$packClubs` clubs in its payload. */
    private function cachePack(string $country, int $tier, int $packClubs, ?int $payloadVersion = null): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $set = new WorldPackSet($country, WorldInitializationService::WORLD_PACK_VERSION, null, $tier);
        $set->setStatus(WorldPackSetStatus::READY);
        $em->persist($set);

        $clubs = [];
        for ($i = 0; $i < $packClubs; $i++) {
            $clubs[] = ['name' => sprintf('Pack %s-%d-%d', $country, $tier, $i), 'players' => []];
        }

        $em->persist(new CountryWorldPackCache(
            $set,
            $tier,
            ['clubs' => $clubs],
            $payloadVersion ?? WorldInitializationService::WORLD_PACK_VERSION,
        ));

        $em->flush();
        $em->clear();
    }

    // ── ⚠️ THE TRAP: a reserved bottom tier is NOT drift ─────────────────

    /**
     * THE CASE A NAIVE CHECK GETS WRONG, AND THE REASON THIS TEST FILE EXISTS. Tier 3 is the
     * deepest and holds one fewer row than tier 2, which is the player's reserved place. Every
     * tier agrees with its authored 16 and the check must say NOTHING about any of them —
     * including, specifically, the bottom one.
     */
    public function testAReservedBottomTierIsNotReportedAsDrift(): void
    {
        $this->seed(self::RESERVED, [1 => 16, 2 => 16, 3 => 15], [1 => 16, 2 => 16, 3 => 16]);

        $out = $this->normalised($this->runGate(self::RESERVED));

        self::assertStringContainsString(self::TABLE_HEADING, $out, 'the check did not run at all');
        self::assertStringNotContainsString(self::FAIL_PHRASE, $out, 'a by-design reserved place was failed');
        self::assertStringNotContainsString(self::WARN_PHRASE, $out, 'a by-design reserved place was warned about');
    }

    /**
     * And the detection must be visible in the table, not merely in its effect: a future reader
     * needs to see that the reservation was found rather than wonder why 15 rows passed against
     * a size of 16.
     */
    public function testTheTableSaysTheReservationWasDetected(): void
    {
        $this->seed(self::RESERVED, [1 => 16, 2 => 16, 3 => 15], [1 => 16, 2 => 16, 3 => 16]);

        $out = $this->normalised($this->runGate(self::RESERVED));

        self::assertStringContainsString('reserved', $out);
        self::assertStringContainsString('DETECTED, not assumed', $out);
    }

    // ── Rows outnumber the slots: FAILS ──────────────────────────────────

    /**
     * THE OWNER'S OWN SCENARIO: a tier regenerated at 20 clubs against an authored 16. The pool
     * is divided 16 ways and paid to 20 clubs. This is the half that fails, because there is no
     * benign state that produces it.
     */
    public function testRowsOutnumberingTheAuthoredSizeIsReportedWithBothFigures(): void
    {
        $this->seed(self::OVERFILLED, [1 => 20], [1 => 16]);

        $out = $this->normalised($this->runGate(self::OVERFILLED));

        self::assertStringContainsString(self::FAIL_PHRASE, $out);
        self::assertStringContainsString('YB tier 1: 20 NPC club rows exist against an authored division size of 16', $out);
        // It names the number a human should author, rather than only that something is wrong.
        self::assertStringContainsString('Author league.slots to 20', $out);
    }

    public function testRowsOutnumberingTheAuthoredSizeFailsTheCommand(): void
    {
        // It is a GATE: this has to move the exit code, not just print a line.
        $this->seed(self::OVERFILLED, [1 => 20], [1 => 16]);

        self::assertSame(1, $this->runGate(self::OVERFILLED)->getStatusCode());
    }

    // ── Slots outnumber the rows: WARNS ──────────────────────────────────

    /**
     * A half-filled tier, which is what every country looks like partway through
     * `BootstrapCountryCommand` — it creates the leagues and then fills them. Reported, because
     * a finished generation that landed here IS drift, but not as a release blocker, because a
     * gate that goes red on a box doing nothing wrong is a gate nobody reads.
     */
    public function testAnUnderFilledTierWarnsRatherThanFailing(): void
    {
        $this->seed(self::UNDERFILLED, [1 => 10], [1 => 16]);

        $out = $this->normalised($this->runGate(self::UNDERFILLED));

        self::assertStringContainsString(self::WARN_PHRASE, $out, 'an under-filled tier said nothing');
        self::assertStringContainsString(
            'YC tier 1: authored size 16 against a real size of 10 (10 NPC club rows) — short by 6',
            $out,
        );
        self::assertStringNotContainsString(
            self::FAIL_PHRASE,
            $out,
            'an under-filled tier was reported as the provably-wrong direction',
        );
    }

    // ── Under-filled WITH a pool: money evaporates, so it FAILS ─────────

    /**
     * ⚠️ THE CASE THAT CHANGED THE FAIL/WARN RULE. An under-filled tier is ambiguous only while
     * nothing is being divided. Authoring a pool is the act that turns the size into a divisor,
     * and from that moment the shortfall is real and **invisible**: the pool is split 14 ways,
     * 12 clubs are each paid a 14-way share, and the remaining two shares reach nobody at all.
     * Scotland tier 7 on the owner's device, 2026-11-19: £357,142 a club instead of £416,666,
     * and £714,285 of a £5,000,000 pool paid to no one. Nobody complains, because the money
     * that went missing was never anybody's.
     */
    public function testAnUnderFilledTierWithATvPoolFailsInsteadOfWarning(): void
    {
        $this->seed(self::POOLED, [1 => 12], [1 => 14], poolPence: 500_000_000);

        $tester = $this->runGate(self::POOLED);
        $out    = $this->normalised($tester);

        self::assertSame(1, $tester->getStatusCode(), 'an evaporating pool did not fail the gate');
        self::assertStringContainsString(
            'YE tier 1: an authored division size 14 against a real size of 12 (12 NPC club rows) — short by 2',
            $out,
        );
        // The three figures a human needs: what they get, what they should get, what vanishes.
        self::assertStringContainsString('£357,142.85', $out, 'the 14-way share');
        self::assertStringContainsString('£416,666.66', $out, 'the correct 12-way share');
        self::assertStringContainsString('£714,285.80', $out, 'the money that reaches nobody');
        self::assertStringNotContainsString(self::WARN_PHRASE, $out, 'it was downgraded to a warning');
    }

    // ── ⚠️ THE DEPLOYED SHAPES, AND THE DRIFT MUST DECOMPOSE ─────────────

    /**
     * ENGLAND AS DEPLOYED, measured off the 2026-11-19 world export: `slots` 16 at every tier,
     * 16 NPC rows at tiers 1-7 and **15 at tier 8**. The raw row difference at tier 8 is 1 and
     * **the drift is 0**, because that one is the player's reserved place — detected, because
     * tier 8 holds exactly one fewer row than tier 7. ⚠️ **A check that reported England's
     * bottom tier as short by 1 would be reporting the generator working correctly**, which is
     * the §3a trap on the real deployed data rather than on a synthetic fixture.
     */
    public function testTheDeployedEnglandShapeIsCleanIncludingItsReservedBottomTier(): void
    {
        $this->seed(
            self::LIVE_EN,
            [1 => 16, 2 => 16, 3 => 16, 4 => 16, 5 => 16, 6 => 16, 7 => 16, 8 => 15],
            [1 => 16, 2 => 16, 3 => 16, 4 => 16, 5 => 16, 6 => 16, 7 => 16, 8 => 16],
            poolPence: 500_000_000,
        );

        $out = $this->normalised($this->runGate(self::LIVE_EN));

        self::assertStringContainsString(self::TABLE_HEADING, $out, 'the check did not run');
        self::assertStringNotContainsString('short by', $out, 'England was reported as drifted');
        self::assertStringNotContainsString(self::FAIL_PHRASE, $out);
        self::assertStringNotContainsString(self::WARN_PHRASE, $out);
    }

    /**
     * SCOTLAND AS DEPLOYED: `slots` 14 at every tier against **12** NPC rows at tiers 1-7 and
     * **11** at tier 8. Two assertions that are the whole point of the reservation rule:
     *
     *  - tiers 1-7 are short by **2**;
     *  - tier 8's raw difference is **3**, and the reported drift must be **2** — the
     *    reservation accounts for exactly one of the three, so the division really fields 12.
     *
     * Getting tier 8 wrong by one would send somebody authoring 11 and permanently losing the
     * player's place in Scotland's bottom division.
     */
    public function testTheDeployedScotlandShapeIsShortByTwoAtEveryTierIncludingTheReservedOne(): void
    {
        $this->seed(
            self::LIVE_SC,
            [1 => 12, 2 => 12, 3 => 12, 4 => 12, 5 => 12, 6 => 12, 7 => 12, 8 => 11],
            [1 => 14, 2 => 14, 3 => 14, 4 => 14, 5 => 14, 6 => 14, 7 => 14, 8 => 14],
            poolPence: 500_000_000,
        );

        $tester = $this->runGate(self::LIVE_SC);
        $out    = $this->normalised($tester);

        self::assertSame(1, $tester->getStatusCode(), 'Scotland did not fail the gate');

        for ($tier = 1; $tier <= 7; $tier++) {
            self::assertStringContainsString(
                sprintf('YH tier %d: an authored division size 14 against a real size of 12 (12 NPC club rows) — short by 2', $tier),
                $out,
                "tier {$tier} did not report a drift of 2",
            );
        }

        // ⚠️ THE ONE THAT MATTERS: 11 rows, drift 2, NOT 3.
        self::assertStringContainsString(
            'YH tier 8: an authored division size 14 against a real size of 12 (11 NPC club rows plus a reserved player place) — short by 2',
            $out,
            'the reserved place was not subtracted from the bottom tier drift',
        );
        self::assertStringNotContainsString('short by 3', $out, 'the reservation was counted as drift');
    }

    // ── The THIRD count: the cached pack the device actually plays ────────

    /**
     * ⚠️ COMPARING `slots` AGAINST THE ROWS ALONE WOULD PASS HERE WHILE THE DEVICE IS WRONG.
     * A world pack is a CACHE of the NPC rows and nothing invalidates it when those rows change,
     * so the division a device fields can differ from both the authored size and the rows. Here
     * `slots` and the rows agree at 14 — the first two counts are clean — and the cached pack
     * holds 12. A check that went green on this would license exactly the belief that caused the
     * live defect.
     */
    public function testAStalePackIsReportedEvenWhenSlotsAndRowsAgree(): void
    {
        $this->seed(self::PACKED, [1 => 14], [1 => 14], poolPence: 500_000_000);
        $this->cachePack(self::PACKED, 1, packClubs: 12);

        $tester = $this->runGate(self::PACKED);
        $out    = $this->normalised($tester);

        // The first two counts agree, so the slots-vs-rows half must stay silent...
        self::assertStringNotContainsString(self::FAIL_PHRASE, $out, 'the rows-over branch fired');
        self::assertStringNotContainsString('short by', $out, 'the slots-vs-rows half reported drift');

        // ...and the pack comparison must be the thing that speaks.
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('the cached world pack', $out);
        self::assertStringContainsString('holds 12 clubs but 14 NPC club rows exist', $out);
        // The remedy is the pack, NOT authoring slots down to match a cache.
        self::assertStringContainsString('REGENERATE the pack, do not author', $out);
    }

    /**
     * A payload at an old `payload_version` is already treated as a cache MISS and will be
     * rebuilt, so reporting it is noise about a cache that is about to be discarded.
     */
    public function testAPackAtAnOldPayloadVersionIsIgnored(): void
    {
        $this->seed(self::PACKED, [1 => 14], [1 => 14], poolPence: 500_000_000);
        $this->cachePack(self::PACKED, 1, packClubs: 12, payloadVersion: 1);

        $out = $this->normalised($this->runGate(self::PACKED));

        self::assertStringContainsString('stale version — ignored', $out);
        self::assertStringNotContainsString('holds 12 clubs but 14 NPC club rows exist', $out);
    }

    /**
     * ⚠️ AND WHEN THERE IS NO PACK AT ALL, THAT IS SAID OUT LOUD. Silence would read as "the
     * pack agrees", which is the same false reassurance in a different costume.
     */
    public function testNoCachedPackIsReportedAsAnAbsenceOfEvidence(): void
    {
        $this->seed(self::PACKED, [1 => 14], [1 => 14], poolPence: 500_000_000);

        $out = $this->normalised($this->runGate(self::PACKED));

        self::assertStringContainsString('Cached world packs for YF: none', $out);
        self::assertStringContainsString('could still field a different division', $out);
    }

    // ── `slots = 0` is derived, and must not be called authored ──────────

    /**
     * ⚠️ THIS PINS A LATENT DEFECT THIS TASK DELIBERATELY DOES NOT FIX.
     * `LeagueSizeResolver::sizeOf()`'s derive branch adds 1 at the deepest tier
     * UNCONDITIONALLY, where the migration's rule detects the reservation. A single-tier country
     * cannot show a reservation, so the resolver claims 9 where 8 clubs play — and the check
     * surfaces it as an under-filled tier rather than being broken by it, which is the outcome
     * that lets the resolver be left alone. The row must also say `derived`, not `authored`: the
     * figure is nobody's decision and must not be reported as one.
     */
    public function testADerivedSizeIsLabelledDerivedAndSurfacesTheResolversUnconditionalPlusOne(): void
    {
        $this->seed(self::DERIVING, [1 => 8], [1 => 0]);

        $out = $this->normalised($this->runGate(self::DERIVING));

        self::assertStringContainsString(
            'YD tier 1: derived size 9 against a real size of 8 (8 NPC club rows) — short by 1',
            $out,
        );
        self::assertStringNotContainsString('YD tier 1: authored', $out, 'a derived size was reported as authored');
    }

    // ── It runs on a clean country too ───────────────────────────────────

    /**
     * ON A BOX WHERE EVERYTHING AGREES, AN EMPTY FINDING LIST IS INDISTINGUISHABLE FROM A CHECK
     * THAT NEVER RAN. The table is the evidence that it did, so it prints unconditionally and
     * that is asserted rather than assumed — this is the state the deployed box is expected to
     * be in, so it is also the state the check is least able to prove anything in.
     */
    public function testTheTablePrintsEvenWhenNothingIsWrong(): void
    {
        $this->seed(self::RESERVED, [1 => 16, 2 => 16, 3 => 15], [1 => 16, 2 => 16, 3 => 16]);

        $out = $this->normalised($this->runGate(self::RESERVED));

        self::assertStringContainsString(self::TABLE_HEADING, $out);
        self::assertStringContainsString('npc rows', $out);
        self::assertStringContainsString('real size', $out);
        self::assertStringContainsString('drift', $out);
    }

    /**
     * ⚠️ AND IT MUST NOT WRITE ANYTHING. The whole point of A4 was that the size stops being a
     * side effect; a check that "helpfully" corrected `slots` would reintroduce exactly the
     * defect `LeagueSizeResolver`'s docblock exists to prevent.
     */
    public function testTheCheckNeverWritesTheAuthoredSize(): void
    {
        $this->seed(self::OVERFILLED, [1 => 20], [1 => 16]);

        $this->runGate(self::OVERFILLED);

        $slots = (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT slots FROM league WHERE country = ? AND tier = 1',
            [self::OVERFILLED],
        );

        self::assertSame(16, $slots, 'the gate authored slots behind the operator');
    }
}
