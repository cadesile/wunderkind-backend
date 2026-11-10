<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Service\Competition\HallOfFameService;
use App\Tests\Support\EntrantFixtureTrait;
use App\Tests\Support\PlayedCupFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The two questions the Hall of Fame answers, and the difference between them.
 *
 * `currentHolders()` is one row per active TEMPLATE. `history()` is one row per completed
 * INSTANCE. Those were the same method once, which is the bug these tests exist for: with one
 * active template and five finals played, the per-template answer is one row and no parameter
 * can widen it, so an app asking for historical winners saw a single champion forever.
 *
 * The competition tables are truncated in setUp(), the way CupResultsBoardServiceTest and the
 * other classes here do it: history() reads "every completed cup" globally with no scope to
 * narrow it, so leftovers from another class would land on page 1.
 */
class HallOfFameServiceTest extends KernelTestCase
{
    use EntrantFixtureTrait;
    use PlayedCupFixtureTrait;

    private EntityManagerInterface $em;
    private HallOfFameService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(HallOfFameService::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_result, competition_result_audit, competition_fixture,
                      competition_round, competition_entrant_squad, competition_entrant,
                      active_competition, competition_template CASCADE',
        );
    }

    /**
     * The regression. Three runnings of ONE template, three different champions: the history
     * is three rows while the holders list is correctly still one.
     *
     * Asserting both in the same test is the point — a fix that simply widened
     * `currentHolders()` would pass a history assertion and break every client keying the
     * holders list on templateId.
     */
    public function testHistoryIsOneRowPerCompletedInstanceOfTheSameTemplate(): void
    {
        $first  = $this->playedCup($this->em, 'First Winners FC', new \DateTimeImmutable('2026-03-01 18:00:00'));
        $second = $this->playedCup($this->em, 'Second Winners FC', new \DateTimeImmutable('2026-04-01 18:00:00'), templateId: $first->templateId);
        $third  = $this->playedCup($this->em, 'Third Winners FC', new \DateTimeImmutable('2026-05-01 18:00:00'), templateId: $first->templateId);

        $history = $this->service->history()['rows'];

        self::assertCount(3, $history, 'three cups were won, so three rows');
        self::assertSame(
            ['Third Winners FC', 'Second Winners FC', 'First Winners FC'],
            array_column(array_column($history, 'champion'), 'clubName'),
            'newest first, and three DISTINCT champions — not the latest one repeated',
        );
        self::assertSame(
            [$third->instanceId, $second->instanceId, $first->instanceId],
            array_column($history, 'instanceId'),
        );

        $holders = $this->service->currentHolders();
        self::assertCount(1, $holders, 'one active template still contributes exactly one holder');
        self::assertSame($third->instanceId, $holders[0]['instanceId'], 'and it is the most recent cup');
    }

    /** Cups from different templates compete for the same page, ordered only by date. */
    public function testHistoryInterleavesTemplatesByCompletionDate(): void
    {
        $cupA     = $this->playedCup($this->em, 'A Winners FC', new \DateTimeImmutable('2026-01-01 12:00:00'));
        $cupB     = $this->playedCup($this->em, 'B Winners FC', new \DateTimeImmutable('2026-02-01 12:00:00'));
        $cupAgain = $this->playedCup($this->em, 'A Winners Again FC', new \DateTimeImmutable('2026-03-01 12:00:00'), templateId: $cupA->templateId);

        self::assertSame(
            [$cupAgain->instanceId, $cupB->instanceId, $cupA->instanceId],
            array_column($this->service->history()['rows'], 'instanceId'),
            'ordered by completedAt alone — never grouped by template',
        );
    }

    /**
     * Two cups completed in the same tick share completedAt AND createdAt. Under offset
     * pagination an unstable sort does not merely reorder them: page 1 and page 2 can return
     * the SAME row and silently drop the other, so one of the two cups becomes unreachable.
     *
     * This is the only test that fails if findCompletedHistory()'s `a.id` tiebreak is dropped,
     * and the timestamps are forced equal by hand because ActiveCompetition sets createdAt in
     * its constructor and exposes no setter.
     */
    public function testHistoryPagesDoNotOverlapWhenTwoCupsShareTheirTimestamps(): void
    {
        $sameMoment = new \DateTimeImmutable('2026-06-01 09:00:00');
        $one        = $this->playedCup($this->em, 'Tied One FC', $sameMoment);
        $two        = $this->playedCup($this->em, 'Tied Two FC', $sameMoment);

        $this->em->getConnection()->executeStatement(
            'UPDATE active_competition SET created_at = :t WHERE id IN (:ids)',
            ['t' => $sameMoment->format('Y-m-d H:i:s'), 'ids' => [$one->instanceId, $two->instanceId]],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $pageOne = $this->service->history(0, 1);
        $pageTwo = $this->service->history(1, 1);

        self::assertCount(1, $pageOne['rows']);
        self::assertCount(1, $pageTwo['rows']);
        self::assertNotSame(
            $pageOne['rows'][0]['instanceId'],
            $pageTwo['rows'][0]['instanceId'],
            'the second page must advance past the first, not repeat it',
        );
        $seen = [...array_column($pageOne['rows'], 'instanceId'), ...array_column($pageTwo['rows'], 'instanceId')];
        sort($seen);
        $expected = [$one->instanceId, $two->instanceId];
        sort($expected);
        self::assertSame($expected, $seen, 'both cups are reachable across the two pages');
    }

    /**
     * The invariant that makes the two keys safe to serve side by side: a template's holder is
     * always the newest row its own cups contribute to the history. If these ever disagree,
     * one of the two queries has drifted.
     */
    public function testTheHolderOfACupIsTheNewestRowOfItsOwnHistory(): void
    {
        $cup = $this->playedCup($this->em, 'Holder FC', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $this->playedCup($this->em, 'Newer Holder FC', new \DateTimeImmutable('2026-08-01 10:00:00'), templateId: $cup->templateId);

        $holders = $this->service->currentHolders();
        $ownHistory = array_values(array_filter(
            $this->service->history()['rows'],
            fn (array $row) => $row['templateId'] === $cup->templateId,
        ));

        self::assertSame($ownHistory[0]['instanceId'], $holders[0]['instanceId']);
        self::assertSame($ownHistory[0]['champion']['clubName'], $holders[0]['champion']['clubName']);
    }

    /** The limit is clamped in the SERVICE, because the endpoint reading it is public. */
    public function testTheHistoryLimitIsClampedWhateverIsAskedFor(): void
    {
        $this->playedCup($this->em, 'Only Winners FC', new \DateTimeImmutable('2026-09-01 10:00:00'));

        self::assertCount(1, $this->service->history(0, 999)['rows'], 'an absurd limit is capped, not refused');
        self::assertCount(1, $this->service->history(0, 0)['rows'], 'and a zero or negative limit still returns a page');
        self::assertCount(1, $this->service->history(-5, 20)['rows'], 'a negative offset reads from the start');
    }

    /** The hub takes a fixed slice, and it is the first page of the same ordering. */
    public function testTheHubHistoryIsTheFirstPageCappedAtItsOwnLimit(): void
    {
        $template = null;
        for ($i = 1; $i <= HallOfFameService::HUB_HISTORY_LIMIT + 2; $i++) {
            $cup = $this->playedCup(
                $this->em,
                sprintf('Winners %02d FC', $i),
                new \DateTimeImmutable(sprintf('2026-10-%02d 10:00:00', $i)),
                templateId: $template,
            );
            $template ??= $cup->templateId;
        }

        $hub = $this->service->hubHistory();
        self::assertCount(HallOfFameService::HUB_HISTORY_LIMIT, $hub);
        self::assertSame(
            array_column(array_slice($this->service->history(0, HallOfFameService::MAX_HISTORY_LIMIT)['rows'], 0, HallOfFameService::HUB_HISTORY_LIMIT), 'instanceId'),
            array_column($hub, 'instanceId'),
        );
    }
}
