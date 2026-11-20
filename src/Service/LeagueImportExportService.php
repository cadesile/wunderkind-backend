<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\NpcClub;
use App\Enum\CitySize;
use App\Enum\Formation;
use App\Enum\ReputationTier;
use App\Repository\GameConfigRepository;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Service\Economy\LeaguePayoutResolver;
use App\Service\Economy\LeagueSizeResolver;
use Doctrine\ORM\EntityManagerInterface;

class LeagueImportExportService
{
    private const EXPORT_VERSION = 1;

    public function __construct(
        private readonly LeagueRepository       $leagueRepository,
        private readonly NpcClubRepository      $npcClubRepository,
        private readonly EntityManagerInterface $em,
        private readonly LeaguePayoutResolver   $leaguePayoutResolver,
        private readonly GameConfigRepository   $gameConfigRepository,
        private readonly LeagueSizeResolver     $leagueSizeResolver,
    ) {}

    // ── Export ────────────────────────────────────────────────────────────────

    public function export(): array
    {
        $leagues = $this->leagueRepository->findAll();
        $clubs   = $this->npcClubRepository->findAll();

        // ONE CONFIG FOR THE WHOLE EXPORT, as `SyncService::buildLeagueSnapshot()` and
        // `/api/initialize/leagues` both do: `prizeFor()` would otherwise re-fetch it per
        // league, and a world export is 196 of them.
        $config = $this->gameConfigRepository->getConfig();

        $leagueData = [];
        foreach ($leagues as $l) {
            $leagueData[] = [
                'country'              => $l->getCountry(),
                'tier'                 => $l->getTier(),
                'name'                 => $l->getName(),
                'promotionSpots'       => $l->getPromotionSpots(),
                // ── RESOLVED, AND THE SAME TWO-KEY CONTRACT AS THE PAYOUTS BELOW ───────────
                //
                // `tvDeal` is DERIVED whenever `tvPoolPence` is authored — `LeagueSizeResolver`
                // owns that arithmetic and `LeagueTierDefaultDiff:103` already states the rule.
                // But `syncTvDeal()` is called ONLY from an admin save
                // (`LeagueAdminController:95`/`:201`); `LeagueService:82` deliberately skips it
                // at generation because no NPC club exists yet, and nothing calls it afterwards.
                // So a generated pooled league keeps a NULL column for ever — which is the live
                // state of Scottish tiers 5-8 — and serialising the raw column published NULL.
                // The app reads `Math.max(0, league.tvDealPence)` (`seasonFinances.ts:80`), so
                // those tiers earned £0 of TV in a running save, not merely in a gate report.
                //
                // `derivedTvDealPence()` returns null when there is no pool, so every league
                // that authors its deal directly exports exactly what it did before.
                'tvDeal'               => $this->leagueSizeResolver->tvDealFor($l),
                'tvPoolPence'          => $l->getTvPoolPence(),
                // ⚠️ `slots` IS NOT EXPORTED, AND NOT IMPORTED. Owner ruling, 2026-11-19:
                // *"Retire slots."* `LeagueSizeResolver::sizeOf()` derives every division size
                // from the NPC club rows, which this file exports under `clubs`, so the size
                // round-trips through the rows and the column has nothing left to carry.
                // Emitting it would have meant breaking the round-trip invariant
                // (`testWorldSurvivesClearAndReimport`: whatever the export emits must come
                // back) for a field nothing reads — so it is excluded at both ends instead,
                // registered as such in `worldEntityProvider()`'s exclusion list. The DATABASE
                // column stays; `app:economy:wage-table`'s check 9 prints it beside the derived
                // size for anyone reconciling an older export against a running box.
                'leaguePositionDecreasePercent' => $l->getLeaguePositionDecreasePercent(),
                'leagueReputationTier' => $l->getLeagueReputationTier()?->value,
                // ── RESOLVED, NOT THE RAW COLUMN, AND THE KEY NAME IS THE WHOLE POINT ──────
                //
                // `Version20261118120000` NULLed both columns so they inherit from the ppm
                // ladder, which is `5dc3f51b`'s design. Serialising the raw column therefore
                // read £0 in every export taken after it, and the app's economy gate
                // (`scripts/economy/variantGate.ts`, `dispersionGate.ts`) reads
                // `league?.prizeMoney ?? 0` — so it credited zero prize and zero position money
                // and every gate run since has needed a hand-patch.
                //
                // The resolved figure arrives under the EXISTING key because that is the one the
                // gate reads: publishing it beside the raw column under a new name would need a
                // change in `wunderkind-app` to be worth anything. Same chain as
                // `SyncService::buildLeagueSnapshot()` and `/api/initialize/leagues`.
                'prizeMoney'           => $this->leaguePayoutResolver->prizeFor($l, $config),
                'leaguePositionPot'    => $this->leaguePayoutResolver->positionPotFor($l, $config),
                // ── AND THE RAW COLUMNS, SO THE EXPORT IS STILL A BACKUP ───────────────────
                //
                // ⚠️ THESE TWO ARE THE ONES `import()` READS. The resolved keys above are
                // export-only and deliberately ignored on the way back in — see
                // `importLeagues()`. An operator forcing a per-league override must edit the
                // `*Override` key; editing `prizeMoney` does nothing.
                //
                // Null here means "inherit", which is the state of all 196 leagues today, and
                // round-tripping must leave it null rather than freezing the ladder into 196
                // per-league overrides.
                // ⚠️ AND THE RAW COLUMN, WHICH IS THE ONE `import()` READS. Null means "derive
                // from the pool"; a value means an operator authored this league's deal by hand.
                // Round-tripping must leave a derived league's column NULL rather than freezing
                // the resolved share into it — the same trap the payout pair carries.
                'tvDealOverride'       => $l->getTvDeal(),
                'prizeMoneyOverride'   => $l->getPrizeMoney(),
                'leaguePositionPotOverride' => $l->getLeaguePositionPot(),
                'sponsorCount'         => $l->getSponsorCount(),
                'trophy'               => $l->getTrophy(),
                'parachute'            => $l->getParachute(),
            ];
        }

        $clubData = [];
        foreach ($clubs as $c) {
            $clubData[] = [
                'name'              => $c->getName(),
                'country'           => $c->getCountry(),
                'tier'              => $c->getTier(),
                'reputation'        => $c->getReputation(),
                'primaryColor'      => $c->getPrimaryColor(),
                'secondaryColor'    => $c->getSecondaryColor(),
                'stadiumName'       => $c->getStadiumName(),
                'balance'           => $c->getBalance(),
                'playingStyle'      => $c->getPlayingStyle(),
                'financialApproach' => $c->getFinancialApproach(),
                'managerTemperament'=> $c->getManagerTemperament(),
                'facilities'        => $c->getFacilities(),
                'formation'         => $c->getFormation()->value,
                'abbreviation'      => $c->getAbbreviation(),
                'region'            => $c->getRegion(),
                'citySize'          => $c->getCitySize()->value,
                'populationSize'    => $c->getPopulationSize(),
                'isCapital'         => $c->isCapital(),
                'identity'          => $c->getIdentity(),
            ];
        }

        return [
            'version'    => self::EXPORT_VERSION,
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'leagues'    => $leagueData,
            'clubs'      => $clubData,
        ];
    }

    // ── Import ────────────────────────────────────────────────────────────────

    /**
     * @return array{applied: bool, errors: string[]}
     */
    public function import(array $data): array
    {
        $result = ['applied' => false, 'errors' => []];

        if (($data['version'] ?? null) !== self::EXPORT_VERSION) {
            $result['errors'][] = 'Unsupported export version — expected version ' . self::EXPORT_VERSION;
            return $result;
        }

        try {
            if (is_array($data['leagues'] ?? null)) {
                $result['errors'] = array_merge($result['errors'], $this->importLeagues($data['leagues']));
            }
            if (is_array($data['clubs'] ?? null)) {
                $result['errors'] = array_merge($result['errors'], $this->importClubs($data['clubs']));
            }
            $this->em->flush();

            // After import, ensure correct league association
            $this->relinkClubsToLeagues();
            $this->em->flush();

            $result['applied'] = true;
        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Reports malformed rows rather than abandoning the file — a single hand-edited row
     * should not cost the operator the other few hundred.
     *
     * @return string[]
     */
    private function importLeagues(array $rows): array
    {
        $errors = [];

        foreach ($rows as $row) {
            $created = null;
            try {
                $league = $this->leagueRepository->findByCountryAndTier($row['country'] ?? null, (int) ($row['tier'] ?? 0));

                if (!$league) {
                    $league = $created = new League($row['country'], (int) $row['tier'], $row['name']);
                    $this->em->persist($league);
                }

                $league->setName($row['name']);

                // Use array_key_exists (not isset) so explicit null values are applied correctly
                if (array_key_exists('promotionSpots', $row))
                    $league->setPromotionSpots($row['promotionSpots'] !== null ? (int) $row['promotionSpots'] : null);
                // THE OVERRIDE KEY WINS, KEYED ON ITS PRESENCE RATHER THAN ITS VALUE — the
                // same idiom as the payout pair below and safe for the same reason. Every export
                // written from here on carries `tvDealOverride` even when null, so a row WITHOUT
                // it can only be a file written before this change, where `tvDeal` WAS the raw
                // column and restoring it is correct. A new file can therefore never reach the
                // fallback, so a RESOLVED share can never be written into the column.
                if (array_key_exists('tvDealOverride', $row)) {
                    $league->setTvDeal($row['tvDealOverride'] !== null ? (int) $row['tvDealOverride'] : null);
                } elseif (array_key_exists('tvDeal', $row)) {
                    $league->setTvDeal($row['tvDeal'] !== null ? (int) $row['tvDeal'] : null);
                }
                // ── THE OVERRIDE COLUMNS, AND THE RESOLVED KEYS ARE NOT READ ──────────────
                //
                // ⚠️ THIS IS THE ROUND-TRIP TRAP, AND IT IS WHY THE KEYS ARE SPLIT. `export()`
                // now emits RESOLVED payouts under `prizeMoney` / `leaguePositionPot` because
                // that is what the app's gate reads. Writing those back into the columns would
                // convert every inherit (NULL) into a per-league override on the first round
                // trip — permanently freezing the ppm ladder into 196 rows and defeating
                // `5dc3f51b`'s NULL-means-inherit design, silently and with no error.
                //
                // So the resolved keys are IGNORED here, and the raw columns come back from
                // `prizeMoneyOverride` / `leaguePositionPotOverride` instead. A NULL override
                // restores as NULL, which is what keeps the ladder inherited.
                //
                // THE FALLBACK IS KEYED ON THE OVERRIDE KEY'S PRESENCE, NOT ITS VALUE, and that
                // is what makes it safe. Every export written from here on carries
                // `prizeMoneyOverride` even when it is null, so a row WITHOUT the key can only
                // be a file written before this change — where `prizeMoney` was the raw column
                // and restoring it is correct. A new file can therefore never reach the
                // fallback, so a resolved figure can never be written to a column. Same idiom
                // as the `trophy` / `trophyImage` pair below, and for the same reason: a world
                // backup an admin committed months ago must still restore.
                if (array_key_exists('prizeMoneyOverride', $row)) {
                    $league->setPrizeMoney($row['prizeMoneyOverride'] !== null ? (int) $row['prizeMoneyOverride'] : null);
                } elseif (array_key_exists('prizeMoney', $row)) {
                    $league->setPrizeMoney($row['prizeMoney'] !== null ? (int) $row['prizeMoney'] : null);
                }
                if (array_key_exists('leaguePositionPotOverride', $row)) {
                    $league->setLeaguePositionPot($row['leaguePositionPotOverride'] !== null ? (int) $row['leaguePositionPotOverride'] : null);
                } elseif (array_key_exists('leaguePositionPot', $row)) {
                    $league->setLeaguePositionPot($row['leaguePositionPot'] !== null ? (int) $row['leaguePositionPot'] : null);
                }
                // RESTORED VERBATIM, AND tvDeal IS STILL NOT RE-DERIVED FROM THE POOL HERE.
                // The export now carries the resolved share under `tvDeal` and the raw column
                // under `tvDealOverride`, and it is the override that is restored above, so a
                // clear-then-import round trip still reproduces the file exactly —
                // testWorldSurvivesClearAndReimport asserts that. Re-deriving
                // would also divide by a size taken mid-import, when the NPC clubs that make up
                // that size may not have been inserted yet.
                if (array_key_exists('tvPoolPence', $row))
                    $league->setTvPoolPence($row['tvPoolPence'] !== null ? (int) $row['tvPoolPence'] : null);
                // VERBATIM BOTH WAYS. No cast, no validation — the backend has no opinion
                // about this block's shape, so a round trip must return exactly what it was
                // given or the export stops being a backup.
                if (array_key_exists('parachute', $row))
                    $league->setParachute(is_array($row['parachute']) ? $row['parachute'] : null);
                // ⚠️ `slots` IS DELIBERATELY NOT RESTORED. It used to be, and the owner retired
                // the field on 2026-11-19: restoring it would put an authored divisor back into
                // the database on every world import, where the next reader would find a
                // populated column and reasonably assume something reads it. The size is
                // derived from the NPC club rows the same import inserts, so nothing is lost.
                // The key is still EXPORTED (see `exportWorld()`); the asymmetry is the point.
                if (array_key_exists('leaguePositionDecreasePercent', $row))
                    $league->setLeaguePositionDecreasePercent(
                        $row['leaguePositionDecreasePercent'] !== null
                            ? (int) $row['leaguePositionDecreasePercent']
                            : null
                    );
                if (array_key_exists('leagueReputationTier', $row))
                    $league->setLeagueReputationTier(
                        $row['leagueReputationTier'] !== null
                            ? ReputationTier::from((string) $row['leagueReputationTier'])
                            : null
                    );
                if (array_key_exists('sponsorCount', $row))
                    $league->setSponsorCount((int) $row['sponsorCount']);
                // The `trophyImage` fallback is for export files written before the
                // trophy sprite replaced the old slug+colour pair: those carry the two
                // legacy keys and no 'trophy'. Converting rather than ignoring them
                // means a world backup an admin committed months ago still restores
                // its trophies — see TrophyConfigFactory::fromLegacy().
                if (array_key_exists('trophy', $row)) {
                    $league->setTrophy(TrophyConfigFactory::validateNullable($row['trophy']));
                } elseif (array_key_exists('trophyImage', $row)) {
                    $league->setTrophy(TrophyConfigFactory::fromLegacy(
                        is_string($row['trophyImage']) ? $row['trophyImage'] : null,
                        is_string($row['trophyColour'] ?? null) ? $row['trophyColour'] : null,
                    ));
                }
            } catch (\Throwable $e) {
                // Don't leave a half-built row behind: a rejected row must not reach the flush.
                if ($created !== null) {
                    $this->em->detach($created);
                }
                $errors[] = 'league[' . ($row['country'] ?? '?') . ' tier ' . ($row['tier'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        return $errors;
    }

    /** @return string[] */
    private function importClubs(array $rows): array
    {
        $errors = [];

        foreach ($rows as $row) {
            $created = null;
            try {
                $club = $this->npcClubRepository->findOneBy([
                    'country' => $row['country'],
                    'tier'    => (int) $row['tier'],
                    'name'    => $row['name']
                ]);

                if (!$club) {
                    $club = $created = new NpcClub(
                        $row['name'],
                        $row['country'],
                        (int) $row['tier'],
                        (int) ($row['reputation'] ?? 0),
                        (string) ($row['primaryColor'] ?? '#000000'),
                        (string) ($row['secondaryColor'] ?? '#FFFFFF'),
                        (int) ($row['balance'] ?? 0),
                        (array) ($row['facilities'] ?? [])
                    );
                    $this->em->persist($club);
                }

                if (array_key_exists('reputation', $row))         $club->setReputation((int) $row['reputation']);
                if (array_key_exists('primaryColor', $row))       $club->setPrimaryColor((string) $row['primaryColor']);
                if (array_key_exists('secondaryColor', $row))     $club->setSecondaryColor((string) $row['secondaryColor']);
                if (array_key_exists('stadiumName', $row))        $club->setStadiumName($row['stadiumName']);
                if (array_key_exists('balance', $row))            $club->setBalance((int) $row['balance']);
                if (array_key_exists('playingStyle', $row))       $club->setPlayingStyle((string) $row['playingStyle']);
                if (array_key_exists('financialApproach', $row))  $club->setFinancialApproach((string) $row['financialApproach']);
                if (array_key_exists('managerTemperament', $row)) $club->setManagerTemperament((int) $row['managerTemperament']);
                if (array_key_exists('facilities', $row))         $club->setFacilities((array) $row['facilities']);
                if (isset($row['formation']))                     $club->setFormation(Formation::from((string) $row['formation']));
                if (array_key_exists('abbreviation', $row))       $club->setAbbreviation($row['abbreviation'] !== null ? (string) $row['abbreviation'] : null);
                if (array_key_exists('region', $row))             $club->setRegion($row['region'] !== null ? (string) $row['region'] : null);
                if (isset($row['citySize']))                      $club->setCitySize(CitySize::from((string) $row['citySize']));
                if (array_key_exists('populationSize', $row))     $club->setPopulationSize((int) $row['populationSize']);
                if (array_key_exists('isCapital', $row))          $club->setIsCapital((bool) $row['isCapital']);
                if (array_key_exists('identity', $row))           $club->setIdentity($row['identity'] !== null ? (array) $row['identity'] : null);
            } catch (\Throwable $e) {
                if ($created !== null) {
                    $this->em->detach($created);
                }
                $errors[] = 'club[' . ($row['name'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        return $errors;
    }

    private function relinkClubsToLeagues(): void
    {
        $leagues = $this->leagueRepository->findAll();
        $leagueMap = [];
        foreach ($leagues as $l) {
            $leagueMap[$l->getCountry()][$l->getTier()] = $l;
        }

        $clubs = $this->npcClubRepository->findAll();
        foreach ($clubs as $c) {
            $c->setLeague($leagueMap[$c->getCountry()][$c->getTier()] ?? null);
        }
    }

    // ── Clear ─────────────────────────────────────────────────────────────────

    public function clearAll(): void
    {
        // Nullify Club.currentLeague references before deleting League rows,
        // otherwise the FK constraint (Club → League, no ON DELETE rule) will reject the delete.
        $this->em->createQuery('UPDATE ' . Club::class . ' c SET c.currentLeague = NULL')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\NpcClub')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\League')->execute();
        $this->em->flush();
    }
}
