<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionResult;
use App\Entity\Competition\CompetitionRound;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\Competition\CompetitionFixtureStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Enum\Competition\MatchEngineIdentifier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds a COMPLETED cup with a settled final — the fixture every "who won" read path needs,
 * and the cheapest honest way to get one.
 *
 * The alternative is CompetitionControllerTest::drawAndResolveRound(), which runs the real
 * draw and result engines. That is the right tool for proving the engine works and the wrong
 * one for proving a *list* of finished cups is assembled correctly: it costs four registered
 * clubs and two cron passes per instance, and a test needing seven cups would pay for
 * fifty-six registrations to assert on seven rows.
 *
 * What it does build faithfully is the part these reads actually touch: `winnerEntrant` on
 * the final fixture, and `CompetitionResult`'s two FROZEN club blocks, copied off each
 * entrant's display block the way CompetitionResultsService::clubJsonOf() copies them. That
 * is what lets a champion survive its club being deleted, so a fixture that skipped it would
 * make the durability untestable.
 *
 * Lives here rather than in one test class because three classes now need it:
 * CupResultsBoardServiceTest (the landing page), HallOfFameServiceTest and the two API
 * controller tests. It began as private helpers on the first of those.
 *
 * Requires EntrantFixtureTrait, which is where the entrant itself comes from.
 */
trait PlayedCupFixtureTrait
{
    /**
     * A two-round cup — SF then FINAL — won from the AWAY side in both, with the final
     * settled on penalties.
     *
     * Away is the side worth defaulting to: `CompetitionResult` stores everything by
     * home/away — scores, penalty scores, both frozen club blocks, both lineups — while every
     * reader presents it by winner/runner-up. One isHome() check decides six separate swaps,
     * and with the winner at home every one of them is right by accident.
     *
     * `$templateId` reuses an existing template instead of minting one, which is the whole
     * point for a Hall of Fame test: the bug being guarded against is many instances of ONE
     * template collapsing to one row, and a helper that always made a fresh template could
     * not express it. It is an id rather than an entity because this method clears the entity
     * manager before returning, so an entity handed in by a previous call would be detached.
     *
     * Passing `completedAt: null` builds the one row the dated reads must reject: COMPLETED
     * with no completion date, which Postgres sorts FIRST under `completed_at DESC`.
     *
     * `$settleFinal = false` stops at a final with no winner — a genuinely completed instance
     * whose result never landed, which the history keeps with a null champion.
     *
     * Only the TEMPLATE name carries a unique suffix. The three club names are fixed, so a
     * test can assert on them directly; pass `$winnerName` when a test needs to tell several
     * cups' champions apart, which a Hall of Fame test does and a single-cup test does not.
     */
    private function playedCup(
        EntityManagerInterface $em,
        ?string $winnerName = null,
        ?\DateTimeImmutable $completedAt = new \DateTimeImmutable('2026-05-01 18:00:00'),
        ?string $templateId = null,
        bool $settleFinal = true,
    ): PlayedCup {
        $suffix     = uniqid('', true);
        $winnerName ??= 'Away Winners FC';

        $template = $templateId !== null
            ? $em->getRepository(CompetitionTemplate::class)->find($templateId)
            : null;

        if ($template === null) {
            $template = new CompetitionTemplate(
                'Cup Results Trophy ' . $suffix,
                'cup-results-' . $suffix,
                4,
                CompetitionDuration::TEN_HOURS,
            );
            $template->setVictorPrize(500000);
            $template->setTrophy([
                'style'           => 'cup',
                'finish'          => 'gold',
                'ribbons'         => true,
                'ribbonPrimary'   => '#112233',
                'ribbonSecondary' => '#445566',
            ]);
            $em->persist($template);
        }

        $competition = new ActiveCompetition($template);
        $competition->setStatus(ActiveCompetitionStatus::COMPLETED);
        $competition->setCompletedAt($completedAt);
        $em->persist($competition);

        // The winner's own identity is the one every reader must freeze and draw: a
        // distinctive home kit (merged into the player sprite) and a country that keys a real
        // flag file.
        $winner = $this->playedCupEntrant($em, $competition, $winnerName, 1, [
            'country'       => 'ES',
            'formation'     => '4-4-2',
            // Conditional keys of the display block (CompetitionRegistrationService only
            // writes them when the club supplied one), so the fixture has to supply them for
            // the champion block to look like a real one.
            'playingStyle'  => 'POSSESSION',
            'stadiumName'   => 'The Away End',
            'homeKitConfig' => ['kit' => 'stripes', 'primary' => '#112233', 'secondary' => '#445566', 'shorts' => 'black', 'socks' => 'primary'],
        ]);
        $runnerUp = $this->playedCupEntrant($em, $competition, 'Home Losers FC', 2);
        $victim   = $this->playedCupEntrant($em, $competition, 'Semi Victims FC', 3);

        // Capacity 4 labels its rounds ['SF', 'FINAL'] (BracketLabeler), and every reader
        // finds the final by round index log2(capacity) — so the FINAL must be index 2.
        $sf = $this->playedCupRound($em, $competition, 1, 'SF');
        $this->playedCupFixture($em, $sf, home: $victim, away: $winner, homeScore: 0, awayScore: 3, winner: $winner);

        $final       = $this->playedCupRound($em, $competition, 2, 'FINAL');
        $finalResult = $this->playedCupFixture(
            $em,
            $final,
            home: $runnerUp,
            away: $winner,
            homeScore: 1,
            awayScore: 1,
            winner: $settleFinal ? $winner : null,
            awayLineup: [
                ['id' => 'a1', 'name' => 'Away Also Played', 'position' => 'DEF', 'goals' => 0, 'rating' => 6.1, 'appearance' => ['hair' => 'crop', 'skin' => 's2']],
                ['id' => 'a2', 'name' => 'Away Match Winner', 'position' => 'FWD', 'goals' => 2, 'rating' => 8.4, 'appearance' => ['hair' => 'bald', 'skin' => 's3']],
            ],
            homeLineup: [
                ['id' => 'h1', 'name' => 'Home Star', 'position' => 'FWD', 'goals' => 1, 'rating' => 9.9, 'appearance' => ['hair' => 'afro', 'skin' => 's1']],
            ],
        );

        // Home 3, away 5 — the away side wins the shootout, so a reader has to swap them.
        $finalResult->setShootoutInfo(true, true, 3, 5);

        $em->flush();

        $cup = new PlayedCup(
            templateId: (string) $template->getId(),
            templateName: $template->getName(),
            instanceId: (string) $competition->getId(),
            winnerClubName: $winnerName,
            runnerUpClubName: 'Home Losers FC',
            completedAt: $completedAt,
        );

        // Cleared so the reader under test loads from the database rather than from an
        // identity map this method happens to have warmed.
        $em->clear();

        return $cup;
    }

    /** @param array<string, mixed> $clubExtras */
    private function playedCupEntrant(
        EntityManagerInterface $em,
        ActiveCompetition $competition,
        string $name,
        int $seed,
        array $clubExtras = [],
    ): CompetitionEntrant {
        $user = new User(sprintf('played-cup-%s@example.com', uniqid('', true)));
        $user->setPassword('x');
        $club = new Club($name, $user);
        $em->persist($user);
        $em->persist($club);

        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = ['id' => "$name-p$i", 'name' => "$name Player $i", 'position' => 'MID', 'currentAbility' => 60];
        }

        return $this->makeEntrantFromEnvelope($em, $competition, $club, $seed, [
            'club'    => array_merge(['id' => (string) $club->getId(), 'name' => $name], $clubExtras),
            'players' => $players,
            'staff'   => [['id' => 's1', 'role' => 'MANAGER', 'name' => "$name Boss"]],
        ]);
    }

    private function playedCupRound(
        EntityManagerInterface $em,
        ActiveCompetition $competition,
        int $index,
        string $label,
    ): CompetitionRound {
        $round = new CompetitionRound($competition, $index, $label, new \DateTimeImmutable('-2 hours'));
        $round->setStatus(CompetitionRoundStatus::RESULTS_PUBLISHED);
        $em->persist($round);

        return $round;
    }

    /**
     * @param list<array<string, mixed>> $homeLineup
     * @param list<array<string, mixed>> $awayLineup
     */
    private function playedCupFixture(
        EntityManagerInterface $em,
        CompetitionRound $round,
        CompetitionEntrant $home,
        CompetitionEntrant $away,
        int $homeScore,
        int $awayScore,
        ?CompetitionEntrant $winner,
        array $homeLineup = [],
        array $awayLineup = [],
    ): CompetitionResult {
        $fixture = new CompetitionFixture($round, 0, $home, $away);
        $fixture->setStatus(CompetitionFixtureStatus::COMPLETE);
        if ($winner !== null) {
            $fixture->setWinnerEntrant($winner);
        }
        $em->persist($fixture);

        // Frozen from each entrant's own display block, exactly as
        // CompetitionResultsService::clubJsonOf() does it.
        $result = new CompetitionResult(
            $fixture,
            $homeScore,
            $awayScore,
            MatchEngineIdentifier::DETERMINISTIC,
            $home->getClubDisplayJson(),
            $away->getClubDisplayJson(),
            $homeLineup,
            $awayLineup,
        );
        $em->persist($result);

        return $result;
    }
}
