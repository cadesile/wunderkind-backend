<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameConfig;
use App\Enum\CompanySize;
use App\Enum\EconomyWageModel;
use App\Repository\GameConfigRepository;
use App\Repository\LeagueRepository;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\LeagueSizeResolver;
use App\Service\Economy\SponsorBandResolver;
use App\Service\Economy\TicketPriceResolver;
use App\Service\Economy\WageModelResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What the turnover wage model would pay, per tier, BEFORE anybody switches it on.
 *
 * THIS IS THE GATE ON STEP 9'S RUNBOOK, NOT A DEBUGGING AID. Flipping `economyWageModel`
 * re-prices every player the world generates from then on, and the cached packs that hold those
 * wages have no TTL — so the flip is a one-way door per country until somebody notices. The
 * only way to know the numbers are right is to read them, which is what this prints.
 *
 * IT READS THE LIVE CONFIG ROWS. Squad sizes come from `starterConfig.npcSquadConfig`, ability
 * bands from `starterConfig.leagueAbilityRanges`, and every economy knob from `game_config` —
 * deliberately not from arguments. A preview computed from numbers typed on the command line
 * would be a preview of nothing: the whole question is what the STORED configuration produces.
 *
 * ── THE NINE CHECKS ───────────────────────────────────────────────────────────────────────
 *
 * A table of plausible-looking numbers can still be wrong in ways that are invisible row by
 * row, so the command answers nine questions it would be easy not to ask:
 *
 *  1. **Is `S_tier` strictly decreasing?** It has to be, or a lower division pays more per unit
 *     of ability than the one above it.
 *  2. **Does any ability earn more at a LOWER tier?** The bands overlap, so a non-monotonic
 *     `S_tier` means a promoted player can take a pay cut. This checks the overlaps directly
 *     rather than inferring it from (1).
 *  3. **Is TV money a sane fraction of turnover?** Tier-1 TV currently exceeds any plausible
 *     tier-1 turnover, which makes `wageToTurnoverRatio` meaningless — the TV table has to be
 *     re-authored before the model goes on.
 *  4. **Does the squad bill actually match the configured share?** The arithmetic is checked
 *     against its own definition, so a rounding error in the chain shows up here rather than as
 *     a slowly-drifting wage bill.
 *  5. **Do the absolute-pence thresholds still mean anything?** `bankruptcyDeductionTiers` is
 *     flat pence while wages scale, so a country factor or a model flip silently decouples
 *     them — and overlapping unbounded rows are unreachable under the documented
 *     first-match-wins contract. See docs/economy-threshold-review.md.
 *  6. **Is the TV table scaled by the country factor?** `league.tv_deal` is the one money
 *     figure the factor chain never touches — it is a stored absolute — so a factored country
 *     would pay a fifth of the wages and collect full baseline TV money. Check 3 cannot see
 *     this: TV at five times its intended share still passes a bound of 100% of turnover.
 *  7. **Is sponsorship a believable share of the turnover it is authored against?** Club plus
 *     league sponsorship between 8% and 30% at every tier, the bottom division no more than 3x
 *     the top, and non-increasing ratios. **The COVERAGE figure is the APP's** — this prints
 *     the sponsorship lines only; `scripts/economy/variantGate.ts` in `wunderkind-app` is the
 *     acceptance test, because modelled income needs a ground model, fill and elasticity that
 *     this backend does not have.
 *  8. **Do the league payout ladders cost a division something sane?** `prize_money` and
 *     `league_position_pot` are PER-CLUB figures that read like pools, and the authored ladder
 *     inverted at tier 3 — a tier-8 champion was paid 30.33x its own authored turnover against
 *     tier 1's 0.26x. Four constraints: both ladders non-increasing; TV + position + prize per
 *     average club inside 10-50% of turnover with the two ends of the pyramid within 4x either
 *     way; a champion's own take (prize + first place) at or under 100% of its turnover; and
 *     the prize-plus-position contribution FLAT across tiers, which is the invariant the ladder
 *     creates and the only one of the four the TV ladder cannot drown out. The band is 10-50%
 *     rather than the 25-75% first proposed, for a reason the constant's own docblock gives.
 *
 *  9. **Does the DERIVED division size still agree with everything that should match it?**
 *     ⚠️ **Rewritten 2026-11-19: the owner retired `league.slots` and `sizeOf()` now derives
 *     always**, so the old authored-vs-rows comparison has no content left — the method's own
 *     docblock carries the overruled argument verbatim rather than deleting it. Two
 *     disagreements remain. It FAILS on a **stale world pack**, the one way left to put a wrong
 *     divisor in front of a player: the divisor follows the NPC rows instantly and the cached
 *     pack does not. It WARNS on a **stale `tv_deal` column** (no player reads it — every serve
 *     path derives — but four admin surfaces do, so the back office and a device would disagree),
 *     on a **pooled tier with no NPC rows at all**, and on a **deepest tier that reserved no
 *     place for the player**, which is the one size the rows alone cannot corroborate. It still
 *     writes nothing: that half of the old prohibition stands.
 *
 * The two deduction-ladder checks inside (5) read country-independent columns and therefore run
 * ONCE for the whole invocation, not once per country argument — see checkDeductionLadders().
 * That is a fix, not a detail: they used to run inside the per-country loop, so the finding
 * count rose with the number of countries asked for and nothing on screen explained it.
 *
 * Exit code 1 when any check fails, so this can gate a deploy rather than just inform one.
 */
#[AsCommand(
    name: 'app:economy:wage-table',
    description: 'Print what the turnover wage model would pay per tier, and flag anything inconsistent',
)]
final class EconomyWageTableCommand extends Command
{
    /** Shown alongside England by default, because a shallow pyramid is the other shape to check. */
    private const DEFAULT_COUNTRIES = ['EN', 'SC'];

    /**
     * The sponsorship band the owner review settled on, as a share of authored turnover.
     *
     * 8% because below it the mechanic is decoration; 30% because above it sponsorship crowds
     * out matchday and TV and the wage anchor starts following sponsorship rather than the
     * turnover ladder it is defined against. The tier-1 target inside that band is 20-25%.
     */
    private const SPONSOR_SHARE_MIN_PCT = 8;
    private const SPONSOR_SHARE_MAX_PCT = 30;

    /**
     * How much more of its turnover the BOTTOM division may draw from sponsorship than the top.
     *
     * Not 1: sponsorship is supposed to matter relatively more where there is no TV money. But
     * past about 3x the bottom of the pyramid is a sponsorship game and the top is a TV game,
     * which is two economies rather than one ladder.
     */
    private const SPONSOR_SHARE_SPREAD = 3;

    /**
     * What TV + position money + the champion's prize may come to for an AVERAGE club in a
     * division, as a share of that club's own authored turnover.
     *
     * ⚠️ 10-50%, NOT THE 25-75% THE BRIEF PROPOSED, and the data is why. Once the prize and the
     * pot are a flat share of each tier's own turnover, those two lines contribute a CONSTANT
     * 9.29 percentage points at every tier by construction. Every point of tier-to-tier
     * variation left in this figure therefore comes from the TV ladder, which is monotone 35%
     * at tier 1 down to 3% at tier 8 and is deliberately out of scope.
     *
     * So a 25% floor is not a statement about prize money at all: it asserts that TV must be at
     * least about 15.7% of turnover at tier 8, five times what the TV table was authored to pay.
     * It would fail at tiers 4-8 on figures that are correct. These three lines are also not a
     * club's whole income — sponsorship is separately held to 8-30% of turnover by check 7 — so
     * 12.3% here plus 22% there plus matchday is a believable bottom-tier mix.
     *
     * 10-50% brackets the authored shape with headroom at both ends and still catches what was
     * actually wrong: tiers 6, 7 and 8 were at 157%, 362% and 542%.
     *
     * ── THE CEILING WENT 50 -> 55 ON 2026-11-18 (OWNER), AND HERE IS WHY ──────────────────────
     *
     * Authoring resolution R2 (`Version20261118120000`) put EN tier 1 at **50.38%** and tripped
     * this ceiling by 0.38 points. The cause is isolated and it is not the thing this check was
     * written to catch:
     *
     *  - **The payout half is innocent, provably.** Prize and pot are a flat **9.29% of turnover
     *    at BOTH ends** of the pyramid — exactly what `5dc3f51b`'s ratio ladder exists to hold,
     *    and what `PAYOUT_FLATNESS_TOLERANCE_PP` below independently confirms at 0.0065pp.
     *  - **The whole breach is TV's SHARE of a turnover figure that moved beneath it.**
     *    `league.tv_deal` is an authored ABSOLUTE. R2 cuts tier-1 turnover 15% (£388.7m ->
     *    £331m) and deliberately does not re-derive TV (`REMAINING-WORK.md` §2f.5), so TV's
     *    share goes 35.0% -> 41.1% with no figure in the TV table changing. A top flight drawing
     *    ~41% of its turnover from broadcast is normal for a broadcast-led division, not a
     *    defect.
     *
     * ⚠️ **AND EVERY ALTERNATIVE WAS COSTED AND IS WORSE.** Raising tier-1 turnover enough to
     * clear 50% needs £334.1m and STILL fails the spread at 4.31x, while dropping tier-1 COVERAGE
     * to 84.5% — below the app gate's 85-110% band. Clearing the spread by turnover alone needs
     * £366.8m and 77.2% coverage. Re-deriving tier-1 TV to hold its old 35% share clears both
     * checks but drops tier-1 coverage to 79.2%; holding coverage then forces T1 ~ £290m, which
     * tightens the check-1 chain to T2 <= £100.2m and re-opens every tier's figure.
     *
     * So 55 is the owner re-setting a bound that was shown to be mis-calibrated, NOT an
     * implementer quieting an alarm about their own authoring. The floor stays at 10 and
     * `CHAMPION_SHARE_MAX_PCT` stays at 100.
     */
    private const PAYOUT_SHARE_MIN_PCT = 10;
    private const PAYOUT_SHARE_MAX_PCT = 55;

    /**
     * How much more of its turnover the BOTTOM division may draw from these three lines than the
     * top, and vice versa.
     *
     * The brief asked for "the tier-8 share no more than 3x tier 1's", which the authored ladder
     * passes at 0.278x — tier 8 is LOWER, because TV collapses faster than the flat prize share
     * compensates. Checked in BOTH directions for that reason: the one-sided form would have
     * been silent on the real spread, which is tier 1 at 3.60x tier 8.
     *
     * ── THE CAP WENT 4 -> 5 ON 2026-11-18 (OWNER), FOR THE SAME REASON AND ONE OF ITS OWN ─────
     *
     * R2 took this spread to **4.34x** (tier 1 at 50.4% against tier 8 at 11.6%). The arithmetic
     * cause is the one `PAYOUT_SHARE_MAX_PCT` above sets out: turnover fell 15% at the top while
     * TV, an authored absolute, did not move.
     *
     * ⚠️ **BUT THIS CAP ALSO TURNED OUT TO BE MEASURING THE PYRAMID RATHER THAN A
     * MIS-AUTHORING.** The spread between these two shares is STRUCTURAL — a top flight is
     * broadcast-led and a bottom division is not, and EN tier 8's TV deal is **£4,950 a year**.
     * Capping the ratio of league-income shares between a £136,000,000 broadcast contract and a
     * £4,950 one was never a statement about whether the ladder was authored correctly, which is
     * what this check is for. The flat-share invariant below is the check that actually catches a
     * ladder drifting off its turnover table, and it passes with 0.0065pp of slack.
     *
     * 5 keeps the check alive — it still catches the 157%/362%/542% shape the ladder was authored
     * to fix, and it would still fire well before the two ends were running different economies —
     * while no longer failing on a correctly-authored pyramid. Costed alternatives: see
     * `PAYOUT_SHARE_MAX_PCT`.
     */
    private const PAYOUT_SHARE_SPREAD = 5;

    /**
     * The hard ceiling on what ONE CLUB can bank for winning its division: prize + first-place
     * position money, against its own authored turnover.
     *
     * 100% because past it a single season's trophy is worth more than everything else the club
     * does in a year, which is the defect this ladder was authored to fix — tier 8 was at
     * 4,246%. The authored ladder sits at 51.4-51.5% at every tier.
     */
    private const CHAMPION_SHARE_MAX_PCT = 100;

    /**
     * How far the prize-plus-position contribution may vary between tiers, in percentage points
     * of turnover.
     *
     * THE INVARIANT THIS LADDER CREATES, and the only check here that is blind to TV. Both
     * ladders are a flat share of each tier's own turnover, so this contribution is the same at
     * every tier — 9.29pp on the authored figures, varying by 0.008pp across all eight, which is
     * three-significant-figure rounding in the ratios and nothing else. A retune of the turnover
     * table without re-deriving the ratios breaks it immediately and visibly, which is the trap
     * the sponsor ratios already carry a warning about in the rollout doc. 0.5pp is generous
     * against a 0.008pp observed spread, and still an order of magnitude tighter than any drift
     * worth shipping.
     */
    private const PAYOUT_FLATNESS_TOLERANCE_PP = 0.5;

    public function __construct(
        private readonly GameConfigRepository    $gameConfigRepository,
        private readonly EconomyScaleResolver    $scaleResolver,
        private readonly WageModelResolver       $wageModel,
        private readonly LeagueRepository        $leagueRepository,
        private readonly \App\Repository\NpcClubRepository $npcClubRepository,
        private readonly \App\Repository\CountryWorldPackCacheRepository $worldPackCacheRepository,
        private readonly LeagueSizeResolver      $leagueSizeResolver,
        private readonly SponsorBandResolver     $sponsorBands,
        private readonly TicketPriceResolver     $ticketPrices,
        private readonly \App\Service\Economy\LeaguePayoutResolver $leaguePayouts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'countries',
                InputArgument::IS_ARRAY,
                'Country codes to table. Defaults to ' . implode(' and ', self::DEFAULT_COUNTRIES) . '.',
            )
            ->addOption(
                'reputation',
                null,
                InputOption::VALUE_REQUIRED,
                'Club reputation to price at. 50 is the calibration anchor, where the modifier is exactly 1.0.',
                50,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $config = $this->gameConfigRepository->getConfig();

        /** @var list<string> $countries */
        $countries = $input->getArgument('countries') ?: self::DEFAULT_COUNTRIES;
        $reputation = (int) $input->getOption('reputation');

        $this->printSettings($io, $config, $reputation);

        $problems = [];

        foreach ($countries as $raw) {
            $country = strtoupper(trim((string) $raw));
            $problems = [...$problems, ...$this->tableFor($io, $config, $country, $reputation)];
        }

        // ONCE, WHATEVER THE ARGUMENT LIST. These read country-independent `game_config`
        // columns; running them inside the loop above is what made the finding count rise with
        // the number of countries asked for and no explanation on screen.
        $problems = [...$problems, ...$this->checkDeductionLadders($config)];

        if ($problems !== []) {
            $io->section('PROBLEMS');
            $io->listing($problems);
            $io->error(sprintf('%d check(s) failed. Do not flip the wage model until these are resolved.', count($problems)));

            return Command::FAILURE;
        }

        $io->success('Every check passed.');

        return Command::SUCCESS;
    }

    private function printSettings(SymfonyStyle $io, GameConfig $config, int $reputation): void
    {
        $io->title('Economy wage table');

        $model = $config->getEconomyWageModel();
        if ($model !== EconomyWageModel::TURNOVER) {
            // Not a refusal: the whole point is to see the turnover numbers while `legacy` is
            // still live. Said out loud so nobody reads the table as what the world currently
            // holds.
            $io->warning(sprintf(
                'The live wage model is "%s". This table is what "turnover" WOULD pay — nothing generated so far was priced this way.',
                $model->value,
            ));
        }

        $io->definitionList(
            ['wage model'            => $model->value],
            ['weeks per season'      => (string) $config->getEconomyWeeksPerSeason()],
            ['wage : turnover'       => $this->pct($config->getEconomyWageToTurnoverRatioBp())],
            ['staff share of wages'  => $this->pct($config->getEconomyStaffWageShareBp())],
            ['curve exponent k'      => number_format($config->getEconomyWageCurveExponentBp() / 10000, 4)],
            ['reputation spread'     => $this->pct($config->getEconomyReputationSpreadBp())],
            ['priced at reputation'  => $reputation . ($reputation === 50 ? ' (the calibration anchor)' : ' (NOT the anchor — 50 is)')],
            ['baseline country'      => $this->baselineLabel()],
        );
    }

    /** @return list<string> problems found */
    private function tableFor(SymfonyStyle $io, GameConfig $config, string $country, int $reputation): array
    {
        $io->section($country);

        $tierCount = $this->scaleResolver->tierCountFor($country);
        $problems  = [];
        $rows      = [];
        $prevScale = null;
        $bands     = [];
        $scales    = [];

        for ($tier = 1; $tier <= $tierCount; $tier++) {
            $scale = $this->scaleResolver->resolve($country, $tier);
            $band  = $this->scaleResolver->abilityBand($country, $tier);
            $squad = $this->squadSize($tier);

            $bands[$tier]  = $band;
            $scales[$tier] = $scale->wageScaleMicroPence;

            if (!$scale->isResolvable) {
                $rows[] = [$tier, $squad, "{$band['min']}-{$band['max']}", '—', '—', '—', '—', '—', 'UNAUTHORED'];
                $problems[] = "{$country} tier {$tier}: baselineTurnoverPence is 0 — the turnover model cannot price this tier.";
                continue;
            }

            $min = $this->wageModel->turnoverPlayerPence($band['min'], $country, $tier, $reputation, $config) ?? 0;
            $mid = $this->wageModel->turnoverPlayerPence($this->mid($band), $country, $tier, $reputation, $config) ?? 0;
            $max = $this->wageModel->turnoverPlayerPence($band['max'], $country, $tier, $reputation, $config) ?? 0;

            // CHECK 1 — S_tier strictly decreasing.
            if ($prevScale !== null && $scale->wageScaleMicroPence >= $prevScale) {
                $problems[] = sprintf(
                    '%s tier %d: S_tier (%s) is not LOWER than tier %d (%s) — a lower division pays more per unit of ability.',
                    $country, $tier, $this->micro($scale->wageScaleMicroPence), $tier - 1, $this->micro($prevScale),
                );
            }
            $prevScale = $scale->wageScaleMicroPence;

            // CHECK 4 — the squad bill against its own definition.
            $bill   = $this->squadBillPence($country, $tier, $reputation, $config);
            $budget = $this->playerBudgetPence($scale->turnoverPence, $config);
            $drift  = $budget > 0 ? abs($bill - $budget) * 100 / $budget : 0.0;

            if ($drift > 2.0) {
                $problems[] = sprintf(
                    '%s tier %d: the reference squad costs %s against a budget of %s (%.1f%% out) — the chain is not self-consistent.',
                    $country, $tier, $this->money($bill), $this->money($budget), $drift,
                );
            }

            $rows[] = [
                $tier,
                $squad,
                "{$band['min']}-{$band['max']}",
                $this->micro($scale->wageScaleMicroPence),
                $this->money($min),
                $this->money($mid),
                $this->money($max),
                $this->money($scale->turnoverPence),
                sprintf('%.1f%%', $budget > 0 ? $bill * 100 / $scale->turnoverPence : 0.0),
            ];
        }

        $io->table(
            ['T', 'squad', 'band', 'S_tier (p)', 'min /wk', 'mid /wk', 'max /wk', 'turnover', 'bill % turnover'],
            $rows,
        );

        $problems = [...$problems, ...$this->checkAbilityInversions($country, $bands, $scales, $config)];
        $problems = [...$problems, ...$this->checkTvAgainstTurnover($io, $country, $tierCount, $config)];
        $problems = [...$problems, ...$this->checkTvIsCountryScaled($io, $country, $tierCount)];
        $problems = [...$problems, ...$this->checkAbsoluteThresholds($io, $country, $tierCount, $config)];
        $problems = [...$problems, ...$this->checkSponsorshipShares($io, $country, $tierCount, $config)];
        $problems = [...$problems, ...$this->checkLeaguePayouts($io, $country, $tierCount, $config)];
        $problems = [...$problems, ...$this->checkDerivedSizeAgainstRows($io, $country)];

        return $problems;
    }

    /**
     * CHECK 9 — does the DERIVED division size still agree with everything that should match it?
     *
     * ── ⚠️ THE PROHIBITION THIS DOCBLOCK USED TO CARRY HAS BEEN OVERRULED ─────────────────
     *
     * Until 2026-11-19 this block said, in as many words, that `league.slots` is authored on
     * purpose and **"this check does not write `slots`, and it must never be 'fixed' by making
     * `sizeOf()` prefer the row count."** The reasoning was sound and is worth keeping: a
     * divisor derived from live NPC rows moves whenever somebody clicks Generate, so adding two
     * clubs to a tier silently re-prices that tier's TV money for every shipped build, as a
     * side effect of an unrelated admin action.
     *
     * **The owner heard that argument and ruled against it.** *"The number of clubs in a country
     * league tier is something that can change, as we alter the cache and tune the system ahead
     * of go live, so there should be no wiring around the amount of clubs, the finances should be
     * dynamic."* And then, specifically: *"Retire slots."* `sizeOf()` now derives always.
     * `LeagueSizeResolver`'s class docblock records the decision and what it cost; this one
     * records what the check became, because a reader who arrives at a division-size
     * disagreement needs to know which of the two arguments the code is currently making.
     *
     * **It is still not a correction, and it still writes nothing.** That half of the old
     * prohibition survives untouched. What changed is what it compares.
     *
     * ── WHAT IS LEFT TO CHECK ONCE NOTHING IS AUTHORED ───────────────────────────────────
     *
     * The old check compared count 1 (authored `slots`) against count 2 (the NPC rows). That
     * comparison has no content any more: count 1 IS count 2, by construction, so asserting it
     * would only prove that `sizeOf()` and this method call the same repository. The two
     * disagreements that remain are both real and neither involves `slots`:
     *
     *  1. 🔴 **THE CACHED WORLD PACK against the rows it was built from.** A pack is a CACHE of
     *     `npc_club` rows and **nothing invalidates it when those rows change.** This was always
     *     the hardest of the three to see and it is now the ONLY one that can put a wrong
     *     divisor in front of a player: the divisor follows the rows the instant they change,
     *     and the pack does not. A device fielding a 12-club table while the server divides by
     *     14 is exactly the 2026-11-19 live defect, and after the ruling a stale pack is the one
     *     way left to reproduce it. See `comparePackCounts()`. **FAILS.**
     *
     *  2. 🟡 **THE `league.tv_deal` COLUMN against the share actually published.** This is the
     *     new one, and it is precisely the cost the owner accepted. Every path that serves a
     *     device computes the share at read time through `derivedTvDealPence()` —
     *     `SyncService:834`, `InitializeController:251`, `LeagueService:306`,
     *     `LeagueImportExportService:66` — so **no player ever sees the stored column.** But
     *     `syncTvDeal()` is called only from an admin save (`LeagueAdminController:95`/`:201`),
     *     so once the rows move, the column holds the old figure and four admin surfaces read it
     *     raw: `LeagueAdminController:127`, `EconomyAdminController:457`,
     *     `WorldOverviewService:98`, `LeagueTierDefaultDiff:68`. An operator comparing the admin
     *     screen against a device would see two different TV deals and have no way to tell which
     *     is live. **WARNS, not fails** — nothing a player can reach is wrong, and the remedy is
     *     a resave, not a release block.
     *
     * ── ⚠️ THE RESERVED PLAYER SLOT IS NOW INFORMATIONAL, NOT A CORRECTION INPUT ──────────
     *
     * `sizeOf()` adds 1 at a country's deepest tier UNCONDITIONALLY, because the player's own
     * club is in that division whether or not the generator left a gap for it — `rows + 1` is
     * what the device assembles (the pack's `clubs` array plus the player's club) and so it is
     * the right divisor either way.
     *
     * The old check used a different rule — `rows + 1` at the deepest tier **only when that tier
     * holds exactly one fewer row than the tier above**, which is `Version20261119120000`'s own
     * `CORRECT_SQL` in PHP. That rule was right for its job, which was deciding what to AUTHOR;
     * it is the wrong rule for a participant count. So the detection stays, and the table prints
     * it, but it no longer decides anything: a deepest tier that is NOT one row short of the
     * tier above is reported as a SHAPE note, because that is the one configuration where the
     * reservation cannot be corroborated from the data and a reader puzzled by `size 13` against
     * a 12-row pack deserves to be told why. ⚠️ **No country in the 2026-11-19 export is in
     * that shape** — all 25 reserve a place at tier 8 — so this is latent, not live. The
     * docblock that preceded this one claimed CA, JP, NG, PL, US and SC held a uniform count;
     * that was measured on 2026-11-12 and the caches have since been rebuilt. It is stale, which
     * is itself the argument for printing the shape rather than asserting it.
     *
     * ── AN EMPTY TIER WITH A POOL STILL WARNS ────────────────────────────────────────────
     *
     * `sizeOf()` returns 0 for a tier with no NPC rows, deliberately, so `derivedTvDealPence()`
     * can refuse rather than publish a whole pool as one club's share. The consequence is that
     * such a tier falls back to the stored `tv_deal` column on every serve path — so a pool
     * authored against an empty tier publishes a figure that has nothing to do with it.
     * `BootstrapCountryCommand` creates a country's leagues and then fills them, so this is a
     * normal mid-generation state and **warns** rather than failing.
     *
     * ── THE TABLE PRINTS EITHER WAY, AND IT CARRIES THE RETIRED COLUMN ───────────────────
     *
     * On a box where everything agrees, an empty finding list is indistinguishable from a check
     * that never ran. The table is the evidence that it did.
     *
     * ⚠️ It has a `slots (unread)` column, and that is deliberate rather than nostalgic. The
     * column still exists and still holds whatever it last held — Scotland's 14, England's 16 —
     * and it is still SERIALISED INTO NOTHING but visible in any `world` export taken before
     * 2026-11-19. Somebody comparing an old export's `slots 14` against a running box's 12 needs
     * the two numbers side by side and a word saying which is live. Printing it costs a column;
     * not printing it costs an afternoon.
     *
     * Scope note: this compares whichever countries were asked for, which is `DEFAULT_COUNTRIES`
     * (EN, SC) unless an operator names others — the rule is general because a general rule is
     * the right shape and costs nothing, not because the other 23 countries are in scope.
     *
     * @return list<string>
     */
    private function checkDerivedSizeAgainstRows(SymfonyStyle $io, string $country): array
    {
        $leagues = $this->leagueRepository->findByCountry($country);

        if ($leagues === []) {
            return [];
        }

        $counts     = $this->npcClubRepository->countsByLeagueForCountry($country);
        $rowsByTier = [];

        foreach ($leagues as $league) {
            $rowsByTier[$league->getTier()] = $counts[(string) $league->getId()] ?? 0;
        }

        // The deepest league the country ACTUALLY has, not `country_profile.league_tier_count` —
        // the same choice `LeagueSizeResolver::isBottomTier()` makes, and for the same reason:
        // the reserved place belongs to wherever a new club is really put.
        $deepest = max(array_keys($rowsByTier));

        $problems = [];
        $warnings = [];
        $rows     = [];

        foreach ($leagues as $league) {
            $tier  = $league->getTier();
            $here  = $rowsByTier[$tier];
            $above = $rowsByTier[$tier - 1] ?? null;

            // DETECTED, not assumed — and now informational only. See the docblock: `sizeOf()`
            // adds the place unconditionally because the player is there regardless.
            $reserved = $tier === $deepest
                && $above !== null
                && $here === $above - 1;

            $size     = $this->leagueSizeResolver->sizeOf($league);
            $pool     = $league->getTvPoolPence();
            $derived  = $this->leagueSizeResolver->derivedTvDealPence($league);
            $cached   = $league->getTvDeal();
            $slots    = $league->getSlots();

            // THE INVARIANT, KEPT AS A TRIPWIRE RATHER THAN DELETED. `sizeOf()` is `$here` plus
            // at most one, so rows can no longer outnumber the size — the branch that used to
            // carry the owner's "regenerated at 20 against an authored 16" scenario is
            // unreachable by construction. It stays because "unreachable by construction" is a
            // claim about code that can stop being true, and this is the cheapest place to find
            // out: anything that makes `sizeOf()` read a stored number again lands here first.
            if ($here > $size) {
                $verdict    = 'ROWS OVER';
                $problems[] = sprintf(
                    '%s tier %d: %d NPC club rows exist against a derived division size of %d — the size is supposed '
                    . 'to BE the row count, so something is resolving a size from outside the rows. '
                    . 'Start at LeagueSizeResolver::sizeOf(); it must not be reading league.slots (currently %d) again.',
                    $country,
                    $tier,
                    $here,
                    $size,
                    $slots,
                );
            } elseif ($size === 0) {
                // No rows. `derivedTvDealPence()` refuses, so every serve path falls back to the
                // stored column — a pool authored here publishes a figure unrelated to it.
                $verdict = $pool === null ? 'empty' : 'EMPTY, POOLED';

                if ($pool !== null) {
                    $warnings[] = sprintf(
                        '%s tier %d: a %s TV pool is authored against a tier with NO NPC club rows, so the size '
                        . 'resolves to 0 and no share can be derived. Every serve path falls back to the stored '
                        . 'tv_deal column (%s), which this pool did not produce. Normal while a country is still '
                        . 'being generated; a finding once it is finished.',
                        $country,
                        $tier,
                        $this->money($pool),
                        $cached === null ? 'null' : $this->money($cached),
                    );
                }
            } elseif ($tier === $deepest && !$reserved) {
                // THE SHAPE NOTE. The division fields `rows + 1` because the player joins it,
                // and that is correct — but it is the one tier where the data does not
                // corroborate the extra place, so it is said out loud rather than left to look
                // like an off-by-one to the next reader.
                $verdict    = 'unreserved';
                $warnings[] = sprintf(
                    '%s tier %d is the deepest division and holds %d NPC club rows, the same as the tier above '
                    . '(%s) rather than one fewer — so no place was demonstrably reserved for the player. The size '
                    . 'is still %d, because the player\'s club joins this division either way and that is the table '
                    . 'the device assembles. Reported because it is the only tier whose size the rows alone do not '
                    . 'corroborate.',
                    $country,
                    $tier,
                    $here,
                    $above === null ? 'there is no tier above' : (string) $above,
                    $size,
                );
            } else {
                $verdict = 'ok';
            }

            // ── THE tv_deal CACHE, CHECKED ON EVERY TIER THAT RESOLVES ──────────────────
            //
            // Independent of the size verdict above: a tier can be entirely consistent and
            // still carry a stale column, which is the normal state after any regeneration.
            $cacheCell = '—';

            if ($derived !== null) {
                if ($cached === $derived) {
                    $cacheCell = 'ok';
                } else {
                    $cacheCell  = 'STALE';
                    $warnings[] = sprintf(
                        '%s tier %d: the stored tv_deal column reads %s but the share every device is served is '
                        . '%s (%s / %d clubs). No player sees the column — SyncService, InitializeController and '
                        . 'LeagueService all derive at serve time — but four admin surfaces read it raw, so the '
                        . 'back office and a device would show different TV money. Resave the league in admin, or '
                        . 'resync the column, to clear it.',
                        $country,
                        $tier,
                        $cached === null ? 'null' : $this->money($cached),
                        $this->money($derived),
                        $this->money((int) $pool),
                        $size,
                    );
                }
            }

            $rows[] = [
                $tier,
                $size,
                $here,
                $reserved ? 'yes' : 'no',
                $slots > 0 ? (string) $slots : '—',
                $pool === null ? '—' : $this->money($pool),
                $cacheCell,
                $verdict,
            ];
        }

        $io->text(
            'Division size, DERIVED from the NPC club rows that exist (league.slots is retired and no longer read '
            . '— owner ruling, 2026-11-19; it is printed only so an older export can be reconciled against a '
            . 'running box). The reserved player place is DETECTED and informational: the +1 is unconditional.',
        );
        $io->table(
            ['T', 'size', 'npc rows', 'reserved', 'slots (unread)', 'tv pool', 'tv_deal cache', ''],
            $rows,
        );

        [$packProblems, $packWarnings] = $this->comparePackCounts($io, $country, $leagues, $rowsByTier);

        $problems = [...$problems, ...$packProblems];
        $warnings = [...$warnings, ...$packWarnings];

        if ($warnings !== []) {
            // Warnings rather than problems: see the docblock. Printed here, next to the table
            // that shows them, instead of being pooled into the PROBLEMS list where a state
            // nobody can act on yet — or one no player can see — would read as a release blocker.
            $io->warning(implode("\n", $warnings));
        }

        return $problems;
    }

    /**
     * Check 9, third count — the CACHED WORLD PACK against the rows it was built from.
     *
     * A pack is a cache of `npc_club` rows and **nothing invalidates it when those rows change**
     * (the known footgun: regenerating NPC clubs leaves every cached pack in place). So the
     * division a device actually fields can differ from the server rows, and that is the
     * disagreement a human sees — a league table header reading 12 while every finance divides
     * by 14.
     *
     * ⚠️ **Since 2026-11-19 this is the ONLY remaining way to get a wrong divisor in front of a
     * player**, and it got more likely rather than less: `sizeOf()` derives from the rows now,
     * so the divisor moves the instant they change while the pack stays exactly as it was.
     *
     * Compared against the ROWS rather than against `sizeOf()`, which are the same number at
     * every filled non-deepest tier but not at the deepest, where `sizeOf()` adds the player's
     * place and the payload does not carry it. The rows are what the pack is built from, so a
     * disagreement is unambiguously a stale cache with a clear remedy (regenerate). The
     * player's own club is added on the device and is not in the payload, so no reservation
     * adjustment belongs here.
     *
     * @param  list<\App\Entity\League> $leagues
     * @param  array<int, int>          $rowsByTier
     * @return array{0: list<string>, 1: list<string>}
     */
    private function comparePackCounts(SymfonyStyle $io, string $country, array $leagues, array $rowsByTier): array
    {
        $current = \App\Service\WorldInitializationService::WORLD_PACK_VERSION;
        $pools   = [];

        foreach ($leagues as $league) {
            $pools[$league->getTier()] = $league->getTvPoolPence();
        }

        $problems = [];
        $warnings = [];
        $rows     = [];

        foreach ($this->worldPackCacheRepository->findAllSummaries() as $summary) {
            if (strtoupper(trim($summary['country'])) !== $country) {
                continue;
            }

            $tier = $summary['tier'];

            if (!array_key_exists($tier, $rowsByTier)) {
                // A cached tier with no league row. Not this check's business — it would be a
                // finding about the pack's own shape, and check 9 is about division sizes.
                continue;
            }

            $packCount = $summary['clubCount'];
            $here      = $rowsByTier[$tier];
            $stale     = $summary['payloadVersion'] !== $current;

            $rows[] = [
                $tier,
                substr($summary['setId'], 0, 8),
                $packCount,
                $here,
                $summary['payloadVersion'] . ($stale ? ' (will rebuild)' : ''),
                $packCount === $here ? 'ok' : ($stale ? 'stale version — ignored' : 'PACK DISAGREES'),
            ];

            if ($packCount === $here || $stale) {
                continue;
            }

            $pool = $pools[$tier] ?? null;

            if ($pool !== null) {
                $problems[] = sprintf(
                    '%s tier %d: the cached world pack (set %s) holds %d clubs but %d NPC club rows exist — the device '
                    . 'fields a different division from the one the %s pool is divided by. The pack is a cache and '
                    . 'nothing invalidates it when clubs are regenerated: REGENERATE the pack. ⚠️ There is no '
                    . 'longer an authored size to reach for instead — league.slots was retired on 2026-11-19 and '
                    . 'is not read, so the pack is the only thing here that can be wrong.',
                    $country,
                    $tier,
                    substr($summary['setId'], 0, 8),
                    $packCount,
                    $here,
                    $this->money($pool),
                );

                continue;
            }

            $warnings[] = sprintf(
                '%s tier %d: the cached world pack (set %s) holds %d clubs but %d NPC club rows exist — a stale pack. '
                . 'No TV pool is authored here, so no money is mis-divided yet; regenerate the pack.',
                $country,
                $tier,
                substr($summary['setId'], 0, 8),
                $packCount,
                $here,
            );
        }

        if ($rows === []) {
            $io->text(sprintf(
                'Cached world packs for %s: none. The pack-against-rows comparison cannot run, so a device could still field a different division from the one every finance divides by.',
                $country,
            ));

            return [$problems, $warnings];
        }

        $io->text('Cached world pack club counts against the NPC club rows they were built from (a pack is a cache; nothing invalidates it when clubs are regenerated):');
        $io->table(
            ['T', 'set', 'pack clubs', 'npc rows', 'payload v', ''],
            $rows,
        );

        return [$problems, $warnings];
    }

    /**
     * CHECK 2 — does any ability earn more at a lower tier than the one above it?
     *
     * Checked over the OVERLAP of each adjacent pair of bands rather than inferred from the
     * S_tier ordering, because that is the thing a player would actually experience: an ability
     * that exists in both tiers, paid more in the worse division. With England's live bands,
     * tiers 5 and 6 overlap at 10-15 and tiers 4 and 5 at 15-25, so there is real overlap to
     * check.
     *
     * @param array<int, array{min:int,max:int}> $bands
     * @param array<int, int>                    $scales
     * @return list<string>
     */
    private function checkAbilityInversions(string $country, array $bands, array $scales, GameConfig $config): array
    {
        $problems = [];
        $k        = $config->getEconomyWageCurveExponentBp() / 10000;

        foreach ($bands as $tier => $band) {
            $below = $tier + 1;
            if (!isset($bands[$below], $scales[$tier], $scales[$below])) {
                continue;
            }

            $lo = max($band['min'], $bands[$below]['min']);
            $hi = min($band['max'], $bands[$below]['max']);

            if ($lo > $hi) {
                // No shared ability, so no comparison a player could make. Reported as an
                // observation rather than a problem — disjoint bands are a design choice.
                continue;
            }

            foreach ([$lo, $hi] as $ability) {
                $here  = $scales[$tier] * ($ability ** $k);
                $there = $scales[$below] * ($ability ** $k);

                if ($there > $here) {
                    $problems[] = sprintf(
                        '%s: ability %d is paid MORE at tier %d than at tier %d — a promoted player takes a pay cut.',
                        $country, $ability, $below, $tier,
                    );
                }
            }
        }

        return $problems;
    }

    /**
     * CHECK 3 — TV per club as a share of that tier's baseline turnover.
     *
     * The check that caught the real inconsistency: England tier-1 TV is authored at a figure
     * larger than any plausible tier-1 turnover, which makes `wageToTurnoverRatio` meaningless
     * because TV alone already exceeds the revenue the wage bill is a fraction of.
     *
     * @return list<string>
     */
    private function checkTvAgainstTurnover(SymfonyStyle $io, string $country, int $tierCount, GameConfig $config): array
    {
        $problems = [];
        $rows     = [];

        for ($tier = 1; $tier <= $tierCount; $tier++) {
            $league = $this->leagueRepository->findByCountryAndTier($country, $tier);
            $scale  = $this->scaleResolver->resolve($country, $tier);

            if ($league === null) {
                continue;
            }

            // RESOLVED, NOT THE RAW COLUMN. `league.tv_deal` is a CACHE of `tvPoolPence` over
            // the division size that `syncTvDeal()` only refreshes on an admin save, so
            // anything that moves the size leaves it encoding the old divisor — which is
            // exactly what happened on 2026-11-19 (EN stored pool/18 against a corrected size
            // of 16, so this check read England's TV income 12.5% low and printed
            // `perClub x size != pool`). ⚠️ **More exposed since the size became derived**: it
            // now goes stale on every regeneration rather than only on a migration, which is
            // why check 9 reports the staleness explicitly. Every publication site already
            // resolves this way; the gate reading the raw column was the one reader left in the
            // cache-staleness class.
            $perClub  = $this->leagueSizeResolver->derivedTvDealPence($league) ?? $league->getTvDeal();
            $size     = $this->leagueSizeResolver->sizeOf($league);
            $turnover = $scale->turnoverPence;

            $share = ($perClub !== null && $turnover > 0) ? $perClub * 100 / $turnover : null;

            $rows[] = [
                $tier,
                $size,
                $perClub === null ? '—' : $this->money($perClub),
                $league->getTvPoolPence() === null ? '—' : $this->money($league->getTvPoolPence()),
                $turnover > 0 ? $this->money($turnover) : '—',
                $share === null ? '—' : sprintf('%.1f%%', $share),
            ];

            if ($share !== null && $share > 100.0) {
                $problems[] = sprintf(
                    '%s tier %d: TV alone is %.0f%% of baseline turnover (%s per club against %s) — re-author the TV table before flipping the model.',
                    $country, $tier, $share, $this->money($perClub), $this->money($turnover),
                );
            }
        }

        if ($rows !== []) {
            $io->text('TV against turnover:');
            $io->table(['T', 'clubs', 'TV per club', 'TV pool', 'turnover', 'TV % turnover'], $rows);
        }

        return $problems;
    }

    /**
     * CHECK 6 — is the TV table scaled by the country factor?
     *
     * ── THE GAP THIS CLOSES ───────────────────────────────────────────────────────────────
     *
     * Every money figure in this economy reaches a country through `EconomyScaleResolver`,
     * which multiplies a base-year amount by the country factor — **except TV.**
     * `league.tv_deal` is a STORED ABSOLUTE on a per-country row, authored by an operator, and
     * no factor is ever applied to it. So setting `country_profile.economy_factor_bp` to 2000
     * drops a country's turnover, wages and sponsorship to a fifth and leaves its TV money at
     * 100%: a club paying a fifth of the wages while collecting full English TV money. Not a
     * cheaper country — a different game, and the same defect the sponsor bands had before they
     * were country-scaled, except this one cannot be fixed in code because the figure is
     * authored rather than derived.
     *
     * Nothing warned about it. Check 3 does not: TV at 5x its intended share still passes a
     * bound of 100% of turnover whenever the factor is above about 0.35.
     *
     * ── HOW IT IS DETECTED ────────────────────────────────────────────────────────────────
     *
     * Turnover IS country-scaled, so for a correctly-authored TV table
     *
     *     TV_country / turnover_country  ===  TV_baseline / turnover_baseline
     *
     * at every tier — the share is scale-free, which is what makes it comparable at all. The
     * check therefore reads the baseline country's own `tv_deal` for the tier and asks whether
     * this country's is the baseline's times the factor. It reports the figure the operator
     * should author, not merely that something is wrong.
     *
     * A country at parity needs no check and is skipped, which is why this is silent on every
     * box today. Zero baselines is legal and also skipped: with nothing to compare against
     * there is no claim to make.
     *
     * TOLERANCE IS 2%, because a sensible TV table is rounded to a few significant figures and
     * the factor is basis points. The check is for an unscaled table, not for a rounding
     * difference.
     *
     * @return list<string>
     */
    private function checkTvIsCountryScaled(SymfonyStyle $io, string $country, int $tierCount): array
    {
        $baseline = $this->scaleResolver->baselineCountryCode();

        // Nothing flagged, or this IS the baseline: no comparison to make.
        if ($baseline === null || $baseline === strtoupper(trim($country))) {
            return [];
        }

        $factorBp = $this->scaleResolver->countryFactorBp($country);

        // At parity the stored absolute is already correct, which is every country today.
        if ($factorBp === 10000) {
            return [];
        }

        $problems = [];
        $rows     = [];

        for ($tier = 1; $tier <= $tierCount; $tier++) {
            $here = $this->leagueRepository->findByCountryAndTier($country, $tier)?->getTvDeal();
            $base = $this->leagueRepository->findByCountryAndTier($baseline, $tier)?->getTvDeal();

            if ($here === null || $base === null || $base <= 0) {
                continue;
            }

            $expected = intdiv($base * $factorBp, 10000);
            $driftPct = $expected > 0 ? abs($here - $expected) * 100 / $expected : 0.0;

            $rows[] = [
                $tier,
                $this->money($base),
                sprintf('%.2f', $factorBp / 10000),
                $this->money($expected),
                $this->money($here),
                sprintf('%.0f%%', $driftPct),
            ];

            if ($driftPct > 2.0) {
                $problems[] = sprintf(
                    '%s tier %d: TV is %s per club but %s\'s economy factor is %.0f%% of %s, so it should be about %s. '
                    . '`league.tv_deal` is a stored absolute and no factor reaches it — re-author %s\'s TV table.',
                    $country,
                    $tier,
                    $this->money($here),
                    $country,
                    $factorBp / 100,
                    $baseline,
                    $this->money($expected),
                    $country,
                );
            }
        }

        if ($rows !== []) {
            $io->text(sprintf('TV against the %s baseline (factor %.2f):', $baseline, $factorBp / 10000));
            $io->table(['T', "{$baseline} per club", 'factor', 'expected', 'authored', 'drift'], $rows);
        }

        return $problems;
    }

    /**
     * CHECK 5 — the absolute-pence thresholds against what a tier's squad actually costs.
     *
     * THE THRESHOLDS DO NOT SCALE AND THE WAGES DO, which is the whole problem. Every figure in
     * `bankruptcyDeductionTiers` is a flat pence amount, so the moment a country factor or a
     * wage-model flip moves a wage bill, the bands stop meaning what they were authored to
     * mean — and nothing announces it. This prints the relationship rather than leaving it to
     * be rediscovered.
     *
     * Reported as warnings rather than hard failures: the thresholds are a CLIENT-side mechanic
     * the backend only publishes, and `bankruptcyDeductionsEnabled` may well be off. A flat
     * refusal would block a wage-model flip on a mechanic that is not running.
     *
     * The exception is the youth scholarship, which IS a backend figure and CAN invert — a
     * scholar paid more than an average first-team player is a defect wherever it happens.
     *
     * @return list<string>
     */
    private function checkAbsoluteThresholds(SymfonyStyle $io, string $country, int $tierCount, GameConfig $config): array
    {
        $problems = [];
        $bands    = $config->getBankruptcyDeductionTiers();
        $topBand  = 0;

        foreach ($bands as $band) {
            $topBand = max($topBand, (int) ($band['deficitMin'] ?? 0));
        }

        $rows = [];

        for ($tier = 1; $tier <= $tierCount; $tier++) {
            $scale = $this->scaleResolver->resolve($country, $tier);

            if (!$scale->isResolvable) {
                continue;
            }

            // A TIER ABSENT FROM npcSquadConfig IS A SILENT MIS-CALIBRATION, not a missing row.
            // `EconomyScaleResolver::npcSquadRow()` returns [] and referenceSquadSize() falls to
            // 1, while the GENERATOR falls back to WorldInitializationService::defaultTierConfig()
            // and builds ~18 players. So the tier gets priced as a one-player squad and then
            // filled with eighteen — S_tier comes out roughly eighteen times too high and
            // nothing else notices.
            if ($this->scaleResolver->npcSquadRow($tier) === []) {
                $problems[] = sprintf(
                    '%s tier %d is absent from starterConfig.npcSquadConfig — the wage scale calibrates against a ONE-player reference squad while the generator builds a full one.',
                    $country,
                    $tier,
                );
            }

            $weekly  = intdiv($this->squadBillPence($country, $tier, 50, $config), max(1, $config->getEconomyWeeksPerSeason()));
            // Through the resolver's own definition, so the check and the thing it checks
            // cannot drift apart — which is exactly how the scholarship bug got in.
            $midWage = $this->wageModel->averageFirstTeamWagePence($country, $tier, 50, $config) ?? 0;

            $weeksToTop = ($weekly > 0 && $topBand > 0) ? $topBand / $weekly : null;

            // THE RESOLVED figure, not the flat one, AND resolved under the model this table
            // is previewing rather than the live one. Reading `youthScholarshipWagePence`
            // directly would keep reporting an inversion the cap has already fixed; asking
            // for the LIVE figure while the model is still `legacy` — which is the state this
            // command exists to run in — did the same thing by a longer route, comparing a
            // flat £250 against turnover wages and raising a problem that no authoring action
            // could clear. Every figure in this table is what `turnover` WOULD pay, so this
            // one has to be too.
            $scholarship = $this->wageModel->youthScholarshipPenceUnder(
                EconomyWageModel::TURNOVER,
                $country,
                $tier,
                $config,
            );

            $rows[] = [
                $tier,
                $this->money($weekly),
                $this->money($midWage),
                $weeksToTop === null ? '—' : sprintf('%.2f', $weeksToTop),
                $midWage > 0 ? sprintf('%.2fx', $scholarship / $midWage) : '—',
            ];

            // Under a week of unpaid wages reaching the HARSHEST band means the ladder has
            // collapsed into "any deficit at all = maximum penalty" for this tier.
            if ($weeksToTop !== null && $weeksToTop < 1.0) {
                $problems[] = sprintf(
                    '%s tier %d: the harshest bankruptcy band (%s) is reached in %.2f weeks of unpaid wages (%s/wk) — the ladder has no range left here.',
                    $country, $tier, $this->money($topBand), $weeksToTop, $this->money($weekly),
                );
            }

            // A scholar out-earning an average first-team player is a defect wherever it is.
            if ($midWage > 0 && $scholarship > $midWage) {
                $problems[] = sprintf(
                    '%s tier %d: the youth scholarship (%s/wk) is MORE than an average first-team wage (%s/wk).',
                    $country, $tier, $this->money($scholarship), $this->money($midWage),
                );
            }
        }

        if ($rows !== []) {
            $io->text('Absolute-pence thresholds against this country\'s wage bills:');
            $io->table(
                ['T', 'squad wages /wk', 'mid wage /wk', 'weeks to harshest band', 'scholarship : mid wage'],
                $rows,
            );
        }

        // THE TWO COUNTRY-INDEPENDENT CHECKS USED TO BE HERE, AND THAT IS WHY THE FINDING
        // COUNT MOVED WITH THE ARGUMENT LIST. The `deficitMax: null` scan and
        // checkDeductionWeeks() read `game_config` columns that have no country dimension at
        // all, but this method runs once per country argument — so `wage-table EN SC` reported
        // every ladder defect twice and `wage-table EN SC WA` three times. An operator
        // comparing two runs saw the count rise with no explanation, which made the whole
        // output look unreliable at exactly the moment it was supposed to gate a deploy.
        //
        // They are now called once from execute(), in checkDeductionLadders(). Nothing in this
        // method may read a country-independent column for the same reason.

        return $problems;
    }

    /**
     * The two deduction-ladder checks, run ONCE — they have no country dimension.
     *
     * `bankruptcyDeductionTiers` and `bankruptcyDeductionWeeks` are single `game_config` JSON
     * columns. Nothing about them varies per country, so a finding about them is one finding
     * however many countries were asked for. Keeping them here rather than in
     * checkAbsoluteThresholds() is what makes two runs comparable: `wage-table EN` and
     * `wage-table EN SC` may now differ only by genuinely per-country findings.
     *
     * @return list<string>
     */
    private function checkDeductionLadders(GameConfig $config): array
    {
        $problems = [];

        // Overlapping unbounded rows make later rows unreachable under the first-match-wins
        // contract the column documents.
        $unbounded = 0;
        foreach ($config->getBankruptcyDeductionTiers() as $i => $band) {
            if (($band['deficitMax'] ?? null) === null) {
                $unbounded++;
                if ($unbounded > 1) {
                    $problems[] = sprintf(
                        'bankruptcyDeductionTiers row %d has deficitMax null behind another unbounded row — under first-match-wins it can never be reached.',
                        $i + 1,
                    );
                }
            }
        }

        return [...$problems, ...$this->checkDeductionWeeks($config)];
    }

    /**
     * CHECK 7 — is sponsorship a believable share of the turnover it is authored against?
     *
     * ── THE THREE CONSTRAINTS, AND WHY THEY ARE HERE AND NOT ONLY IN THE APP ──────────────
     *
     * The sponsor tables are authored on this backend, so an operator has to be able to see
     * what they are worth before a pack is warmed with them. The constraints the owner review
     * settled on:
     *
     *  1. **club + league sponsorship is between 8% and 30% of authored turnover at every
     *     tier.** Below 8% the mechanic is decoration; above 30% it crowds out matchday and TV
     *     and makes the wage anchor follow sponsorship instead of the turnover ladder.
     *  2. **the tier-8 share is no more than 3x the tier-1 share.** Sponsorship is SUPPOSED to
     *     matter relatively more at the bottom, where there is no TV money — but a bottom
     *     division where it is the whole economy is a different game from the top, not a
     *     poorer version of it.
     *  3. **the ratios are non-increasing.** A division worth more per sponsor than the one
     *     above it is almost always a typo; it is reported, never refused, because it may be
     *     deliberate.
     *
     * ── THIS IS NOT THE COVERAGE FIGURE, AND MUST NOT BE READ AS ONE ──────────────────────
     *
     * The acceptance test is the APP's gate — `scripts/economy/variantGate.ts` in
     * `wunderkind-app` — whose COVERAGE column is modelled income over authored turnover, with
     * matchday computed per club over real ground levels and fill under elasticity. **The
     * backend cannot compute that**: it has no ground model, no elasticity and no fill, which
     * is exactly why the app owns the figure.
     *
     * What this prints is narrower and says so: the sponsorship LINES only, as a share of the
     * same denominator. A share inside 8-30% here is a necessary condition, not a verdict.
     *
     * ── THE ARITHMETIC MIRRORS `sponsorSeasonPence()` DELIBERATELY ────────────────────────
     *
     * Band selection by reputation (75/40/15), the mean of the eligible mix, position inside
     * each band by reputation, x the sponsor count. Copied from the app's own helper so the two
     * gates cannot disagree about a figure an operator is asked to approve. A second,
     * differently-shaped estimate here would be worse than no estimate: it would look like a
     * cross-check and would not be one.
     *
     * One divergence is inherited and reported rather than fixed: the club sponsor COUNT is
     * looked up through a tier->commercial-tier map (1-2 elite, 3-4 national, 5-6 regional,
     * 7-8 local) while the engine's own lookup uses a different one (tier 1 elite, 2 national,
     * 3 regional, 4+ local). They disagree for tiers 2-6. Fixing it is not this pass.
     *
     * @return list<string>
     */
    private function checkSponsorshipShares(SymfonyStyle $io, string $country, int $tierCount, GameConfig $config): array
    {
        $problems  = [];
        $rows      = [];
        $shares    = [];
        $maxByTier = $config->getMaxSponsorsByTier();
        $ratios    = $config->getEconomySponsorTierRatioBp();

        for ($tier = 1; $tier <= $tierCount; $tier++) {
            $scale = $this->scaleResolver->resolve($country, $tier);

            if (!$scale->isResolvable || $scale->turnoverPence <= 0) {
                // Unauthored turnover: there is no denominator, and check 1 already said so.
                continue;
            }

            $league   = $this->leagueRepository->findByCountryAndTier($country, $tier);
            $anchor   = $this->anchorReputation($tier);
            $clubs    = $maxByTier[$this->commercialTier($tier)] ?? 2;
            $leagueN  = $league?->getSponsorCount() ?? 0;

            $perSponsor   = $this->meanSponsorPence($anchor, $country, $config, $tier);
            $clubPence    = (int) round($perSponsor * $clubs);
            $leaguePence  = (int) round($perSponsor * $leagueN);
            $totalPence   = $clubPence + $leaguePence;
            $sharePct     = $totalPence * 100 / $scale->turnoverPence;

            $shares[$tier] = $sharePct;

            $rows[] = [
                'T' . $tier,
                $this->money($clubPence),
                $this->money($leaguePence),
                $this->money($scale->turnoverPence),
                sprintf('%.1f%%', $sharePct),
                sprintf('%d bp', $ratios[$tier - 1] ?? 10000),
                $this->money($this->ticketPrices->priceFor($tier, $country, $config)),
            ];

            if ($sharePct < self::SPONSOR_SHARE_MIN_PCT || $sharePct > self::SPONSOR_SHARE_MAX_PCT) {
                $problems[] = sprintf(
                    '%s tier %d: club + league sponsorship is %.1f%% of authored turnover (%s of %s) — outside the agreed %d-%d%% band.',
                    $country,
                    $tier,
                    $sharePct,
                    $this->money($totalPence),
                    $this->money($scale->turnoverPence),
                    self::SPONSOR_SHARE_MIN_PCT,
                    self::SPONSOR_SHARE_MAX_PCT,
                );
            }
        }

        if ($rows !== []) {
            $io->text('Sponsorship against authored turnover (the APP\'s gate owns COVERAGE — this is the sponsorship lines only):');
            $io->table(
                ['T', 'club sponsors', 'league pot', 'turnover', 'share', 'sponsor ratio', 'ticket'],
                $rows,
            );
        }

        // Constraint 2: the bottom division may lean on sponsorship, but not three times as
        // hard as the top one.
        $first = $shares[1] ?? null;
        $last  = $shares[min(8, $tierCount)] ?? null;

        if ($first !== null && $last !== null && $first > 0 && $last > $first * self::SPONSOR_SHARE_SPREAD) {
            $problems[] = sprintf(
                '%s: the tier-%d sponsorship share (%.1f%%) is more than %dx the tier-1 share (%.1f%%).',
                $country,
                min(8, $tierCount),
                $last,
                self::SPONSOR_SHARE_SPREAD,
                $first,
            );
        }

        // Constraint 3: non-increasing ratios. Reported, never refused.
        for ($i = 1; $i < min(8, $tierCount); $i++) {
            if (($ratios[$i] ?? 0) > ($ratios[$i - 1] ?? 0)) {
                $problems[] = sprintf(
                    'economySponsorTierRatioBp: tier %d (%d bp) is higher than tier %d (%d bp) — a lower division worth more per sponsor than the one above it.',
                    $i + 1,
                    $ratios[$i] ?? 0,
                    $i,
                    $ratios[$i - 1] ?? 0,
                );
            }
        }

        return $problems;
    }

    /**
     * Check 8 — the league payout ladders, and what they cost a division.
     *
     * ── WHAT WAS WRONG, AND WHY A CHECK RATHER THAN A FIX ─────────────────────────────────
     *
     * `prize_money` and `league_position_pot` are PER-CLUB figures sized as though they were
     * pools, and against the authored turnover ladder they INVERTED at tier 3: tier 1 paid its
     * champion 0.26x its turnover, tier 8 paid 30.33x. Version20261116181000 re-authored them
     * as a flat share of each tier's own turnover. These checks are what stops that being
     * undone by the next retune — the ladder is decoupled from the turnover table on purpose,
     * so nothing but a check can notice the two drifting apart.
     *
     * ── THE ARITHMETIC MIRRORS THE RESOLVER, NOT A SECOND MODEL ───────────────────────────
     *
     * Figures come from `LeaguePayoutResolver`, and the taper sum from its own `taperSumPpm()`,
     * for the reason check 7 states at length: a differently-shaped estimate here would look
     * like a cross-check and would not be one. The club count and the decrease percent come off
     * this country's own league rows — the derived size is 16 in EN, 12 in SC, 14 in BE and 20
     * in FR (world export, 2026-11-19), so assuming 18 would quietly misreport the world.
     *
     * ── FOUR CONSTRAINTS ──────────────────────────────────────────────────────────────────
     *
     *  a. both ladders non-increasing down the pyramid;
     *  b. TV + position + prize per AVERAGE club inside PAYOUT_SHARE_MIN/MAX_PCT, and the
     *     top-to-bottom spread inside PAYOUT_SHARE_SPREAD either way;
     *  c. a champion's own take (prize + first place) at or under CHAMPION_SHARE_MAX_PCT;
     *  d. the prize-plus-position contribution flat across tiers — the invariant the ladder
     *     creates, and the only one of the four that TV cannot drown out.
     *
     * @return list<string>
     */
    private function checkLeaguePayouts(SymfonyStyle $io, string $country, int $tierCount, GameConfig $config): array
    {
        $problems   = [];
        $rows       = [];
        $shares     = [];
        $flatness   = [];
        $prizeRatio = $config->getEconomyPrizeTierRatioPpm();
        $potRatio   = $config->getEconomyPositionPotTierRatioPpm();

        for ($tier = 1; $tier <= $tierCount; $tier++) {
            $scale = $this->scaleResolver->resolve($country, $tier);

            if (!$scale->isResolvable || $scale->turnoverPence <= 0) {
                // No denominator. Check 1 already reports an unauthored tier; saying so twice
                // would make this command's finding count depend on how many tiers are blank.
                continue;
            }

            $league = $this->leagueRepository->findByCountryAndTier($country, $tier);

            if ($league === null) {
                continue;
            }

            $prize = $this->leaguePayouts->prizeFor($league, $config) ?? 0;
            $pot   = $this->leaguePayouts->positionPotFor($league, $config) ?? 0;

            $clubs    = max(1, $this->leagueSizeResolver->sizeOf($league));
            $decrease = $league->getLeaguePositionDecreasePercent()
                ?? $config->getLeaguePositionDecreasePercent();
            $taperPpm = $this->leaguePayouts->taperSumPpm($clubs, $decrease);

            // RESOLVED for the same reason check 3 does it: this line is paired with the
            // `sizeOf()` read eight lines above, so a stale cache makes `perClub` and `clubs`
            // disagree about how many ways the pool was split.
            $tvPerClub     = $this->leagueSizeResolver->derivedTvDealPence($league)
                ?? $league->getTvDeal()
                ?? 0;
            $positionTotal = intdiv($pot * $taperPpm, 1_000_000);

            // PER AVERAGE CLUB: this club's TV, the division's position bill spread over its
            // clubs, and the prize spread the same way — the champion takes it all, but the
            // question here is what the tier pays out per head.
            $perClub  = $tvPerClub + intdiv($positionTotal, $clubs) + intdiv($prize, $clubs);
            $sharePct = $perClub * 100 / $scale->turnoverPence;

            $payoutOnlyPct  = (intdiv($positionTotal, $clubs) + intdiv($prize, $clubs)) * 100 / $scale->turnoverPence;
            $championPct    = ($prize + $pot) * 100 / $scale->turnoverPence;

            $shares[$tier]   = $sharePct;
            $flatness[$tier] = $payoutOnlyPct;

            $rows[] = [
                'T' . $tier,
                $this->money($prize),
                $this->money($pot),
                $clubs . ' @ ' . $decrease . '%',
                sprintf('%.2fx', $taperPpm / 1_000_000),
                $this->money($league->getTvPoolPence() ?? 0),
                $this->money(($league->getTvPoolPence() ?? 0) + $positionTotal + $prize),
                $this->money($scale->turnoverPence * $clubs),
                sprintf('%.1f%%', $sharePct),
                sprintf('%.1f%%', $championPct),
            ];

            // Constraint b, first half.
            if ($sharePct < self::PAYOUT_SHARE_MIN_PCT || $sharePct > self::PAYOUT_SHARE_MAX_PCT) {
                $problems[] = sprintf(
                    '%s tier %d: TV + position + prize is %.1f%% of authored turnover for an average club (%s of %s) — outside the agreed %d-%d%% band.',
                    $country,
                    $tier,
                    $sharePct,
                    $this->money($perClub),
                    $this->money($scale->turnoverPence),
                    self::PAYOUT_SHARE_MIN_PCT,
                    self::PAYOUT_SHARE_MAX_PCT,
                );
            }

            // Constraint c. The hard one: this is what a single club banks for winning.
            if ($championPct > self::CHAMPION_SHARE_MAX_PCT) {
                $problems[] = sprintf(
                    '%s tier %d: a champion banks %s (prize %s + first-place pot %s), which is %.1f%% of its own authored turnover of %s — over the %d%% ceiling.',
                    $country,
                    $tier,
                    $this->money($prize + $pot),
                    $this->money($prize),
                    $this->money($pot),
                    $championPct,
                    $this->money($scale->turnoverPence),
                    self::CHAMPION_SHARE_MAX_PCT,
                );
            }
        }

        if ($rows !== []) {
            $io->text('League payouts against authored turnover (POSITION is the FIRST-PLACE amount; the division pays the taper multiple of it):');
            $io->table(
                ['T', 'prize', 'position (1st)', 'clubs @ taper', 'x pot', 'tv pool', 'league total', 'league turnover', 'per club', 'champion'],
                $rows,
            );
        }

        // Constraint b, second half — BOTH directions. The brief asked only whether the bottom
        // outran the top, which the authored ladder passes at 0.278x precisely because TV
        // collapses faster than a flat prize share compensates. The real spread is the other
        // way round, and a one-sided check would never have said so.
        $first = $shares[1] ?? null;
        $last  = $shares[min(8, $tierCount)] ?? null;

        if ($first !== null && $last !== null && $first > 0 && $last > 0) {
            $spread = max($first / $last, $last / $first);

            if ($spread > self::PAYOUT_SHARE_SPREAD) {
                $problems[] = sprintf(
                    '%s: the tier-1 payout share (%.1f%%) and the tier-%d share (%.1f%%) are %.2fx apart, over the %dx limit — the two ends of the pyramid are running different economies.',
                    $country,
                    $first,
                    min(8, $tierCount),
                    $last,
                    $spread,
                    self::PAYOUT_SHARE_SPREAD,
                );
            }
        }

        // Constraint d — the invariant, and the only check here TV cannot mask.
        if (count($flatness) > 1) {
            $min = min($flatness);
            $max = max($flatness);

            if ($max - $min > self::PAYOUT_FLATNESS_TOLERANCE_PP) {
                $problems[] = sprintf(
                    '%s: prize + position money is %.2fpp of turnover at its lowest tier and %.2fpp at its highest, a %.2fpp spread over the %.1fpp tolerance — the ratio tables no longer track the turnover ladder they were derived from. Re-derive them, do not widen the tolerance.',
                    $country,
                    $min,
                    $max,
                    $max - $min,
                    self::PAYOUT_FLATNESS_TOLERANCE_PP,
                );
            }
        }

        // Constraint a. Reported, never refused — as with the sponsor ratios.
        foreach ([
            ['economyPrizeTierRatioPpm',       $prizeRatio],
            ['economyPositionPotTierRatioPpm', $potRatio],
        ] as [$label, $table]) {
            for ($i = 1; $i < min(8, $tierCount); $i++) {
                if (($table[$i] ?? 0) > ($table[$i - 1] ?? 0)) {
                    $problems[] = sprintf(
                        '%s: tier %d (%d ppm) is higher than tier %d (%d ppm) — a lower division paying its champion more, relative to tier 1, than the one above it.',
                        $label,
                        $i + 1,
                        $table[$i] ?? 0,
                        $i,
                        $table[$i - 1] ?? 0,
                    );
                }
            }
        }

        return $problems;
    }

    /**
     * The mean pence one sponsor pays at this tier, mirroring the app's `sponsorSeasonPence()`.
     *
     * Band selection by reputation and position inside the band by reputation, over the bands
     * the resolver resolves for this tier and country — so an authored per-tier row or a ratio
     * is reflected here without this method knowing which produced the band.
     */
    private function meanSponsorPence(int $anchorReputation, string $country, GameConfig $config, int $tier): float
    {
        $rep = max(0, min(100, $anchorReputation));

        $sizes = match (true) {
            $rep >= 75 => [CompanySize::LARGE],
            $rep >= 40 => [CompanySize::MEDIUM, CompanySize::LARGE],
            $rep >= 15 => [CompanySize::SMALL, CompanySize::MEDIUM],
            default    => [CompanySize::SMALL],
        };

        $sum = 0.0;

        foreach ($sizes as $size) {
            [$min, $max] = $this->sponsorBands->bandFor($size, $country, $config, $tier);
            $sum        += $min + ($rep / 100) * ($max - $min);
        }

        return $sum / count($sizes);
    }

    /** `repTierForLeagueLevel`'s anchor: 1-2 elite, 3-4 national, 5-6 regional, 7-8 local. */
    private function anchorReputation(int $tier): int
    {
        return match (true) {
            $tier <= 2 => 87,
            $tier <= 4 => 57,
            $tier <= 6 => 27,
            default    => 7,
        };
    }

    /** `npcTierToCommercialRepString` — the map the app's gate uses for `maxSponsorsByTier`. */
    private function commercialTier(int $tier): string
    {
        return match (true) {
            $tier <= 2 => 'elite',
            $tier <= 4 => 'national',
            $tier <= 6 => 'regional',
            default    => 'local',
        };
    }

    /**
     * The WEEKS ladder's own contract: contiguous, ascending, one unbounded row, and it last.
     *
     * NOT A DUPLICATE OF THE UNBOUNDED CHECK ABOVE. That one reports a defect in the pence
     * ladder which `GameConfig` deliberately does not normalise — those rows are stored exactly
     * as typed, first-match-wins over hand-ordered rows, so the command is the only thing that
     * can speak. This ladder IS normalised on write, so anything reported here means the stored
     * value and the authored value differ: the operator typed something the setter had to
     * repair. Saying so is the point — a silent repair is how an operator ends up believing a
     * ladder they do not have.
     *
     * Null is the shipped state and reports nothing: the app uses its own constant.
     *
     * @return list<string>
     */
    private function checkDeductionWeeks(GameConfig $config): array
    {
        $rows = $config->getBankruptcyDeductionWeeks();

        if ($rows === null || $rows === []) {
            return [];
        }

        $problems = [];
        $previous = null;

        foreach ($rows as $i => $row) {
            $min = (int) ($row['weeksMin'] ?? 0);
            $max = $row['weeksMax'] ?? null;

            if ($previous !== null && $previous['weeksMax'] === null) {
                $problems[] = sprintf(
                    'bankruptcyDeductionWeeks row %d sits behind an unbounded row — it can never be reached.',
                    $i + 1,
                );
                break;
            }

            if ($previous !== null && $min !== (int) $previous['weeksMax']) {
                $problems[] = sprintf(
                    'bankruptcyDeductionWeeks row %d starts at week %d but row %d ended at week %d — the ladder has a %s and a deficit there falls through it.',
                    $i + 1,
                    $min,
                    $i,
                    (int) $previous['weeksMax'],
                    $min > (int) $previous['weeksMax'] ? 'gap' : 'overlap',
                );
            }

            if ($max !== null && (int) $max <= $min) {
                $problems[] = sprintf(
                    'bankruptcyDeductionWeeks row %d spans week %d to %d — an empty or inverted window.',
                    $i + 1,
                    $min,
                    (int) $max,
                );
            }

            $previous = ['weeksMin' => $min, 'weeksMax' => $max === null ? null : (int) $max];
        }

        // A ladder that never ends leaves the worst case unpunished, which is the opposite of
        // what the mechanic is for.
        if ($previous !== null && $previous['weeksMax'] !== null) {
            $problems[] = sprintf(
                'bankruptcyDeductionWeeks ends at week %d with no unbounded row — a club further behind than that is deducted nothing.',
                (int) $previous['weeksMax'],
            );
        }

        return $problems;
    }

    /**
     * What the reference squad costs per season at this tier, summed player by player.
     *
     * ⚠️ **THE SQUAD COMES FROM THE RESOLVER AND IS NOT REBUILT HERE.** It used to be: this
     * method spread the ability band evenly and priced that, which was a second statement of
     * what a reference squad is and was correct only while the resolver's own statement said
     * the same two lines. On 2026-11-19 the resolver stopped pricing the band — the band is a
     * POTENTIAL range and wages are paid on current ability — and began mapping each member
     * through the generator's maturation curve. A local copy of that would be the very drift
     * the change removed, so `referenceSquadAbilities()` was made public instead and this
     * check now prices exactly the squad S_tier was calibrated against. That is what keeps
     * the check meaningful: it still catches a rounding error anywhere in the chain, and it
     * can no longer report a drift that is its own and nothing else's.
     */
    private function squadBillPence(
        string $country,
        int $tier,
        int $reputation,
        GameConfig $config,
    ): int {
        $weeks = $config->getEconomyWeeksPerSeason();
        $bill  = 0;

        foreach ($this->scaleResolver->referenceSquadAbilities($country, $tier) as $ability) {
            $bill += ($this->wageModel->turnoverPlayerPence($ability, $country, $tier, $reputation, $config) ?? 0) * $weeks;
        }

        return $bill;
    }

    private function playerBudgetPence(int $turnoverPence, GameConfig $config): int
    {
        $wages = intdiv($turnoverPence * $config->getEconomyWageToTurnoverRatioBp(), 10000);

        return intdiv($wages * (10000 - $config->getEconomyStaffWageShareBp()), 10000);
    }


    private function squadSize(int $tier): int
    {
        $row = $this->scaleResolver->npcSquadRow($tier);
        $min = (int) ($row['playerMin'] ?? 0);
        $max = (int) ($row['playerMax'] ?? 0);

        return max(1, intdiv(min($min, $max) + max($min, $max), 2));
    }

    /** @param array{min:int,max:int} $band */
    private function mid(array $band): int
    {
        return (int) round(($band['min'] + $band['max']) / 2);
    }

    private function baselineLabel(): string
    {
        $bp = $this->scaleResolver->countryFactorBp('__none__');

        return $bp === 10000
            ? 'resolving at parity (either the baseline, or none flagged)'
            : 'a baseline is flagged; unknown countries resolve at ' . $this->pct($bp);
    }

    private function money(int $pence): string
    {
        return '£' . number_format($pence / 100, 2);
    }

    private function micro(int $microPence): string
    {
        return number_format($microPence / EconomyScaleResolver::WAGE_SCALE_UNIT, 4);
    }

    private function pct(int $bp): string
    {
        return number_format($bp / 100, 2) . '%';
    }
}
