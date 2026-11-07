<?php

declare(strict_types=1);

namespace App\Service\Location\Suggestion;

use App\Entity\Admin;
use App\Entity\Location\LocationEnrichmentRun;
use App\Entity\Location\TownSuggestionBatch;
use App\Enum\Country;
use App\Enum\Location\EnrichmentTrigger;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\Location\TownSuggestionBatchRepository;
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
 * TWO WAYS IN, DELIBERATELY.
 *
 *   - suggestForCountry() is the SINGLE-BATCH PRIMITIVE: one API call, start to finish, in the
 *     caller's process. app:locations:suggest-towns still uses it, and still supports --dry-run,
 *     which has no meaning on a queue.
 *   - planRun() + runBatch() are the QUEUED path the admin screen uses. planRun() writes a run
 *     and its batch rows and returns; a Messenger message per batch does the work.
 *
 * THE ADMIN PATH USED TO BE SYNCHRONOUS AND TIMED OUT. One call for 300 towns is a ~12,000
 * output-token generation taking 60-180s, and nginx cuts the request off at 60s while PHP keeps
 * waiting — so the operator saw a failure for work that was often still running, and sometimes
 * landed afterwards with nothing to say so.
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

    /**
     * Towns asked for per queued batch.
     *
     * Fifty sits exactly on ClaudeTownSuggester's MIN_TOKENS floor — tokenBudget(50) is
     * max(2048, 50 * 40) = 2048 — so anything smaller buys no cheaper call while needing more
     * of them. Each batch is therefore one short generation rather than a slice of a long one,
     * which is what keeps a failure to one batch and makes the progress bar move.
     */
    public const BATCH_SIZE = 50;

    /**
     * How long one batch may wait on the provider.
     *
     * Far below ClaudeTownSuggester's 120s default, which was sized for a 12,000-token reply.
     * A 2,048-token batch that has not answered in a minute is stalled, and a consumer with a
     * fixed time budget must not spend half of it finding that out.
     */
    public const BATCH_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly TownSuggesterInterface $suggester,
        private readonly TownSuggestionValidator $validator,
        private readonly LocationSeedService $seedService,
        private readonly CountryProfileRepository $countryProfileRepository,
        private readonly TownSuggestionBatchRepository $batchRepository,
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
     * Towns already held, biggest first, then newest first.
     *
     * Biggest first because the prompt list is truncated: the names the model is most likely to
     * suggest anyway are the ones worth spending the budget on.
     *
     * THE SECOND KEY IS LOAD-BEARING AND USED TO BE `town ASC`, WHICH WAS BACKWARDS. Towns added
     * by a suggestion run are stored with no population at all — deliberately, since writing a
     * zero would be worse than writing nothing — so every one of them lands in the NULL group,
     * which is the group truncation eats first. Ordering that group alphabetically meant the
     * names most at risk of being offered again were the ones the prompt was most likely to
     * hide. `created_at DESC` puts the newest imports at the front of the group, where
     * truncation does not reach.
     *
     * For one big call that was mild waste. For a batched run it was the difference between a
     * full yield and batch four onward paying for names batch one already took.
     *
     * @return list<string>
     */
    private function existingTownNames(string $code): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT town FROM location
              WHERE country_code = :code
              ORDER BY population DESC NULLS LAST, created_at DESC',
            ['code' => $code],
        );

        return array_map(static fn (mixed $t): string => (string) $t, $rows);
    }

    /**
     * How a run of `$max` towns divides into batches.
     *
     * Static and pure so the division is testable without a provider, a database or a queue.
     *
     * @return list<int> towns asked for in each batch, in order
     */
    public static function planBatchSizes(int $max): array
    {
        $max = max(1, min($max, self::HARD_MAX));

        $sizes = [];

        for ($remaining = $max; $remaining > 0; $remaining -= self::BATCH_SIZE) {
            $sizes[] = min(self::BATCH_SIZE, $remaining);
        }

        return $sizes;
    }

    /**
     * Write the run and its batch rows, ready for one message each.
     *
     * `queued` on the run is the BATCH COUNT, not a town count — see the counter note on
     * EnrichmentTrigger::ADMIN_TOWN_SUGGESTION. It has to be: it is set here, before anything
     * has been asked, and how many towns a run will accept is unknowable until it has run.
     * Setting it to the batch count is also what makes the run close itself, because
     * LocationEnrichmentRunRepository::recordBatchOutcome() completes the run on the tick where
     * settled batches reach `queued`.
     *
     * Flushes before returning so the caller can dispatch immediately: a consumer that picks a
     * message up in the same second must find the rows already committed.
     *
     * @return array{run: LocationEnrichmentRun, batches: list<TownSuggestionBatch>}
     */
    public function planRun(Country $country, int $max = self::DEFAULT_MAX, ?Admin $requestedBy = null): array
    {
        $code    = $country->value;
        $profile = $this->countryProfileRepository->findByCode($code);

        if ($profile === null) {
            throw new \InvalidArgumentException(\sprintf(
                "'%s' has no country profile — run app:locations:seed %s first.",
                $code,
                $code,
            ));
        }

        $sizes = self::planBatchSizes($max);

        $run = new LocationEnrichmentRun(
            EnrichmentTrigger::ADMIN_TOWN_BATCH,
            $this->suggester->provider(),
            $this->suggester->model(),
            $code,
            $requestedBy,
        );
        $run->setQueued(\count($sizes));

        $this->em->persist($run);

        $batches = [];

        foreach ($sizes as $i => $size) {
            $batch = new TownSuggestionBatch($run, $code, $i + 1, \count($sizes), $size);
            $this->em->persist($batch);
            $batches[] = $batch;
        }

        $this->em->flush();

        return ['run' => $run, 'batches' => $batches];
    }

    /**
     * Do one batch's work: ask, validate, import.
     *
     * TAKES AND RETURNS SCALARS, NOT ENTITIES, on purpose. importNames() ends in em->clear()
     * when it imported anything, so any entity this held would be detached on the way out —
     * intermittently, since a batch that imports nothing leaves the manager alone. The handler
     * therefore records the outcome through raw statements and nothing is carried across.
     *
     * @return array{
     *     offered: int, accepted: int, imported: int, alreadyHeld: int,
     *     offeredNames: list<string>, duplicates: int,
     *     nearDuplicates: list<array{imported: string, existing: string}>,
     *     inputTokens: ?int, outputTokens: ?int
     * }
     */
    public function runBatch(string $runId, string $batchId, Country $country, int $requested): array
    {
        $code    = $country->value;
        $profile = $this->countryProfileRepository->findByCode($code);

        if ($profile === null) {
            throw new \InvalidArgumentException(\sprintf("'%s' has no country profile.", $code));
        }

        $result  = $this->suggester->suggest(
            $profile,
            $this->exclusionsForBatch($runId, $batchId, $code),
            $requested,
            self::BATCH_TIMEOUT_SECONDS,
        );
        $verdict = $this->validator->validate($result->towns, $requested);

        /** @var list<SuggestedTown> $accepted */
        $accepted = $verdict['accepted'];
        $names    = array_map(static fn (SuggestedTown $t): string => $t->name, $accepted);

        // Every name the model said, accepted or not — the exclusion input for the run's other
        // batches. Rejected and already-held names are kept deliberately: a name it offered
        // once it will offer again, and saying so is cheaper than paying for the repeat.
        $offeredNames = array_values(array_filter(array_map(
            static fn (SuggestedTown $t): string => $t->name,
            $result->towns,
        ), static fn (string $n): bool => $n !== ''));

        // Stored NOW, before the import, so they survive a batch that answers and then fails
        // writing rows. Raw statement, so em->clear() inside importNames() cannot strand it.
        $this->batchRepository->recordOffered($batchId, \count($result->towns), $offeredNames);

        $import = $this->seedService->importNames($country, $names, false);

        $summary = [
            'offered'        => \count($result->towns),
            'accepted'       => \count($accepted),
            'imported'       => $import['imported'],
            'alreadyHeld'    => $import['skipped'],
            'offeredNames'   => $offeredNames,
            'duplicates'     => $verdict['duplicates'] + $import['duplicates'],
            'nearDuplicates' => $import['nearDuplicates'],
            'inputTokens'    => $result->inputTokens,
            'outputTokens'   => $result->outputTokens,
        ];

        $this->logger->info('Town suggestion batch', $summary + [
            'runId'    => $runId,
            'batchId'  => $batchId,
            'country'  => $code,
            'provider' => $this->suggester->provider(),
            'model'    => $this->suggester->model(),
        ]);

        return $summary;
    }

    /**
     * What this batch must not be offered again.
     *
     * THE RUN'S OWN NAMES GO FIRST, and that order is the whole point. Truncation takes the tail,
     * so putting what sibling batches already offered at the head is what guarantees it survives
     * — without it, every batch asks the same question and the model, quite reasonably, gives
     * the same answer.
     *
     * @return list<string>
     */
    private function exclusionsForBatch(string $runId, string $batchId, string $code): array
    {
        $fromRun = $this->batchRepository->offeredNamesForOtherBatches($runId, $batchId);
        $seen    = [];
        $merged  = [];

        foreach ([$fromRun, $this->existingTownNames($code)] as $list) {
            foreach ($list as $name) {
                $key = mb_strtolower($name);

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $merged[]   = $name;
                }
            }
        }

        return $merged;
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
