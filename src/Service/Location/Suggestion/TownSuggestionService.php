<?php

declare(strict_types=1);

namespace App\Service\Location\Suggestion;

use App\Entity\Admin;
use App\Entity\Location\LocationEnrichmentRun;
use App\Enum\Country;
use App\Enum\Location\EnrichmentTrigger;
use App\Repository\Location\CountryProfileRepository;
use App\Service\Location\LocationSeedService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Ask the model for more towns for a country, and add the ones worth adding.
 *
 * ALL THE LOGIC LIVES HERE, not in the command or the controller — the same split
 * SeedLocationsCommand insists on, so the CLI and the admin button cannot drift.
 *
 * SYNCHRONOUS, and capped. One API call, not one per town, so this is cheap in a way a
 * sweep is not; running it in the request means a failure is visible to the operator who
 * clicked rather than buried on a queue they would have to go looking for.
 *
 * THE EXPENSIVE PART IS DOWNSTREAM. Every town added becomes a row that will eventually cost
 * one enrichment call when a player opens it. That is the intended design — enrichment is
 * lazy on purpose — but it is why the cap exists and why this does not quietly default to
 * "as many as possible".
 */
final class TownSuggestionService
{
    /** The form default, and the ceiling on a single run. */
    public const DEFAULT_MAX = 300;

    public const HARD_MAX = 1_000;

    public function __construct(
        private readonly TownSuggesterInterface $suggester,
        private readonly TownSuggestionValidator $validator,
        private readonly LocationSeedService $seedService,
        private readonly CountryProfileRepository $countryProfileRepository,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{
     *     country: string, asked: int, offered: int, accepted: int, imported: int,
     *     alreadyHeld: int, rejected: array<string, string>, duplicates: int,
     *     nearDuplicates: list<array{imported: string, existing: string}>,
     *     inputTokens: ?int, outputTokens: ?int, dryRun: bool
     * }
     */
    public function suggestForCountry(Country $country, int $max = self::DEFAULT_MAX, bool $dryRun = false, ?Admin $requestedBy = null): array
    {
        $code = $country->value;
        $max  = max(1, min($max, self::HARD_MAX));

        $profile = $this->countryProfileRepository->findByCode($code);

        if ($profile === null) {
            // Same refusal as importPlaceNames(), for the same reason: a catalogue with no
            // curated anchor has nothing priced and nothing generatable, and a readiness gate
            // it can never pass.
            throw new \InvalidArgumentException(\sprintf(
                "'%s' has no country profile — run app:locations:seed %s first.",
                $code,
                $code,
            ));
        }

        $existing = $this->existingTownNames($code);

        $result = $this->suggester->suggest($profile, $existing, $max);
        $verdict = $this->validator->validate($result->towns, $max);

        /** @var list<SuggestedTown> $accepted */
        $accepted = $verdict['accepted'];
        $names    = array_map(static fn (SuggestedTown $t): string => $t->name, $accepted);

        // Captured BEFORE the import, because importNames() ends with em->clear() and a
        // detached Admin attached to the run below would blow up on flush.
        $adminId = $requestedBy?->getId();

        // importNames() owns the database dedupe: it holds the snapshot and the normalisation,
        // and having a second opinion about either here is how the two come to disagree.
        $import = $this->seedService->importNames($country, $names, $dryRun);

        $summary = [
            'country'        => $code,
            'asked'          => $max,
            'offered'        => \count($result->towns),
            'accepted'       => \count($accepted),
            'imported'       => $import['imported'],
            // Offered despite being in the exclusion list — the prompt list is truncated for
            // big countries, so some overlap is expected rather than a fault.
            'alreadyHeld'    => $import['skipped'],
            'rejected'       => $verdict['rejected'],
            'duplicates'     => $verdict['duplicates'] + $import['duplicates'],
            'nearDuplicates' => $import['nearDuplicates'],
            'inputTokens'    => $result->inputTokens,
            'outputTokens'   => $result->outputTokens,
            'dryRun'         => $dryRun,
        ];

        if (!$dryRun) {
            $this->recordRun($code, $summary, $adminId);
        }

        $this->logger->info('Town suggestion run', $summary + [
            'provider'      => $this->suggester->provider(),
            'model'         => $this->suggester->model(),
            'promptVersion' => $this->suggester->promptVersion(),
        ]);

        return $summary;
    }

    /** @return array{ok: bool, detail: string} */
    public function checkConnection(): array
    {
        return $this->suggester->checkConnection();
    }

    /**
     * Towns already held, biggest first.
     *
     * Biggest first because the prompt list is truncated: if only four hundred names fit, the
     * four hundred the model is most likely to suggest anyway are the ones worth spending
     * them on. Unpriced imported towns have no population and sort last, which is correct —
     * the model is unlikely to lead with them.
     *
     * @return list<string>
     */
    private function existingTownNames(string $code): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT town FROM location WHERE country_code = :code ORDER BY population DESC NULLS LAST, town ASC',
            ['code' => $code],
        );

        return array_map(static fn (mixed $t): string => (string) $t, $rows);
    }

    /**
     * @param array<string, mixed> $summary
     *
     * Takes the admin's id rather than the entity: importNames() clears the entity manager,
     * so the Admin handed in at the start of the run is detached by the time we get here and
     * attaching it to the run would fail on flush.
     */
    private function recordRun(string $code, array $summary, ?UuidV7 $adminId): void
    {
        $run = new LocationEnrichmentRun(
            EnrichmentTrigger::ADMIN_TOWN_SUGGESTION,
            $this->suggester->provider(),
            $this->suggester->model(),
            $code,
            $adminId === null ? null : $this->em->find(Admin::class, $adminId),
        );

        // Counter meanings for this trigger are documented on the enum case.
        $run->setQueued((int) $summary['accepted']);
        $run->setSettled(
            succeeded: (int) $summary['imported'],
            failed: \count($summary['rejected']) + (int) $summary['alreadyHeld'],
        );
        $run->markComplete();

        $this->em->persist($run);
        $this->em->flush();
    }
}
