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
 * `app:economy:wage-table`'s check 9 — the DERIVED division size against everything that should
 * match it.
 *
 * ── ⚠️ REWRITTEN 2026-11-19: THE OWNER RETIRED `league.slots` ────────────────────────────
 *
 * This file used to guard the opposite property. It said `league.slots` is authored on purpose,
 * because a divisor derived from live NPC rows moves whenever somebody clicks Generate and
 * silently re-prices a tier's TV money for every shipped build; the check existed because the
 * authored number could then drift from reality with nothing watching.
 *
 * **The owner heard that and ruled the other way:** *"there should be no wiring around the
 * amount of clubs, the finances should be dynamic"*, and then *"retire slots"*. `sizeOf()`
 * derives always. The measured reason: Scotland authored 14 at every tier against 12 NPC rows,
 * so each Scottish club was credited `pool / 14` in a division of twelve and the shares a
 * division credited itself fell £86,600,000.36 a season short of the pools they were authored
 * with. The authored number did not hold the divisor still; it held it wrong.
 *
 * ── WHAT IS GUARDED NOW ──────────────────────────────────────────────────────────────────
 *
 *  1. 🔴 **The ruling itself.** `testRowsOutnumberingTheRetiredColumnIsNoLongerADisagreement`
 *     and the two deployed-shape tests are the inverse of the tests they replace. They cannot
 *     pass alongside the old behaviour, which is deliberate: a change that restores the
 *     preference for `slots` fails here rather than quietly reverting a decision.
 *  2. 🟢 **England does not move.** 16 at all eight tiers before and after.
 *  3. 🔴 **Scotland moves 14 -> 12 at every tier**, bottom one included.
 *  4. 🔴 **A stale world pack still FAILS** — now the only way left to show a player a wrong
 *     divisor, and more likely than before rather than less.
 *  5. 🟡 **A stale `tv_deal` column WARNS** — the cost the owner accepted, made visible.
 *  6. 🟡 **An unreserved deepest tier is a SHAPE NOTE**, not a correction: the `+1` is
 *     unconditional because the player joins that division either way.
 *  7. ⚠️ **The command still writes nothing** — not `slots`, not `tv_deal`. That half of the old
 *     prohibition survives the ruling untouched, and both halves are pinned.
 */
class EconomyDerivedSizeDriftTest extends KernelTestCase
{
    /** Fresh synthetic codes — nothing else in the suite fixtures a `Y_` country. */
    private const RESERVED   = 'YA';   // a reserved deepest tier -> entirely silent
    private const OVERFILLED = 'YB';   // more rows than the retired column -> no longer a finding
    private const UNDERFILLED = 'YC';  // a pooled tier with NO npc rows -> must WARN
    private const DERIVING   = 'YD';   // an UNRESERVED deepest tier -> shape note
    private const POOLED     = 'YE';   // a stale tv_deal column -> must WARN
    private const PACKED     = 'YF';   // rows agree with the size, but the cached PACK does not
    private const LIVE_EN    = 'YG';   // the deployed England shape, measured 2026-11-19
    private const LIVE_SC    = 'YH';   // the deployed Scotland shape, measured 2026-11-19

    /** The message fragments the outcomes are distinguished by. */
    private const TABLE_HEADING = 'Division size, DERIVED from the NPC club rows that exist';
    /** The tripwire on the branch that is now unreachable by construction. */
    private const INVARIANT_PHRASE  = 'the size is supposed to BE the row count';
    private const UNRESERVED_PHRASE = 'no place was demonstrably reserved';
    private const STALE_CACHE_PHRASE = 'the stored tv_deal column reads';
    private const EMPTY_POOL_PHRASE  = 'authored against a tier with NO NPC club rows';

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
     * @param array<int, int> $slotsPerTier tier => the RETIRED column, which must change nothing
     * @param ?int            $tvDealPence  the stored cache. ⚠️ Omitted means FRESH: it is set
     *                                      to the share the derived size actually produces, so
     *                                      a fixture does not warn about staleness it did not
     *                                      mean to test. Pass a figure to make it stale.
     */
    private function seed(
        string $country,
        array $npcPerTier,
        array $slotsPerTier,
        ?int $poolPence = null,
        ?int $tvDealPence = null,
    ): void {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $profile = new CountryProfile($country, 'Probeland ' . $country);
        $profile->setEconomyFactorBp(10000);
        $profile->setLeagueTierCount(count($npcPerTier));
        $em->persist($profile);

        // The derivation's own rule, in the fixture, so a "fresh" cache means what the command
        // will compute: rows, plus the player's place at the country's deepest tier.
        $deepest = max(array_keys($npcPerTier));

        foreach ($npcPerTier as $tier => $count) {
            $league = new League($country, $tier, 'Probe ' . $tier);
            $league->setSlots($slotsPerTier[$tier]);
            $league->setTvPoolPence($poolPence);

            if ($tvDealPence !== null) {
                $league->setTvDeal($tvDealPence);
            } elseif ($poolPence !== null) {
                $size = $count + ($tier === $deepest ? 1 : 0);
                $league->setTvDeal($size > 0 ? intdiv($poolPence, $size) : null);
            }

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

    /**
     * ⚠️ **WHY THIS IS NOT `assertSame(0, $tester->getStatusCode())`.** These synthetic countries
     * are deliberately minimal — one turnover figure, no sponsorship ladder, no prize ratios —
     * so the command's OTHER eight checks fail on them and the exit code is 1 whatever check 9
     * does. Asserting the exit code would therefore assert nothing about this file's subject,
     * and an earlier draft of these tests did exactly that and read as six failures.
     *
     * So "check 9 did not fail the gate" is asserted the only way it can be: by slicing the
     * PROBLEMS section — everything the command treats as a release blocker — and showing that
     * none of check 9's own failure messages is in it. Its warnings are printed above that
     * section, so they survive the slice, which is the distinction being drawn.
     */
    private static function assertCheck9RaisedNoProblem(CommandTester $tester, string $message): void
    {
        $out      = trim((string) preg_replace('/\s+/u', ' ', $tester->getDisplay()));
        $at       = strpos($out, 'PROBLEMS');
        $problems = $at === false ? '' : substr($out, $at);

        foreach ([self::INVARIANT_PHRASE, 'the cached world pack'] as $failure) {
            self::assertStringNotContainsString($failure, $problems, $message);
        }
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

    // ── 🔴 THE RULING ITSELF: the retired column cannot change a size ─────

    /**
     * 🔴 **THE REGRESSION GUARD FOR THE OWNER'S RULING, 2026-11-19.** Twenty NPC club rows
     * against an authored 16 — the owner's own "regenerated a tier at 20 clubs" scenario, which
     * the check used to FAIL and which is now simply a 20-club division. Nothing is reported,
     * because nothing is wrong: the size IS the row count.
     *
     * ⚠️ This test is the inverse of the two it replaces
     * (`testRowsOutnumberingTheAuthoredSizeIsReportedWithBothFigures` and
     * `…FailsTheCommand`). They cannot both pass, which is the point: a change that restores
     * the old preference for `slots` turns this red rather than quietly reverting the decision.
     */
    public function testRowsOutnumberingTheRetiredColumnIsNoLongerADisagreement(): void
    {
        $this->seed(self::OVERFILLED, [1 => 20, 2 => 19], [1 => 16, 2 => 16]);

        $tester = $this->runGate(self::OVERFILLED);
        $out    = $this->normalised($tester);

        self::assertCheck9RaisedNoProblem($tester, 'a derived size was reported as a release blocker');
        self::assertStringContainsString(self::TABLE_HEADING, $out, 'the check did not run at all');
        self::assertStringNotContainsString(self::INVARIANT_PHRASE, $out, 'the unreachable branch fired');
        self::assertStringNotContainsString(self::UNRESERVED_PHRASE, $out);
    }

    /**
     * ⚠️ **AND THE OVERRULED ARGUMENT IS STILL ON SCREEN.** The old prohibition — *"it must
     * never be 'fixed' by making `sizeOf()` prefer the row count"* — was not deleted; it is
     * quoted in the method's docblock and its conclusion is stated in the table's own caption,
     * so somebody reading a division-size disagreement on a live box learns which of the two
     * arguments the code is making without having to open the source.
     */
    public function testTheTableSaysTheColumnIsRetiredRatherThanLeavingItAmbiguous(): void
    {
        $this->seed(self::RESERVED, [1 => 16, 2 => 16, 3 => 15], [1 => 16, 2 => 16, 3 => 16]);

        $out = $this->normalised($this->runGate(self::RESERVED));

        self::assertStringContainsString('league.slots is retired and no longer read', $out);
        self::assertStringContainsString('owner ruling, 2026-11-19', $out);
        self::assertStringContainsString('the +1 is unconditional', $out);
    }

    /**
     * ⚠️ **THE RETIRED COLUMN IS PRINTED, AND THAT IS DELIBERATE RATHER THAN NOSTALGIC.** It
     * still holds Scotland's 14 and still appears in any `world` export taken before the ruling,
     * so a reader reconciling an old file against a running box needs both numbers in one place
     * — the derived 12 and the dead 14 — and a word saying which is live.
     */
    public function testTheRetiredColumnIsPrintedBesideTheDerivedSize(): void
    {
        $this->seed(self::LIVE_SC, [1 => 12, 2 => 11], [1 => 14, 2 => 14], poolPence: 500_000_000);

        $out = $this->normalised($this->runGate(self::LIVE_SC));

        self::assertStringContainsString('slots (unread)', $out, 'the retired column lost its header');
        // The table row: tier 1, size 12, 12 rows, no reservation at a non-deepest tier, slots 14.
        self::assertStringContainsString('1 12 12 no 14', $out, 'the derived and retired figures are not side by side');
    }

    // ── ⚠️ THE DEPLOYED SHAPES — the whole point of the change ────────────

    /**
     * 🟢 **ENGLAND AS DEPLOYED, AND THE HALF THAT MUST NOT MOVE.** 16 NPC rows at tiers 1-7 and
     * **15 at tier 8** against an authored 16 throughout (world export, 2026-11-19). The
     * derivation reproduces 16 at all eight tiers — the reserved place at tier 8 accounts for
     * the one missing row — so retiring the column changed no English figure anywhere. ⚠️ This
     * is the test that makes "England is unaffected" a claim about code rather than a claim
     * about a spreadsheet.
     */
    public function testTheDeployedEnglandShapeIsUnchangedAndEntirelyClean(): void
    {
        $this->seed(
            self::LIVE_EN,
            [1 => 16, 2 => 16, 3 => 16, 4 => 16, 5 => 16, 6 => 16, 7 => 16, 8 => 15],
            [1 => 16, 2 => 16, 3 => 16, 4 => 16, 5 => 16, 6 => 16, 7 => 16, 8 => 16],
            poolPence: 500_000_000,
        );

        $tester = $this->runGate(self::LIVE_EN);
        $out    = $this->normalised($tester);

        self::assertCheck9RaisedNoProblem($tester, 'England was reported as a release blocker');
        self::assertStringContainsString(self::TABLE_HEADING, $out, 'the check did not run');
        self::assertStringNotContainsString(self::INVARIANT_PHRASE, $out);
        self::assertStringNotContainsString(self::UNRESERVED_PHRASE, $out);
        self::assertStringNotContainsString(self::STALE_CACHE_PHRASE, $out);
        self::assertStringNotContainsString(self::EMPTY_POOL_PHRASE, $out);

        // Every tier resolves to 16, against the authored 16 that is no longer read. The pair
        // of identical numbers is the evidence: England's figures do not move.
        for ($tier = 1; $tier <= 7; $tier++) {
            self::assertStringContainsString(sprintf('%d 16 16 no 16', $tier), $out, "tier {$tier} did not resolve to 16");
        }
        self::assertStringContainsString('8 16 15 yes 16', $out, 'tier 8 lost the reserved player place');
    }

    /**
     * 🔴 **SCOTLAND AS DEPLOYED — THE 14 -> 12 MOVE, WHICH IS WHAT THIS CHANGE IS FOR.** 12 NPC
     * rows at tiers 1-7 and **11 at tier 8** against an authored 14 throughout. Every tier must
     * now resolve to **12**, including the bottom one, where 11 rows plus the player's own place
     * make 12. Before the ruling each Scottish club was credited `pool / 14` in a division of
     * twelve and the shares a division credited itself fell £86,600,000.36 a season short of the
     * pools they were authored with.
     *
     * ⚠️ Nothing is REPORTED here, and that is the shape of the fix: the disagreement is gone,
     * not merely visible.
     */
    public function testTheDeployedScotlandShapeResolvesToTwelveAtEveryTier(): void
    {
        $this->seed(
            self::LIVE_SC,
            [1 => 12, 2 => 12, 3 => 12, 4 => 12, 5 => 12, 6 => 12, 7 => 12, 8 => 11],
            [1 => 14, 2 => 14, 3 => 14, 4 => 14, 5 => 14, 6 => 14, 7 => 14, 8 => 14],
            poolPence: 500_000_000,
        );

        $tester = $this->runGate(self::LIVE_SC);
        $out    = $this->normalised($tester);

        self::assertCheck9RaisedNoProblem($tester, 'Scotland is still a release blocker');

        for ($tier = 1; $tier <= 7; $tier++) {
            self::assertStringContainsString(sprintf('%d 12 12 no 14', $tier), $out, "tier {$tier} did not resolve to 12");
        }
        // ⚠️ THE ONE THAT MATTERS: 11 rows, size 12, NOT 14 and NOT 11. Getting it wrong by one
        // either way would lose the player's place in Scotland's bottom division or restore the
        // evaporating share.
        self::assertStringContainsString('8 12 11 yes 14', $out, 'the bottom tier is not 11 rows plus the player');
        self::assertStringNotContainsString(self::UNRESERVED_PHRASE, $out, 'Scotland does reserve a place');
    }

    // ── 🟡 THE NEW FINDING: a stale tv_deal column ───────────────────────

    /**
     * 🟡 **PRECISELY THE COST THE OWNER ACCEPTED, MADE VISIBLE.** `syncTvDeal()` runs only on an
     * admin save, so the moment the NPC rows move, the stored column encodes the old divisor.
     * **No player sees it** — `SyncService`, `InitializeController` and `LeagueService` all
     * derive the share at serve time — but four admin surfaces read it raw, so the back office
     * and a device would show different TV money with nothing to say which is live.
     *
     * Here a £5,000,000 pool sits on a twelve-club division whose column still holds the 14-way
     * share. Both figures must be named: what the column says and what is actually served.
     */
    public function testAStaleTvDealColumnWarnsAndNamesBothFigures(): void
    {
        $this->seed(
            self::POOLED,
            [1 => 12, 2 => 11],
            [1 => 14, 2 => 14],
            poolPence: 500_000_000,
            // The 14-way share, which is what the box is holding right now.
            tvDealPence: 35_714_285,
        );

        $out = $this->normalised($this->runGate(self::POOLED));

        self::assertStringContainsString(self::STALE_CACHE_PHRASE, $out, 'a stale cache said nothing');
        self::assertStringContainsString('£357,142.85', $out, 'the figure the column holds');
        self::assertStringContainsString('£416,666.66', $out, 'the figure every device is served');
        self::assertStringContainsString('STALE', $out, 'the table did not flag the cell');
    }

    /**
     * ⚠️ AND IT WARNS RATHER THAN FAILING. Nothing a player can reach is wrong, so reddening a
     * release gate over it would train people to ignore the gate — which is the failure mode
     * this whole command exists against.
     */
    public function testAStaleTvDealColumnDoesNotFailTheGate(): void
    {
        $this->seed(self::POOLED, [1 => 12, 2 => 11], [1 => 0, 2 => 0], poolPence: 500_000_000, tvDealPence: 35_714_285);

        self::assertCheck9RaisedNoProblem($this->runGate(self::POOLED), 'a cache nobody reads blocked a release');
    }

    /**
     * ⚠️ **AND THE CHECK MUST NOT "HELPFULLY" RESYNC IT.** The whole point of the old
     * prohibition that survives the ruling is that this command reports and never writes. A
     * check that corrected the column would hide the state it exists to surface, and would do it
     * from a read-only gate run.
     */
    public function testTheCheckNeverWritesTheTvDealColumn(): void
    {
        $this->seed(self::POOLED, [1 => 12, 2 => 11], [1 => 0, 2 => 0], poolPence: 500_000_000, tvDealPence: 35_714_285);

        $this->runGate(self::POOLED);

        $cached = (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT tv_deal FROM league WHERE country = ? AND tier = 1',
            [self::POOLED],
        );

        self::assertSame(35_714_285, $cached, 'the gate resynced tv_deal behind the operator');
    }

    // ── 🟡 THE ONE ASSUMPTION LEFT IN THE DERIVATION ─────────────────────

    /**
     * ⚠️ **THE UNRESERVED DEEPEST TIER — the only size the rows alone cannot corroborate.**
     * `sizeOf()` adds the player's place at the deepest tier UNCONDITIONALLY, because the
     * player's club is in that division whether or not the generator left a gap: `rows + 1` is
     * what the device assembles either way. So a deepest tier holding the SAME count as the tier
     * above is not a defect and is not corrected — but it is the one configuration where nothing
     * in the data confirms the extra place, so it is said out loud rather than left to look like
     * an off-by-one to the next reader.
     *
     * The old check used the opposite convention: it DETECTED the reservation and treated an
     * unreserved bottom tier as drift, because its job was deciding what to author. That rule
     * was right for authoring and is wrong for a participant count.
     *
     * ⚠️ No country in the 2026-11-19 export is in this shape — all 25 reserve a place at tier
     * 8 — so this is latent, which is exactly why it is pinned here rather than trusted.
     */
    public function testAnUnreservedDeepestTierIsAShapeNoteAndKeepsTheUnconditionalPlusOne(): void
    {
        $this->seed(self::DERIVING, [1 => 8, 2 => 8], [1 => 0, 2 => 0]);

        $out = $this->normalised($this->runGate(self::DERIVING));

        self::assertStringContainsString(self::UNRESERVED_PHRASE, $out, 'the unconfirmed place said nothing');
        self::assertStringContainsString('The size is still 9', $out, 'the +1 was made conditional');
        self::assertStringContainsString('2 9 8 no', $out, 'the table did not carry the derived 9');
    }

    public function testAnUnreservedDeepestTierDoesNotFailTheGate(): void
    {
        // It is the correct divisor, merely an uncorroborated one. Failing would redden a gate
        // over a country that is working exactly as designed.
        $this->seed(self::DERIVING, [1 => 8, 2 => 8], [1 => 0, 2 => 0]);

        self::assertCheck9RaisedNoProblem($this->runGate(self::DERIVING), 'an uncorroborated place blocked a release');
    }

    /**
     * ⚠️ AND A RESERVED DEEPEST TIER SAYS NOTHING AT ALL. This is the state every real country
     * is in, so it is also the state the check is least able to prove anything in — which is why
     * the table prints unconditionally.
     */
    public function testAReservedDeepestTierIsSilent(): void
    {
        $this->seed(self::RESERVED, [1 => 16, 2 => 16, 3 => 15], [1 => 16, 2 => 16, 3 => 16]);

        $out = $this->normalised($this->runGate(self::RESERVED));

        self::assertStringContainsString('reserved', $out, 'the column vanished');
        self::assertStringContainsString('DETECTED and informational', $out);
        self::assertStringNotContainsString(self::UNRESERVED_PHRASE, $out, 'a by-design reserved place was reported');
    }

    // ── 🟡 A POOL AUTHORED AGAINST A TIER WITH NO CLUBS ──────────────────

    /**
     * `sizeOf()` returns 0 for a tier with no NPC rows, deliberately, so `derivedTvDealPence()`
     * refuses rather than publishing a whole pool as one club's share. The consequence is that
     * every serve path falls back to the stored column — so a pool authored here publishes a
     * figure that has nothing to do with it. `BootstrapCountryCommand` creates a country's
     * leagues and then fills them, so this is a normal mid-generation state and WARNS.
     */
    public function testAPooledTierWithNoNpcRowsWarnsRatherThanFailing(): void
    {
        $this->seed(self::UNDERFILLED, [1 => 0, 2 => 10], [1 => 0, 2 => 0], poolPence: 500_000_000);

        $tester = $this->runGate(self::UNDERFILLED);
        $out    = $this->normalised($tester);

        self::assertStringContainsString(self::EMPTY_POOL_PHRASE, $out, 'an empty pooled tier said nothing');
        self::assertCheck9RaisedNoProblem($tester, 'a mid-bootstrap country was turned into a release blocker');
    }

    // ── 🔴 THE REMAINING FAILURE: the cached pack the device plays ────────

    /**
     * 🔴 **THE ONE WAY LEFT TO PUT A WRONG DIVISOR IN FRONT OF A PLAYER, AND IT GOT MORE LIKELY
     * RATHER THAN LESS.** A world pack is a CACHE of the NPC rows and nothing invalidates it
     * when those rows change. Before the ruling the divisor was a stored number that moved only
     * when somebody authored it; now it follows the rows the instant they change, while the pack
     * stays exactly as it was. Here the rows read 14 and the cached pack holds 12 — a device
     * fields twelve clubs while every finance divides by fourteen, which is the 2026-11-19 live
     * defect reproduced from the other direction.
     */
    public function testAStalePackIsReportedAndFailsTheGate(): void
    {
        $this->seed(self::PACKED, [1 => 14, 2 => 13], [1 => 0, 2 => 0], poolPence: 500_000_000);
        $this->cachePack(self::PACKED, 1, packClubs: 12);

        $tester = $this->runGate(self::PACKED);
        $out    = $this->normalised($tester);

        self::assertSame(1, $tester->getStatusCode(), 'a stale pack did not fail the gate');
        self::assertStringContainsString('the cached world pack', $out);
        self::assertStringContainsString('holds 12 clubs but 14 NPC club rows exist', $out);
        // ⚠️ THE REMEDY LINE, REWRITTEN WITH THE RULING. It used to read "do not author
        // league.slots to match a cache"; there is no authored size to reach for any more, so
        // the message says so rather than naming a dead column as a temptation.
        self::assertStringContainsString('REGENERATE the pack', $out);
        self::assertStringContainsString('league.slots was retired on 2026-11-19', $out);
        // And the size half must stay silent: the rows and the derived size always agree.
        self::assertStringNotContainsString(self::INVARIANT_PHRASE, $out);
    }

    /**
     * A payload at an old `payload_version` is already treated as a cache MISS and will be
     * rebuilt, so reporting it is noise about a cache that is about to be discarded.
     */
    public function testAPackAtAnOldPayloadVersionIsIgnored(): void
    {
        $this->seed(self::PACKED, [1 => 14, 2 => 13], [1 => 0, 2 => 0], poolPence: 500_000_000);
        $this->cachePack(self::PACKED, 1, packClubs: 12, payloadVersion: 1);

        $out = $this->normalised($this->runGate(self::PACKED));

        self::assertStringContainsString('stale version — ignored', $out);
        self::assertStringNotContainsString('holds 12 clubs but 14 NPC club rows exist', $out);
    }

    /**
     * ⚠️ AND WHEN THERE IS NO PACK AT ALL, THAT IS SAID OUT LOUD. Silence would read as "the
     * pack agrees", which is the same false reassurance in a different costume — and since the
     * pack is now the only count that can be wrong, it is the costume that matters most.
     */
    public function testNoCachedPackIsReportedAsAnAbsenceOfEvidence(): void
    {
        $this->seed(self::PACKED, [1 => 14, 2 => 13], [1 => 0, 2 => 0], poolPence: 500_000_000);

        $out = $this->normalised($this->runGate(self::PACKED));

        self::assertStringContainsString('Cached world packs for YF: none', $out);
        self::assertStringContainsString('could still field a different division', $out);
    }

    // ── It runs on a clean country too ───────────────────────────────────

    /**
     * ON A BOX WHERE EVERYTHING AGREES, AN EMPTY FINDING LIST IS INDISTINGUISHABLE FROM A CHECK
     * THAT NEVER RAN. The table is the evidence that it did, so it prints unconditionally and
     * that is asserted rather than assumed.
     */
    public function testTheTablePrintsEvenWhenNothingIsWrong(): void
    {
        $this->seed(self::RESERVED, [1 => 16, 2 => 16, 3 => 15], [1 => 16, 2 => 16, 3 => 16]);

        $out = $this->normalised($this->runGate(self::RESERVED));

        self::assertStringContainsString(self::TABLE_HEADING, $out);
        self::assertStringContainsString('npc rows', $out);
        self::assertStringContainsString('slots (unread)', $out);
        self::assertStringContainsString('tv_deal cache', $out);
    }

    /**
     * ⚠️ AND IT MUST NOT WRITE THE RETIRED COLUMN EITHER. The column is left in the database
     * deliberately, unread — a check that zeroed it to "tidy up" would destroy the one thing it
     * is still good for, which is reconciling an older world export against a running box.
     */
    public function testTheCheckNeverWritesTheRetiredColumn(): void
    {
        $this->seed(self::OVERFILLED, [1 => 20, 2 => 19], [1 => 16, 2 => 16]);

        $this->runGate(self::OVERFILLED);

        $slots = (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT slots FROM league WHERE country = ? AND tier = 1',
            [self::OVERFILLED],
        );

        self::assertSame(16, $slots, 'the gate rewrote the retired column');
    }
}
