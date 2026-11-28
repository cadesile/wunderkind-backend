<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\GameConfig;
use App\Entity\InflationRateHistory;
use App\Enum\CompanySize;
use App\Enum\Country;
use App\Enum\EconomyWageModel;
use App\Repository\GameConfigRepository;
use App\Repository\InflationRateHistoryRepository;
use App\Repository\LeagueRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\LeaguePayoutResolver;
use App\Service\Economy\LeagueSizeResolver;
use App\Service\Economy\SponsorBandResolver;
use App\Service\Economy\TicketPriceResolver;
use App\Service\Economy\WageModelResolver;
use App\Service\WorldInitializationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * World → Economy. Where the country x tier scaling is turned on.
 *
 * EVERY CONTROL ON THIS SCREEN SHIPS IN ITS "NO CHANGE" POSITION, and the screen's job is to
 * make turning one on a deliberate act with the consequences visible first. The wage model is
 * `legacy`, every country factor is parity, the inflation rate is 0 and the turnover table is
 * unauthored — so the preview below is the only way to see what the `turnover` model WOULD pay
 * before anybody commits to it.
 *
 * ── THE THREE THINGS THAT ARE NOT ORDINARY FORM SAVES ─────────────────────────────────────
 *
 *  1. **The baseline country is a single-row flag with a partial unique index behind it**
 *     (`uq_country_profile_economy_baseline`), and Postgres checks that index PER ROW during an
 *     UPDATE. So it cannot be moved with one `SET is_economy_baseline = (code = :code)` — row
 *     order decides whether the new baseline is set before the old is cleared, and half the
 *     time it is. Clear-then-set inside a transaction is the shape that works, because zero
 *     baselines is legal and two is not. See `saveCountries()`.
 *  2. **The inflation rate writes an audit row.** It is the one economy figure whose PAST
 *     values keep mattering — the app compounds it over a club's season count, so changing it
 *     moves every existing save's prices retroactively. See `InflationRateHistory`.
 *  3. **The preview is read-only and runs the real resolver.** Not a reimplementation: the
 *     whole point is to see what generation will actually do, so a second copy of the
 *     arithmetic here would be worse than no preview at all.
 *
 * Routes are reached through `/admin?routeName=...` and every redirect goes back through the
 * EasyAdmin entry point, per `src/Controller/Admin/CLAUDE.md` — a POST action that rendered or
 * redirected to its own route directly would have no `ea` Twig context and die on an
 * "i18n on null".
 */
class EconomyAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface         $em,
        private readonly Connection                     $connection,
        private readonly GameConfigRepository           $gameConfigRepository,
        private readonly CountryProfileRepository       $countryProfileRepository,
        private readonly InflationRateHistoryRepository $inflationHistoryRepository,
        private readonly LeagueRepository               $leagueRepository,
        private readonly EconomyScaleResolver           $scaleResolver,
        private readonly WageModelResolver              $wageModel,
        private readonly LeagueSizeResolver             $leagueSizeResolver,
        private readonly SponsorBandResolver            $sponsorBands,
        private readonly TicketPriceResolver            $ticketPrices,
        private readonly LeaguePayoutResolver           $leaguePayouts,
    ) {}

    #[Route('/admin/economy', name: 'admin_economy')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(Request $request): Response
    {
        $config   = $this->gameConfigRepository->getConfig();
        $profiles = $this->countryProfileRepository->findBy([], ['name' => 'ASC']);

        // The preview's subject. Defaults to the baseline country so the screen opens on the
        // one table every other country is a percentage of.
        $previewCountry = strtoupper(trim((string) $request->query->get(
            'preview',
            $this->countryProfileRepository->findEconomyBaseline()?->getCode() ?? 'EN',
        )));

        return $this->render('admin/economy_content.html.twig', [
            'bmcGroup'        => 'World',
            'bmcTitle'        => 'Economy',
            'config'          => $config,
            'profiles'        => $profiles,
            'countryLabels'   => Country::labelMap(),
            'baseline'        => $this->countryProfileRepository->findEconomyBaseline(),
            'wageModels'      => EconomyWageModel::cases(),
            'history'         => $this->inflationHistoryRepository->findRecent(20),
            'previewCountry'  => $previewCountry,
            'preview'         => $this->buildPreview($previewCountry, $config),
            // The sponsor and ticket half of the same screen, resolved through the real
            // resolvers and read-only — see buildRevenuePreview().
            'revenue'         => $this->buildRevenuePreview($previewCountry, $config),
            // The league-payout half: what each tier's champion and placings are paid, and what
            // that costs the whole division — see buildPayoutPreview().
            'payouts'         => $this->buildPayoutPreview($previewCountry, $config),
            // The owner season-investment ceiling per tier, base year, so the operator sees what
            // the tier 1 base becomes down the pyramid before saving it.
            'investmentCeilings' => $this->buildInvestmentCeilingPreview($previewCountry),
            'wageScaleUnit'   => EconomyScaleResolver::WAGE_SCALE_UNIT,
            // So the screen can say what flipping the model will cost before it is flipped.
            'worldPackVersion' => WorldInitializationService::WORLD_PACK_VERSION,
        ]);
    }

    /**
     * The scalar knobs plus the two per-tier tables.
     *
     * ONE FORM, because the tier factors and the turnover table are only meaningful together:
     * saving a factor against a turnover row nobody authored yet produces a scale of zero, and
     * splitting them into two forms would make that the normal way to use the screen.
     */
    #[Route('/admin/economy/save', name: 'admin_economy_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('economy_save', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->back();
        }

        $config = $this->gameConfigRepository->getConfig();

        $model = EconomyWageModel::tryFrom((string) $request->request->get('economyWageModel', ''));
        if ($model !== null) {
            $config->setEconomyWageModel($model);
        }

        // BLANK LEAVES THE STORED VALUE ALONE, for every one of these. They are multipliers on
        // money nothing on this screen shows the consequences of until the preview is reloaded,
        // and a cleared field casting to 0 would switch an economy off rather than leave it
        // alone — the failure the contract-value pair on the game-config screen actually had.
        $this->setIfPresent($request, 'economyWageToTurnoverRatioBp', fn (int $v) => $config->setEconomyWageToTurnoverRatioBp($v));
        $this->setIfPresent($request, 'economyStaffWageShareBp', fn (int $v) => $config->setEconomyStaffWageShareBp($v));
        $this->setIfPresent($request, 'economyWageCurveExponentBp', fn (int $v) => $config->setEconomyWageCurveExponentBp($v));
        $this->setIfPresent($request, 'economyReputationSpreadBp', fn (int $v) => $config->setEconomyReputationSpreadBp($v));
        $this->setIfPresent($request, 'economyWeeksPerSeason', fn (int $v) => $config->setEconomyWeeksPerSeason($v));
        $this->setIfPresent($request, 'economyYouthScholarshipCapBp', fn (int $v) => $config->setEconomyYouthScholarshipCapBp($v));
        $this->setIfPresent($request, 'economyYouthScholarshipTier1Pence', fn (int $v) => $config->setEconomyYouthScholarshipTier1Pence($v));
        $this->setIfPresent($request, 'economyYouthScholarshipFloorPence', fn (int $v) => $config->setEconomyYouthScholarshipFloorPence($v));

        // READ BY INDEX, NOT array_values(). These inputs are named `[0]`..`[7]` and the index
        // IS the tier, so collapsing a sparse post with array_values() would silently move
        // tier 8's figure to tier 2. A browser always sends all eight, but a handler that only
        // works when every field arrives is a handler that fails the one time one does not —
        // and it fails by re-pricing the wrong division, not by erroring.
        //
        // An index missing from the post keeps whatever is stored for that tier, so a partial
        // save is a partial save rather than a reset.
        $factors = $request->request->all('tierFactorsBp');
        if ($factors !== []) {
            $current = $config->getEconomyTierFactorsBp();
            for ($i = 0; $i < 8; $i++) {
                if (isset($factors[$i]) && trim((string) $factors[$i]) !== '') {
                    $current[$i] = (int) $factors[$i];
                }
            }
            $config->setEconomyTierFactorsBp($current);
        }

        // POUNDS IN, PENCE STORED. Turnover is the biggest money figure on this backend
        // (England tier 1 is around half a billion pounds), and typing it in pence is how an
        // operator loses two orders of magnitude without noticing.
        //
        // A BLANK CELL HERE MEANS ZERO, not "leave it alone" — unlike every other field on the
        // screen. That is deliberate: zero is "unauthored", which is a real and useful state
        // (it is what makes the turnover model refuse to run), so an operator has to be able
        // to clear a tier back to it. The placeholder says "unauthored" for that reason.
        $turnover = $request->request->all('baselineTurnoverPounds');
        if ($turnover !== []) {
            $current = $config->getEconomyBaselineTurnoverPence();
            for ($i = 0; $i < 8; $i++) {
                if (!array_key_exists($i, $turnover)) {
                    continue;
                }

                $raw         = str_replace(',', '', trim((string) $turnover[$i]));
                $current[$i] = $raw === '' ? 0 : (int) round(((float) $raw) * 100);
            }
            $config->setEconomyBaselineTurnoverPence($current);
        }

        // POUNDS IN, PENCE STORED, and BLANK MEANS ZERO here rather than "leave it alone" —
        // zero is the off switch for the whole season-investment feature, so an operator has
        // to be able to clear the field to turn it back off. The placeholder says "off".
        // THE TWO RATIO TABLES, read by index exactly as the tier factors are, and for the
        // same reason: the input name's index IS the tier, so collapsing a sparse post with
        // array_values() would move tier 8's ratio onto tier 2 — re-pricing the wrong division
        // rather than erroring.
        //
        // A blank cell leaves that tier alone. Unlike the turnover table there is no "clear it
        // back to unauthored" state to express here: parity IS the unauthored state, and an
        // operator who wants a tier back at parity types 10000.
        foreach ([
            'sponsorTierRatioBp'     => [
                fn (): array => $config->getEconomySponsorTierRatioBp(),
                fn (array $v): GameConfig => $config->setEconomySponsorTierRatioBp($v),
            ],
            'ticketPriceTierRatioBp' => [
                fn (): array => $config->getEconomyTicketPriceTierRatioBp(),
                fn (array $v): GameConfig => $config->setEconomyTicketPriceTierRatioBp($v),
            ],
            // THE SAME LOOP, A DIFFERENT UNIT. These two are PARTS PER MILLION, so parity is
            // 1000000 and not 10000 — the tier-8 prize ratio is 424 ppm, which integer basis
            // points cannot hold (see GameConfig::$economyPrizeTierRatioPpm). The loop does not
            // care, because it only ever copies integers by index; the parity figure lives in
            // the setter's normaliser and in the column's placeholder, not here.
            'prizeTierRatioPpm'       => [
                fn (): array => $config->getEconomyPrizeTierRatioPpm(),
                fn (array $v): GameConfig => $config->setEconomyPrizeTierRatioPpm($v),
            ],
            'positionPotTierRatioPpm' => [
                fn (): array => $config->getEconomyPositionPotTierRatioPpm(),
                fn (array $v): GameConfig => $config->setEconomyPositionPotTierRatioPpm($v),
            ],
        ] as $field => [$read, $write]) {
            $posted = $request->request->all($field);

            if ($posted === []) {
                continue;
            }

            $current = $read();

            for ($i = 0; $i < 8; $i++) {
                if (isset($posted[$i]) && trim((string) $posted[$i]) !== '') {
                    $current[$i] = (int) $posted[$i];
                }
            }

            $write($current);
        }

        // POUNDS IN, PENCE STORED, and BLANK MEANS ZERO rather than "leave it alone" — zero is
        // the off switch for the whole ticket ladder (0 propagates through
        // TicketPriceResolver whatever the ratios say), so an operator has to be able to clear
        // the field to turn a published price back off. The placeholder says "unauthored".
        $ticketBase = $request->request->get('ticketPriceTier1Pounds');
        if ($ticketBase !== null) {
            $raw = str_replace(',', '', trim((string) $ticketBase));
            $config->setEconomyTicketPriceTier1Pence(
                $raw === '' ? 0 : (int) round(((float) $raw) * 100),
            );
        }

        // THE TWO LEAGUE-PAYOUT BASES, same pounds-in/pence-stored and same blank-means-zero
        // rule as the ticket base above, and for the same reason: 0 propagates through
        // LeaguePayoutResolver whatever the ratios say, so clearing the field is how an
        // operator turns a ladder back off and hands every division to its own column again.
        //
        // ⚠️ THE POT BASE IS THE FIRST-PLACE AMOUNT, NOT THE DIVISION'S BILL. At the authored
        // 10% taper the division pays out 5.5x whatever is typed here, so this field moves five
        // and a half times as much money as it appears to. The resolved preview shows the
        // league total beside it for exactly that reason.
        foreach ([
            'leaguePrizeTier1Pounds'       => fn (int $p): GameConfig => $config->setEconomyLeaguePrizeTier1Pence($p),
            'leaguePositionPotTier1Pounds' => fn (int $p): GameConfig => $config->setEconomyLeaguePositionPotTier1Pence($p),
        ] as $field => $write) {
            $posted = $request->request->get($field);

            if ($posted === null) {
                continue;
            }

            $raw = str_replace(',', '', trim((string) $posted));
            $write($raw === '' ? 0 : (int) round(((float) $raw) * 100));
        }

        $seasonBase = $request->request->get('seasonInvestmentBasePounds');
        if ($seasonBase !== null) {
            $raw = str_replace(',', '', trim((string) $seasonBase));
            $config->setEconomySeasonInvestmentBasePence(
                $raw === '' ? 0 : (int) round(((float) $raw) * 100),
            );
        }

        // OWNER INVESTMENT CURVE: PERCENT OF TIER 1 IN, PARTS PER MILLION STORED. Read by index
        // like the tier factors (the index IS the tier). A BLANK CELL MEANS "follow turnover"
        // for that tier, so clearing a row is how an operator hands it back to the turnover
        // share; the placeholder shows what that share currently is.
        $shares = $request->request->all('seasonInvestmentTierSharePct');
        if ($shares !== []) {
            $current = $config->getEconomySeasonInvestmentTierSharePpm();
            for ($i = 0; $i < 8; $i++) {
                if (!array_key_exists($i, $shares)) {
                    continue;
                }

                $raw         = str_replace(['%', ','], '', trim((string) $shares[$i]));
                $current[$i] = $raw === '' || !is_numeric($raw) ? null : (int) round(((float) $raw) * 10_000);
            }
            $config->setEconomySeasonInvestmentTierSharePpm($current);
        }

        $parachute = $request->request->get('leagueParachute');
        if ($parachute !== null) {
            // Verbatim, like the per-league block — see League::$parachute. Blank clears;
            // malformed JSON leaves the stored value alone rather than wiping a schedule
            // because of a missing brace.
            if (trim((string) $parachute) === '') {
                $config->setLeagueParachute(null);
            } else {
                $decoded = json_decode((string) $parachute, true);
                if (is_array($decoded)) {
                    $config->setLeagueParachute($decoded);
                }
            }
        }

        $this->em->flush();
        $this->addFlash('success', 'Economy settings saved.');

        return $this->back($request->request->get('preview'));
    }

    /**
     * Per-country factors, and which country is the baseline.
     *
     * ── MOVING THE BASELINE: CLEAR, THEN SET, INSIDE A TRANSACTION ─────────────────────────
     *
     * `uq_country_profile_economy_baseline` is a PARTIAL unique index on
     * `is_economy_baseline = true`, and Postgres checks a non-deferrable unique index
     * **per row during the UPDATE**, not at statement end. So the obvious one-statement
     * `SET is_economy_baseline = (code = :code)` DOES NOT WORK: depending on row order it sets
     * the new baseline before clearing the old and hits the index. That is not theoretical —
     * it is what this controller did first, and `EconomyAdminTest` caught it as a 500.
     *
     * A partial unique index also cannot be made DEFERRABLE (Postgres defers constraints, and
     * a partial unique cannot be a constraint), so there is no way to let the statement settle.
     *
     * The working shape is two statements: clear every baseline, then set one. The intermediate
     * state has NO baseline, which the index permits — and "no baseline" is a state
     * `EconomyScaleResolver` already handles by resolving every country at parity. The
     * transaction is what stops a concurrent request observing it.
     */
    #[Route('/admin/economy/countries', name: 'admin_economy_countries', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function saveCountries(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('economy_countries', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->back();
        }

        $factors = $request->request->all('factorBp');
        foreach ($factors as $code => $bp) {
            if ((string) $bp === '') {
                continue;
            }

            $this->countryProfileRepository->findByCode((string) $code)?->setEconomyFactorBp((int) $bp);
        }
        $this->em->flush();

        $baseline = trim((string) $request->request->get('baselineCountry', ''));
        if ($baseline !== '') {
            $this->connection->transactional(function () use ($baseline): void {
                // Clear first. Zero baselines is legal for the index and a state the resolver
                // already handles (parity for everyone); two is what it refuses.
                $this->connection->executeStatement(
                    'UPDATE country_profile SET is_economy_baseline = false WHERE is_economy_baseline',
                );
                $this->connection->executeStatement(
                    'UPDATE country_profile SET is_economy_baseline = true WHERE code = :code',
                    ['code' => strtoupper($baseline)],
                );
            });

            // The ORM has stale `isEconomyBaseline` values for every row it loaded above.
            $this->em->clear();
        }

        $this->addFlash('success', 'Country factors saved.');

        return $this->back($request->request->get('preview'));
    }

    /**
     * Set the inflation rate, and record what it was.
     *
     * THE AUDIT ROW IS THE POINT, not a side effect. This is the one economy figure whose past
     * values keep mattering: the app compounds it over a club's season count, so changing it
     * moves every existing save's prices retroactively. With only the current value stored
     * there would be no way to answer "why was that club's ceiling that" afterwards.
     *
     * Nothing READS the history to compute anything — the index always comes from the current
     * rate — and that is deliberate. A replayed history would make a club's ceiling depend on
     * when an operator happened to click save.
     */
    #[Route('/admin/economy/inflation', name: 'admin_economy_inflation', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function saveInflation(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('economy_inflation', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->back();
        }

        $raw = $request->request->get('inflationRatePerSeasonBp');
        if ($raw === null || trim((string) $raw) === '') {
            $this->addFlash('warning', 'No rate submitted — nothing changed.');

            return $this->back();
        }

        $config   = $this->gameConfigRepository->getConfig();
        $previous = $config->getInflationRatePerSeasonBp();
        $next     = max(0, (int) $raw);

        if ($next === $previous) {
            // An audit trail of non-changes is noise, and the operator did not do anything.
            $this->addFlash('warning', sprintf('The rate is already %s bp — nothing recorded.', $previous));

            return $this->back();
        }

        $config->setInflationRatePerSeasonBp($next);

        $admin = $this->getUser();
        $this->em->persist(new InflationRateHistory(
            ratePerSeasonBp: $config->getInflationRatePerSeasonBp(),
            previousRatePerSeasonBp: $previous,
            changedBy: $admin instanceof \App\Entity\Admin ? $admin : null,
            note: (string) $request->request->get('note', '') ?: null,
        ));

        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Inflation rate %s bp -> %s bp, recorded. Every existing save\'s derived prices move with it.',
            $previous,
            $next,
        ));

        return $this->back($request->request->get('preview'));
    }

    // ── The preview ───────────────────────────────────────────────────────

    /**
     * The full breakdown for one country, tier by tier, through the REAL resolver.
     *
     * NOT A REIMPLEMENTATION. The whole value of this screen is seeing what generation will
     * actually do, so a second copy of the arithmetic here would be strictly worse than no
     * preview — it would agree with itself and disagree with the world. It also mirrors
     * `app:economy:wage-table`'s checks, so the screen and the command cannot give an operator
     * different answers.
     *
     * @return array<string, mixed>
     */
    /**
     * Season 1 (base-year) owner investment ceiling per tier for one country.
     *
     * @return list<array{tier: int, sharePpm: int, turnoverSharePpm: int, isCustom: bool, ceilingPence: int}>
     */
    private function buildInvestmentCeilingPreview(string $country): array
    {
        $rows = [];

        for ($tier = 1, $n = $this->scaleResolver->tierCountFor($country); $tier <= $n; $tier++) {
            $rows[] = [
                'tier'             => $tier,
                'sharePpm'         => $this->scaleResolver->seasonInvestmentTierSharePpm($country, $tier),
                'turnoverSharePpm' => $this->scaleResolver->seasonInvestmentTurnoverSharePpm($country, $tier),
                'isCustom'         => $this->scaleResolver->seasonInvestmentTierShareIsCustom($country, $tier),
                'ceilingPence'     => $this->scaleResolver->seasonInvestmentCeilingPence($country, $tier, 1),
            ];
        }

        return $rows;
    }

    private function buildPreview(string $country, GameConfig $config): array
    {
        $tierCount = $this->scaleResolver->tierCountFor($country);
        $rows      = [];
        $warnings  = [];
        $prevScale = null;

        for ($tier = 1; $tier <= $tierCount; $tier++) {
            $scale  = $this->scaleResolver->resolve($country, $tier);
            $band   = $this->scaleResolver->abilityBand($country, $tier);
            $league = $this->leagueRepository->findByCountryAndTier($country, $tier);
            $mid    = (int) round(($band['min'] + $band['max']) / 2);

            $perClubTv = $league !== null ? $this->leagueSizeResolver->tvDealFor($league) : null;
            $tvShare   = ($perClubTv !== null && $scale->turnoverPence > 0)
                ? $perClubTv * 100 / $scale->turnoverPence
                : null;

            $rows[] = [
                'tier'            => $tier,
                'countryFactorBp' => $scale->countryFactorBp,
                'tierFactorBp'    => $scale->tierFactorBp,
                'combinedBp'      => $scale->combinedFactorBp,
                'baselinePence'   => $scale->baselineTurnoverPence,
                'turnoverPence'   => $scale->turnoverPence,
                'scaleMicroPence' => $scale->wageScaleMicroPence,
                'resolvable'      => $scale->isResolvable,
                'band'            => $band,
                'squad'           => $this->squadSize($tier),
                'wageMin'         => $this->wageModel->turnoverPlayerPence($band['min'], $country, $tier, 50, $config),
                'wageMid'         => $this->wageModel->turnoverPlayerPence($mid, $country, $tier, 50, $config),
                'wageMax'         => $this->wageModel->turnoverPlayerPence($band['max'], $country, $tier, 50, $config),
                'clubCount'       => $league !== null ? $this->leagueSizeResolver->sizeOf($league) : null,
                'tvPerClubPence'  => $perClubTv,
                'tvSharePct'      => $tvShare,
            ];

            if (!$scale->isResolvable) {
                $warnings[] = sprintf('Tier %d has no baseline turnover authored — the turnover model cannot price it.', $tier);
            } elseif ($prevScale !== null && $scale->wageScaleMicroPence >= $prevScale) {
                $warnings[] = sprintf('Tier %d pays MORE per unit of ability than tier %d.', $tier, $tier - 1);
            }

            if ($tvShare !== null && $tvShare > 100.0) {
                $warnings[] = sprintf(
                    'Tier %d: TV alone is %.0f%% of baseline turnover — re-author the TV table before flipping the model.',
                    $tier,
                    $tvShare,
                );
            }

            if ($scale->isResolvable) {
                $prevScale = $scale->wageScaleMicroPence;
            }
        }

        return [
            'tierCount' => $tierCount,
            'rows'       => $rows,
            'warnings'   => $warnings,
            // Season 1 is always the base year, so this is what a long-running save multiplies
            // its ceiling by — the one place the rate has a visible server-side effect.
            'indexAt10'  => $this->scaleResolver->inflationIndexBp(10),
            'indexAt25'  => $this->scaleResolver->inflationIndexBp(25),
        ];
    }


    /**
     * The resolved sponsor bands and ticket price per tier, and WHERE EACH FIGURE CAME FROM.
     *
     * ── WHY PROVENANCE IS THE POINT, NOT THE FIGURES ──────────────────────────────────────
     *
     * There are now three places a sponsor band can come from — the explicit per-tier row, the
     * flat column x this tier's ratio, or the flat column alone — and they resolve in that
     * order. A screen that printed only the resolved number would leave an operator unable to
     * answer the one question they actually have when a figure looks wrong: which table am I
     * editing to change it? Two of the three sources look identical in the output (a parity
     * ratio and no ratio give the same band), so the answer cannot be inferred from the
     * number.
     *
     * READ-ONLY, AND THROUGH THE REAL RESOLVERS, for the reason buildPreview() already states:
     * a second copy of the arithmetic here would agree with itself and disagree with the
     * world.
     *
     * ── WARN, NEVER FORBID ────────────────────────────────────────────────────────────────
     *
     * Non-monotonic ratios and a company size with no band are reported, not rejected. A
     * division that pays better than the one above it may be deliberate, and a size left at
     * zero is a legitimate half-authored state on the way to a full table. The setter's job is
     * eight integers; the screen's job is to make an accident visible.
     *
     * @return array<string, mixed>
     */
    private function buildRevenuePreview(string $country, GameConfig $config): array
    {
        $tierCount   = $this->scaleResolver->tierCountFor($country);
        $explicit    = $config->getEconomySponsorBandsPerTierPence();
        $sponsorRatio = $config->getEconomySponsorTierRatioBp();
        $ticketRatio = $config->getEconomyTicketPriceTierRatioBp();
        $ticketBase  = $config->getEconomyTicketPriceTier1Pence();
        $explicitTicket = $config->getEconomyTicketPricePerTierPence();

        $rows     = [];
        $warnings = [];

        for ($tier = 1; $tier <= $tierCount; $tier++) {
            $row = [
                'tier'         => $tier,
                'sponsorRatioBp' => $sponsorRatio[$tier - 1] ?? 10000,
                'ticketRatioBp'  => $ticketRatio[$tier - 1] ?? 10000,
                'sizes'        => [],
                'ticketPence'  => $this->ticketPrices->priceFor($tier, $country, $config),
            ];

            foreach (CompanySize::cases() as $size) {
                [$min, $max] = $this->sponsorBands->bandFor($size, $country, $config, $tier);

                $row['sizes'][$size->value] = [
                    'min'    => $min,
                    'max'    => $max,
                    // The resolution order, named. 'flat' and 'ratio' can produce the same
                    // figure, which is exactly why the label is not derived from the figure.
                    'source' => isset($explicit[(string) $tier][$size->value])
                        ? 'override'
                        : (($sponsorRatio[$tier - 1] ?? 10000) !== 10000 ? 'ratio' : 'flat'),
                ];

                if ($max <= 0) {
                    $warnings[] = sprintf(
                        'Tier %d has no %s sponsor band — a sponsor of that size pays nothing. Author the flat column or this tier\'s row.',
                        $tier,
                        strtoupper($size->value),
                    );
                }
            }

            $row['ticketSource'] = ($explicitTicket[$tier - 1] ?? 0) > 0
                ? 'override'
                : ($ticketBase > 0 ? 'ratio' : 'unauthored');

            $rows[] = $row;
        }

        // NON-MONOTONIC IS A WARNING, and it is checked on the RATIOS rather than on the
        // resolved bands: the country factor is a constant down the table, so a ladder that
        // rises in ratio terms rises everywhere, and saying so in the operator's own units is
        // what makes the message actionable.
        foreach ([
            ['sponsor ratio', $sponsorRatio],
            ['ticket ratio',  $ticketRatio],
        ] as [$label, $table]) {
            for ($i = 1; $i < min(8, $tierCount); $i++) {
                if (($table[$i] ?? 0) > ($table[$i - 1] ?? 0)) {
                    $warnings[] = sprintf(
                        'Tier %d\'s %s (%d bp) is HIGHER than tier %d\'s (%d bp). Allowed, but check it is deliberate.',
                        $i + 1,
                        $label,
                        $table[$i] ?? 0,
                        $i,
                        $table[$i - 1] ?? 0,
                    );
                }
            }
        }

        if (($sponsorRatio[0] ?? 10000) !== 10000) {
            $warnings[] = sprintf(
                'The tier-1 sponsor ratio is %d bp, not 10000. Tier 1 IS the base, so this scales the base against itself.',
                $sponsorRatio[0],
            );
        }

        if (($ticketRatio[0] ?? 10000) !== 10000) {
            $warnings[] = sprintf(
                'The tier-1 ticket ratio is %d bp, not 10000. Tier 1 IS the base, so this scales the base against itself.',
                $ticketRatio[0],
            );
        }

        if ($ticketBase <= 0) {
            $warnings[] = 'No tier-1 ticket base is authored, so every published ticket price is 0 — the app falls back to its own 2500p constant whatever the ratios say.';
        }

        return [
            'tierCount' => $tierCount,
            'rows'      => $rows,
            'warnings'  => $warnings,
            'ticketBasePence' => $ticketBase,
        ];
    }

    /**
     * What each tier's champion and placings are paid, and what that costs the division.
     *
     * ── WHY THE LEAGUE TOTAL IS THE COLUMN THAT MATTERS ───────────────────────────────────
     *
     * `leaguePositionPot` is the FIRST-PLACE amount, not a pool, and the per-club figure is the
     * one an operator types — so the figure that actually left the building is invisible on the
     * authoring screen. At the configured 10% taper a division pays out 5.5x the pot, and that
     * is how a tier-8 pot of £2,000,000 came to cost £11,000,000 a year against £2,967,328 of
     * combined authored turnover without anybody noticing. This column is that arithmetic, run
     * once, beside the figure that causes it.
     *
     * ── COMPUTED PER LEAGUE, NOT ASSUMED ──────────────────────────────────────────────────
     *
     * The taper sum is 5.5 for any league of 11 clubs or more at a 10% taper, which makes it
     * tempting to hardcode. It is not constant: `slots` is 10 in KR, 14 in BE, 18 in EN and 20
     * in FR, and `leaguePositionDecreasePercent` has a per-league override. So the club count
     * and the decrease come off THIS country's league row, through the same
     * `LeagueSizeResolver` the TV divisor uses, and both are shown in the row so the arithmetic
     * can be checked rather than trusted.
     *
     * READ-ONLY AND THROUGH THE REAL RESOLVER, as the sibling previews are.
     *
     * @return array<string, mixed>
     */
    private function buildPayoutPreview(string $country, GameConfig $config): array
    {
        $tierCount   = $this->scaleResolver->tierCountFor($country);
        $prizeRatio  = $config->getEconomyPrizeTierRatioPpm();
        $potRatio    = $config->getEconomyPositionPotTierRatioPpm();
        $prizeBase   = $config->getEconomyLeaguePrizeTier1Pence();
        $potBase     = $config->getEconomyLeaguePositionPotTier1Pence();
        $turnover    = $config->getEconomyBaselineTurnoverPence();

        $rows     = [];
        $warnings = [];

        for ($tier = 1; $tier <= $tierCount; $tier++) {
            $league = $this->leagueRepository->findOneBy(['country' => $country, 'tier' => $tier]);

            $prize = $league !== null
                ? $this->leaguePayouts->prizeFor($league, $config)
                : $this->leaguePayouts->inheritedFor($tier, $country, $config)['prize'];
            $pot = $league !== null
                ? $this->leaguePayouts->positionPotFor($league, $config)
                : $this->leaguePayouts->inheritedFor($tier, $country, $config)['positionPot'];

            $clubs = $league !== null ? $this->leagueSizeResolver->sizeOf($league) : 0;
            $decrease = $league?->getLeaguePositionDecreasePercent()
                ?? $config->getLeaguePositionDecreasePercent();
            $taperSumPpm = $this->leaguePayouts->taperSumPpm($clubs, $decrease);

            // The division's whole payout: the TV pool as authored, every placing's share of
            // the pot, and the champion's prize once.
            $tvPool        = $league !== null ? ($this->leagueSizeResolver->tvPoolPenceFor($league) ?? 0) : 0;
            $positionTotal = $pot !== null ? intdiv($pot * $taperSumPpm, 1000000) : 0;
            $leagueTotal   = $tvPool + $positionTotal + ($prize ?? 0);
            $leagueTurnover = ($turnover[$tier - 1] ?? 0) * $clubs;

            $rows[] = [
                'tier'            => $tier,
                'prizeRatioPpm'   => $prizeRatio[$tier - 1] ?? GameConfig::TIER_RATIO_PPM_PARITY,
                'potRatioPpm'     => $potRatio[$tier - 1] ?? GameConfig::TIER_RATIO_PPM_PARITY,
                'prizePence'      => $prize,
                'potPence'        => $pot,
                // THE RESOLUTION ORDER, NAMED, and not derived from the figure — an override
                // and a ratio can land on the same number, which is exactly when an operator
                // most needs to be told which one is in force.
                'prizeSource'     => $league?->getPrizeMoney() !== null
                    ? 'override'
                    : ($prizeBase > 0 ? 'ratio' : 'unauthored'),
                'potSource'       => $league?->getLeaguePositionPot() !== null
                    ? 'override'
                    : ($potBase > 0 ? 'ratio' : 'unauthored'),
                'clubs'           => $clubs,
                'decreasePercent' => $decrease,
                'taperSumPpm'     => $taperSumPpm,
                'tvPoolPence'     => $tvPool,
                'positionTotalPence' => $positionTotal,
                'leagueTotalPence'   => $leagueTotal,
                'leagueTurnoverPence' => $leagueTurnover,
                // Null rather than 0 when turnover is unauthored: "no share" and "a share of
                // nothing" are different states and the template prints them differently.
                'shareOfTurnoverPct' => $leagueTurnover > 0
                    ? round($leagueTotal / $leagueTurnover * 100, 1)
                    : null,
                'championSharePct' => ($turnover[$tier - 1] ?? 0) > 0 && $prize !== null && $pot !== null
                    ? round(($prize + $pot) / $turnover[$tier - 1] * 100, 1)
                    : null,
            ];
        }

        // NON-MONOTONIC IS A WARNING, checked on the ratios and in the operator's own unit, as
        // the sponsor and ticket tables are.
        foreach ([
            ['prize ratio',        $prizeRatio],
            ['position pot ratio', $potRatio],
        ] as [$label, $table]) {
            for ($i = 1; $i < min(8, $tierCount); $i++) {
                if (($table[$i] ?? 0) > ($table[$i - 1] ?? 0)) {
                    $warnings[] = sprintf(
                        'Tier %d\'s %s (%d ppm) is HIGHER than tier %d\'s (%d ppm). Allowed, but a lower division paying more than the one above it is how the ladder inverted before.',
                        $i + 1,
                        $label,
                        $table[$i] ?? 0,
                        $i,
                        $table[$i - 1] ?? 0,
                    );
                }
            }
        }

        foreach ([
            ['prize', $prizeRatio[0] ?? GameConfig::TIER_RATIO_PPM_PARITY],
            ['position pot', $potRatio[0] ?? GameConfig::TIER_RATIO_PPM_PARITY],
        ] as [$label, $tier1]) {
            if ($tier1 !== GameConfig::TIER_RATIO_PPM_PARITY) {
                $warnings[] = sprintf(
                    'The tier-1 %s ratio is %d ppm, not %d. Tier 1 IS the base, so this scales the base against itself.',
                    $label,
                    $tier1,
                    GameConfig::TIER_RATIO_PPM_PARITY,
                );
            }
        }

        if ($prizeBase <= 0) {
            $warnings[] = 'No tier-1 champion prize is authored, so every division falls back to its own stored column whatever the ratios say.';
        }

        if ($potBase <= 0) {
            $warnings[] = 'No tier-1 position pot is authored, so every division falls back to its own stored column whatever the ratios say.';
        }

        foreach ($rows as $row) {
            if ($row['shareOfTurnoverPct'] !== null && $row['shareOfTurnoverPct'] > 100) {
                $warnings[] = sprintf(
                    'Tier %d\'s division pays out %s%% of its own combined authored turnover (%s against %s across %d clubs). Allowed, but it means the league hands out more than its clubs are modelled to earn.',
                    $row['tier'],
                    number_format($row['shareOfTurnoverPct'], 1),
                    '£' . number_format($row['leagueTotalPence'] / 100, 0),
                    '£' . number_format($row['leagueTurnoverPence'] / 100, 0),
                    $row['clubs'],
                );
            }
        }

        return [
            'tierCount'      => $tierCount,
            'rows'           => $rows,
            'warnings'       => $warnings,
            'prizeBasePence' => $prizeBase,
            'potBasePence'   => $potBase,
        ];
    }

    private function squadSize(int $tier): int
    {
        $row = $this->scaleResolver->npcSquadRow($tier);
        $min = (int) ($row['playerMin'] ?? 0);
        $max = (int) ($row['playerMax'] ?? 0);

        return max(1, intdiv(min($min, $max) + max($min, $max), 2));
    }

    // ── Plumbing ──────────────────────────────────────────────────────────

    /**
     * Applies an integer field only when it was actually submitted with a value.
     *
     * The guard the game-config screen's contract-value pair needed and did not have: a field
     * an operator CLEARED posts '', which casts to 0, and a 0 here is a live configuration
     * rather than an absent one. Blank means "leave it alone".
     *
     * @param callable(int): mixed $apply
     */
    private function setIfPresent(Request $request, string $field, callable $apply): void
    {
        $raw = $request->request->get($field);

        if ($raw !== null && trim((string) $raw) !== '') {
            $apply((int) $raw);
        }
    }

    /**
     * Back to the screen, through the EasyAdmin entry point.
     *
     * Per `src/Controller/Admin/CLAUDE.md`: a redirect to the route directly would not carry
     * the `ea` Twig context and the page would die rendering its own layout. The preview
     * selection is carried over so a save does not silently reset which country is on screen.
     */
    private function back(?string $preview = null): Response
    {
        $params = ['routeName' => 'admin_economy'];

        if ($preview !== null && trim($preview) !== '') {
            $params['preview'] = strtoupper(trim($preview));
        }

        return $this->redirect($this->generateUrl('admin', $params));
    }
}
