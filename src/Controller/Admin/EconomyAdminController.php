<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\GameConfig;
use App\Entity\InflationRateHistory;
use App\Enum\Country;
use App\Enum\EconomyWageModel;
use App\Repository\GameConfigRepository;
use App\Repository\InflationRateHistoryRepository;
use App\Repository\LeagueRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\LeagueSizeResolver;
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
        $seasonBase = $request->request->get('seasonInvestmentBasePounds');
        if ($seasonBase !== null) {
            $raw = str_replace(',', '', trim((string) $seasonBase));
            $config->setEconomySeasonInvestmentBasePence(
                $raw === '' ? 0 : (int) round(((float) $raw) * 100),
            );
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

            $perClubTv = $league?->getTvDeal();
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
