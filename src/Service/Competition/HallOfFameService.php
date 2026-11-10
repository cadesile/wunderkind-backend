<?php

declare(strict_types=1);

namespace App\Service\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionFixture;
use App\Repository\Competition\ActiveCompetitionRepository;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionTemplateRepository;

/**
 * The Hall of Fame: who holds each cup now, and who has ever won one.
 *
 * Owns both keys of GET /api/competitions/champions, and the same two on GET /api/club/hub.
 * It exists because those two controllers each had their own copy of the champions loop, and
 * the copies had already drifted — CompetitionController read the trophy through
 * normalizeTrophy($template->getTrophy()) while ClubHubController read it off a
 * serializeInstanceSummary() result. Identical values by coincidence, via two code paths.
 *
 * The distinction the two keys draw, and the bug that forced it:
 *
 *   currentHolders() is one row per ACTIVE TEMPLATE — the club holding that cup right now.
 *   history()        is one row per COMPLETED INSTANCE — every cup ever won.
 *
 * `champions[]` was serving as both, and could not be: its length is the number of active
 * templates, not the number of cups played. With one active template and five finals settled,
 * a client asking for historical winners got exactly one row, permanently, and no parameter
 * could widen it. The landing page's Cup Results board never had the problem because it asks
 * the question the other way round — most recent finals, whatever template they belong to —
 * which is what history() does here.
 *
 * Both readers end in the same private row(), which is the point: a change to how a champion
 * is presented cannot land on one key and miss the other.
 *
 * Query budget: currentHolders() is 1 (templates) + 1 per template + 1 batched finals.
 * history() is 2 regardless of page size — the page, then one batched finals lookup. Reusing
 * CompetitionResponseBuilder::serializeInstanceSummary() per row instead would have been
 * roughly six queries per row, for the two keys a row actually keeps.
 */
final class HallOfFameService
{
    /**
     * The hub is an aggregate read with no cursor of its own, so its history is a fixed
     * slice. Five matches what the landing page's board shows (CupResultsBoardService's
     * latest + EARLIER_LIMIT), so the two surfaces tell the same story at a glance.
     */
    public const HUB_HISTORY_LIMIT = 5;

    public const DEFAULT_HISTORY_LIMIT = 20;

    /** The ceiling CompetitionController::list() already uses. */
    public const MAX_HISTORY_LIMIT = 50;

    public function __construct(
        private readonly CompetitionTemplateRepository $templateRepository,
        private readonly ActiveCompetitionRepository $competitionRepository,
        private readonly CompetitionFixtureRepository $fixtureRepository,
        private readonly CompetitionResponseBuilder $responseBuilder,
    ) {}

    /**
     * Current holder per active template, in template-name order — the long-standing
     * `champions[]`, unchanged in shape and in meaning.
     *
     * A template with nothing completed yet contributes no row, as before.
     *
     * @return list<array<string, mixed>>
     */
    public function currentHolders(): array
    {
        $instances = [];
        foreach ($this->templateRepository->findAllActive() as $template) {
            $latest = $this->competitionRepository->findLatestCompletedForTemplate($template);
            if ($latest !== null) {
                $instances[] = $latest;
            }
        }

        return $this->rows($instances);
    }

    /**
     * One row per completed cup, newest first — the answer to "who has won what".
     *
     * Offset pagination, the same cursor idiom as GET /api/competitions: `nextCursor` is the
     * next offset as a string, and null on the last page. The limit is clamped HERE rather
     * than in the controller because this endpoint is PUBLIC_ACCESS, so the ceiling has to
     * hold whoever calls it and however they got the number in.
     *
     * Rows can carry `champion: null` — a genuinely completed instance whose final was never
     * settled. They are kept rather than filtered: dropping them after LIMIT would return
     * fewer rows than asked for while the cursor still advanced by the full page, which is a
     * client-visible hole. A client that wants winners only should skip null-champion rows.
     *
     * @return array{rows: list<array<string, mixed>>, nextCursor: ?string}
     */
    public function history(int $offset = 0, int $limit = self::DEFAULT_HISTORY_LIMIT): array
    {
        $offset = max(0, $offset);
        $limit  = max(1, min(self::MAX_HISTORY_LIMIT, $limit));

        // One extra row answers "is there another page?" without a second COUNT query.
        $page    = $this->competitionRepository->findCompletedHistory($offset, $limit + 1);
        $hasMore = count($page) > $limit;

        return [
            'rows'       => $this->rows(array_slice($page, 0, $limit)),
            'nextCursor' => $hasMore ? (string) ($offset + $limit) : null,
        ];
    }

    /**
     * The hub's fixed first page. No cursor: the hub is one aggregate response, and a client
     * wanting to page the history calls GET /api/competitions/champions for it.
     *
     * @return list<array<string, mixed>>
     */
    public function hubHistory(): array
    {
        return $this->history(0, self::HUB_HISTORY_LIMIT)['rows'];
    }

    /**
     * Both public readers funnel through here, so the finals are fetched once for the whole
     * set rather than once per row.
     *
     * @param  list<ActiveCompetition> $instances
     * @return list<array<string, mixed>>
     */
    private function rows(array $instances): array
    {
        if ($instances === []) {
            return [];
        }

        $finals = $this->fixtureRepository->findFinalFixturesForCompetitions($instances);

        return array_map(
            fn (ActiveCompetition $instance) => $this->row(
                $instance,
                $finals[$instance->getId()->toRfc4122()] ?? null,
            ),
            $instances,
        );
    }

    /**
     * The row shape, defined in exactly one place.
     *
     * `templateIsActive` is the only key `history[]` carries that `champions[]` historically
     * did not — and it is emitted on both, so one client-side row component renders either
     * list. A retired template is the single thing a reader of the history needs to tell
     * apart: its champions stay on the record (findCompletedHistory() ignores isActive by
     * design), but no one can enter that cup again.
     *
     * @return array<string, mixed>
     */
    private function row(ActiveCompetition $instance, ?CompetitionFixture $final): array
    {
        $template = $instance->getTemplate();
        $pair     = $this->responseBuilder->championPair($final);

        return [
            'templateId'       => (string) $template->getId(),
            'templateName'     => $template->getName(),
            'templateIsActive' => $template->isActive(),
            'entrantCapacity'  => $instance->getEntrantCapacity(),
            'trophy'           => $this->responseBuilder->normalizeTrophy($template->getTrophy()),
            'instanceId'       => (string) $instance->getId(),
            'completedAt'      => $instance->getCompletedAt()?->format(DATE_ATOM),
            'champion'         => $pair['champion'],
            'runnerUp'         => $pair['runnerUp'],
        ];
    }
}
