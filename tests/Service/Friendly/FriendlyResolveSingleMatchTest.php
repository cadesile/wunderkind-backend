<?php

declare(strict_types=1);

namespace App\Tests\Service\Friendly;

use App\Entity\Club;
use App\Entity\Friendly\FriendlyChallenge;
use App\Entity\InboxMessage;
use App\Message\SendPushNotificationMessage;
use Symfony\Component\Messenger\Envelope;
use App\Entity\User;
use App\Enum\Friendly\FriendlyChallengeStatus;
use App\Service\Competition\CompetitionRegistrationService;
use App\Service\Competition\MatchEngineSideFactory;
use App\Service\Friendly\FriendlyChallengeService;
use App\Service\MatchEngine\DeterministicEngine;
use App\Service\MatchEngine\MatchEngineContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use App\Enum\Friendly\FriendlySide;
use App\Repository\Friendly\FriendlyChallengeSquadRepository;
use App\Tests\Support\FriendlyFixtureTrait;

/**
 * Covers FriendlyChallengeService::resolveSingleMatch(), which had no test at all.
 *
 * That gap was not harmless. Removing DeterministicEngine::resolveFriendly() left this
 * method calling a method that no longer existed, and the full suite still passed green —
 * nothing executed the one code path in the friendly domain that resolves a match. These
 * tests exist so that cannot happen again.
 *
 * The seeding here writes a challenge directly rather than going through postChallenge() /
 * acceptChallenge(), because the subject is resolution: the stake escrow and acceptance
 * transactions have their own coverage via the API controller tests.
 */
class FriendlyResolveSingleMatchTest extends KernelTestCase
{
    use FriendlyFixtureTrait;

    private EntityManagerInterface $em;
    private FriendlyChallengeService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(FriendlyChallengeService::class);
    }

    private function club(string $name): Club
    {
        $user = new User(sprintf('friendly-resolve-%s@example.com', uniqid('', true)));
        $user->setPassword('x');
        $club = new Club($name, $user);

        $this->em->persist($user);
        $this->em->persist($club);

        return $club;
    }

    /** @return array<string, mixed> */
    private function snapshot(string $clubName, int $ability): array
    {
        $players   = [];
        $positions = ['GK', 'DEF', 'DEF', 'DEF', 'DEF', 'MID', 'MID', 'MID', 'ATT', 'ATT', 'ATT'];
        foreach ($positions as $i => $position) {
            $players[] = [
                'id'             => sprintf('%s-p%d', $clubName, $i),
                'name'           => sprintf('%s Player %d', $clubName, $i),
                'position'       => $position,
                'currentAbility' => $ability,
            ];
        }

        return [
            'club'    => ['name' => $clubName, 'formation' => '4-4-2', 'cohesion' => 70, 'managerMotivation' => 65],
            'players' => $players,
            'staff'   => [['role' => 'MANAGER', 'name' => $clubName . ' Boss', 'ability' => 70]],
        ];
    }

    private function seedAcceptedChallenge(int $challengerAbility = 70, int $acceptorAbility = 40): FriendlyChallenge
    {
        $challenger = $this->club('Challenger ' . uniqid('', true));
        $acceptor   = $this->club('Acceptor ' . uniqid('', true));

        $challenge = $this->makeOpenChallenge($this->em, $challenger, $this->snapshot('Challenger FC', $challengerAbility));
        $this->acceptChallengeFixture($this->em, $challenge, $acceptor, $this->snapshot('Acceptor FC', $acceptorAbility));
        $challenge->setStatus(FriendlyChallengeStatus::ACCEPTED);

        $this->em->flush();

        return $challenge;
    }

    public function testResolvingAnAcceptedChallengeRecordsAFullResult(): void
    {
        $challenge = $this->seedAcceptedChallenge();

        $this->service->resolveSingleMatch($challenge);
        $this->em->flush();

        self::assertSame(FriendlyChallengeStatus::COMPLETE, $challenge->getStatus());
        self::assertNotNull($challenge->getHomeScore());
        self::assertNotNull($challenge->getAwayScore());
        self::assertCount(11, $challenge->getHomeLineupJson() ?? []);
        self::assertCount(11, $challenge->getAwayLineupJson() ?? []);
        self::assertNotEmpty($challenge->getNarrativePayload() ?? []);
    }

    /**
     * The scoreline must be seeded from the challenge id.
     *
     * That used to be arranged by fabricating a CompetitionFixture and overwriting its
     * private id through reflection; the id now travels on MatchEngineContext. Asserting it
     * by resolving twice is not possible — a resolved challenge is COMPLETE, and forcing a
     * duplicate id onto a second row collides on the primary key the moment the service
     * flushes. So this drives the engine directly with the same sides and the same context
     * the service builds, and checks the service's recorded score agrees.
     */
    public function testTheScorelineIsSeededFromTheChallengeId(): void
    {
        $challenge = $this->seedAcceptedChallenge();
        $squads    = self::getContainer()->get(FriendlyChallengeSquadRepository::class)->findBothSides($challenge);

        $homeDisplay = $challenge->getChallengerClubDisplayJson();
        $awayDisplay = $challenge->getAcceptorClubDisplayJson() ?? [];
        $homeSquad   = $squads[FriendlySide::CHALLENGER->value];
        $awaySquad   = $squads[FriendlySide::ACCEPTOR->value];

        $this->service->resolveSingleMatch($challenge);
        $this->em->flush();

        $expected = self::getContainer()->get(DeterministicEngine::class)->resolve(
            MatchEngineSideFactory::fromFriendlySide(
                sideId: (string) $challenge->getId() . ':home',
                clubId: (string) $challenge->getChallengerClub()->getId(),
                clubName: $challenge->getChallengerClub()->getName(),
                clubDisplay: $homeDisplay,
                players: $homeSquad->getPlayersJson(),
                staff: $homeSquad->getStaffJson(),
                engineInputs: $homeSquad->getClubEngineJson(),
                seed: 1,
            ),
            MatchEngineSideFactory::fromFriendlySide(
                sideId: (string) $challenge->getId() . ':away',
                clubId: (string) $challenge->getAcceptorClub()->getId(),
                clubName: $challenge->getAcceptorClub()->getName(),
                clubDisplay: $awayDisplay,
                players: $awaySquad->getPlayersJson(),
                staff: $awaySquad->getStaffJson(),
                engineInputs: $awaySquad->getClubEngineJson(),
                seed: 2,
            ),
            MatchEngineContext::forFriendly((string) $challenge->getId()),
        );

        self::assertSame(
            [$expected->homeScore, $expected->awayScore],
            [$challenge->getHomeScore(), $challenge->getAwayScore()],
        );
    }

    /**
     * A friendly may end level — that is the one behavioural difference between a friendly
     * and a cup tie, and it is now expressed as `allowDraws` on the engine context rather
     * than a separate resolveFriendly() method. Two evenly matched sides over many
     * resolutions must produce at least one draw; a cup tie never can.
     */
    /**
     * Every surface a resolved friendly writes must be outcome-free.
     *
     * A played challenge friendly of the player's own is sealed until they reveal it, so the
     * three notes previously titled `Friendly Draw:`/`Friendly Victory:`/`Friendly Result:`
     * plus the scoreline, and the push whose body WAS the scoreline, all had to go neutral.
     * The push matters most: it is sent as a display notification, so the OS renders it on a
     * locked screen before any client code runs. Asserted over several resolutions because
     * the engine picks the outcome — whichever branch fires must be indistinguishable.
     */
    public function testAResolvedFriendlyRevealsNothingInItsNotesOrItsPush(): void
    {
        $subjects = [];
        $bodies   = [];

        for ($i = 0; $i < 6; $i++) {
            $challenge = $this->seedAcceptedChallenge(60, 60);
            $this->service->resolveSingleMatch($challenge);
            $this->em->flush();

            foreach ([$challenge->getChallengerClub(), $challenge->getAcceptorClub()] as $club) {
                foreach ($this->em->getRepository(InboxMessage::class)->findBy(['club' => $club]) as $message) {
                    if (($message->getOfferData()['type'] ?? null) !== 'FRIENDLY_RESULT') {
                        continue;
                    }
                    $subjects[] = $message->getSubject();
                    $bodies[]   = $message->getBody();
                }
            }
        }

        self::assertNotEmpty($subjects, 'a resolved friendly must write result notes');

        foreach (array_merge($subjects, $bodies) as $text) {
            self::assertDoesNotMatchRegularExpression('/\d+\s*-\s*\d+/', $text, 'no scoreline may be stored: ' . $text);
            foreach (['Victory', 'Draw', 'won', 'lost', 'Congratulations'] as $spoiler) {
                self::assertStringNotContainsStringIgnoringCase($spoiler, $text, 'outcome word in: ' . $text);
            }
        }

        self::assertCount(1, array_unique($subjects), 'win, loss and draw must share one subject');
        self::assertCount(1, array_unique($bodies), 'win, loss and draw must share one body');

        $pushes = array_values(array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            self::getContainer()->get('messenger.transport.async')->getSent(),
        ), static fn ($m) => $m instanceof SendPushNotificationMessage && ($m->data['type'] ?? null) === 'FRIENDLY_RESULT'));

        self::assertNotEmpty($pushes);
        foreach ($pushes as $push) {
            self::assertDoesNotMatchRegularExpression('/\d+\s*-\s*\d+/', $push->title . ' ' . $push->body);
            self::assertStringContainsString('tap to reveal', $push->body);
        }
    }

    /**
     * A drawn stake comes back on the note, not by writing `club.balance`.
     *
     * The old raw `UPDATE club SET balance = balance + :stake` was simply lost: the client is
     * authoritative for the balance, `SyncService::process()` does an absolute
     * `Club::setBalance($request->balance)`, and the resolution runs on its own schedule, so
     * the next sync overwrote the credit. Riding on `offerData.effects` makes the overwrite
     * correct — the player accepts, the client applies the delta itself and reports it back.
     * This is how the win payout already worked; the draw branch was the inconsistent one.
     */
    public function testADrawnStakeIsReturnedOnTheNoteAndNotWrittenToTheBalance(): void
    {
        $stake    = 50_000;
        $sawDraw  = false;

        for ($i = 0; $i < 40 && !$sawDraw; $i++) {
            $challenger = $this->club('Draw Challenger ' . uniqid('', true));
            $acceptor   = $this->club('Draw Acceptor ' . uniqid('', true));

            $challenge = $this->makeOpenChallenge(
                $this->em,
                $challenger,
                $this->snapshot('Challenger FC', 60),
                stakePence: $stake,
            );
            $this->acceptChallengeFixture($this->em, $challenge, $acceptor, $this->snapshot('Acceptor FC', 60));
            $challenge->setStatus(FriendlyChallengeStatus::ACCEPTED);
            $this->em->flush();

            $balanceBefore = $challenger->getBalance();

            $this->service->resolveSingleMatch($challenge);
            $this->em->flush();

            if ($challenge->getHomeScore() !== $challenge->getAwayScore()) {
                continue;
            }
            $sawDraw = true;

            self::assertSame(
                $balanceBefore,
                $challenger->getBalance(),
                'resolution must not write club.balance — the next sync would overwrite it',
            );

            foreach ([$challenger, $acceptor] as $club) {
                $notes = array_values(array_filter(
                    $this->em->getRepository(InboxMessage::class)->findBy(['club' => $club]),
                    static fn (InboxMessage $m) => ($m->getOfferData()['type'] ?? null) === 'FRIENDLY_RESULT',
                ));
                self::assertCount(1, $notes);

                $effects = $notes[0]->getOfferData()['effects'] ?? [];
                self::assertSame(
                    [['type' => 'ledger_delta', 'amountPence' => $stake]],
                    $effects,
                    'a drawn stake must be claimable on the note',
                );
            }
        }

        self::assertTrue($sawDraw, 'a friendly must be allowed to end level');
    }

    public function testFriendliesCanEndLevel(): void
    {
        $sawDraw = false;

        for ($i = 0; $i < 40 && !$sawDraw; $i++) {
            $challenge = $this->seedAcceptedChallenge(60, 60);
            $this->service->resolveSingleMatch($challenge);
            $this->em->flush();

            if ($challenge->getHomeScore() === $challenge->getAwayScore()) {
                $sawDraw = true;
            }
        }

        self::assertTrue($sawDraw, 'a friendly must be allowed to end level');
    }

    /**
     * The public challenge board must render with no squad rows present at all.
     *
     * This is the invariant the split exists to protect. GET /api/friendlies/challenges is
     * public and lists every open challenge, and it used to decode two whole squads per row
     * to show a club name, kit and badge. Deleting the squads and asserting the board still
     * renders is the only way to prove no serializer quietly reaches for them again — a
     * passing board test with the rows present would prove nothing.
     */
    public function testTheChallengeBoardRendersWithoutAnySquadRows(): void
    {
        $challenge = $this->seedAcceptedChallenge();

        self::getContainer()->get(FriendlyChallengeSquadRepository::class)->deleteForChallenge($challenge);
        $this->em->clear();

        $challenge = $this->em->getRepository(FriendlyChallenge::class)->find($challenge->getId());
        self::assertNotNull($challenge);

        $item = $this->service->serializeChallengeItem($challenge);

        self::assertNotEmpty($item['challenger']['clubName']);
        self::assertNotEmpty($item['acceptor']['clubName'], 'an accepted challenge must still name its acceptor');
    }

    /**
     * Resolution prunes both squad rows, and the played match still serializes without them.
     *
     * Wiring the prune was the last of the review's hygiene items: the repository method
     * existed, was tested in isolation, and had no production caller, so friendly squads
     * accumulated forever while competition squads were pruned on completion. Asserted in
     * both halves on purpose — that the rows are gone, and that the match detail the client
     * actually fetches is unaffected, which is only true because a played friendly's lineups
     * are written onto the challenge itself.
     */
    public function testResolvingPrunesBothSquadRowsAndTheMatchStillSerializes(): void
    {
        $challenge = $this->seedAcceptedChallenge();
        $squadRepo = self::getContainer()->get(FriendlyChallengeSquadRepository::class);

        self::assertCount(2, $squadRepo->findBothSides($challenge), 'both sides submit a squad on acceptance');

        $this->service->resolveSingleMatch($challenge);
        $this->em->flush();

        self::assertSame(FriendlyChallengeStatus::COMPLETE, $challenge->getStatus());
        self::assertSame([], $squadRepo->findBothSides($challenge), 'full-time must prune the engine inputs');

        // The read path the client uses for a played friendly, with the rows gone.
        $detail = self::getContainer()->get(\App\Service\Competition\MatchQueryService::class)
            ->serializeFriendlyMatchDetail($challenge);

        self::assertNotEmpty($detail['homeLineup'], 'a played friendly keeps its lineup on the challenge');
        self::assertNotEmpty($detail['awayLineup']);
        self::assertSame('complete', $detail['status']);
    }
}
