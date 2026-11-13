<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameConfig;
use App\Enum\EconomyWageModel;
use App\Repository\GameConfigRepository;
use App\Repository\LeagueRepository;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\LeagueSizeResolver;
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
 * ── THE SIX CHECKS ────────────────────────────────────────────────────────────────────────
 *
 * A table of plausible-looking numbers can still be wrong in ways that are invisible row by
 * row, so the command answers six questions it would be easy not to ask:
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

    public function __construct(
        private readonly GameConfigRepository    $gameConfigRepository,
        private readonly EconomyScaleResolver    $scaleResolver,
        private readonly WageModelResolver       $wageModel,
        private readonly LeagueRepository        $leagueRepository,
        private readonly LeagueSizeResolver      $leagueSizeResolver,
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
            $bill   = $this->squadBillPence($country, $tier, $band, $squad, $reputation, $config);
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

        return $problems;
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

            $perClub  = $league->getTvDeal();
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

            $band    = $this->scaleResolver->abilityBand($country, $tier);
            $squad   = $this->squadSize($tier);

            $weekly  = intdiv($this->squadBillPence($country, $tier, $band, $squad, 50, $config), max(1, $config->getEconomyWeeksPerSeason()));
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

        // Independent of any tier: overlapping unbounded rows make later rows unreachable under
        // the first-match-wins contract the column documents.
        $unbounded = 0;
        foreach ($bands as $i => $band) {
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

        $problems = [...$problems, ...$this->checkDeductionWeeks($config)];

        return $problems;
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

    /** What the reference squad costs per season at this tier, summed player by player. */
    private function squadBillPence(
        string $country,
        int $tier,
        array $band,
        int $squad,
        int $reputation,
        GameConfig $config,
    ): int {
        if ($squad < 1) {
            return 0;
        }

        $weeks = $config->getEconomyWeeksPerSeason();
        $bill  = 0;

        for ($i = 0; $i < $squad; $i++) {
            // THE SAME INTEGER SPREAD EconomyScaleResolver::referenceSquadAbilitySum() uses.
            // If the two rounded differently this check would report a drift that was its own
            // and nothing else's — which is exactly what it did before the resolver started
            // summing integers.
            $ability = $squad === 1
                ? $this->mid($band)
                : (int) round($band['min'] + ($band['max'] - $band['min']) * $i / ($squad - 1));

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
