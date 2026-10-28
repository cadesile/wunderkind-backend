<?php

declare(strict_types=1);

namespace App\Tests\Service\Friendly;

use App\Entity\Club;
use App\Entity\Friendly\FriendlyChallenge;
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
}
