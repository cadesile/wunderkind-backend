<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\Competition\EntrantSquadState;
use App\Message\Competition\StoreEntrantSubmissionMessage;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Service\Competition\CompetitionDrawService;
use App\Service\Competition\CompetitionRegistrationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Pins the queued half of registration: that the bulky write really is dispatched rather than
 * done under the competition's row lock, and that nothing can draw a competition whose squads
 * have not landed.
 *
 * The functional suite runs the `registration` transport as `sync://`, so everywhere else a
 * registration looks fully synchronous — which is what makes the real post-drain behaviour
 * testable. These tests deliberately bypass that by injecting their own bus, because what is
 * under test here is the dispatch itself.
 */
class RegistrationQueueingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CompetitionEntrantRepository $entrantRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em                = self::getContainer()->get(EntityManagerInterface::class);
        $this->entrantRepository = self::getContainer()->get(CompetitionEntrantRepository::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_entrant_squad, competition_entrant_submission,
                      competition_fixture, competition_result, competition_result_audit,
                      competition_round, competition_response_cache, active_competition,
                      competition_template, entrant_reward_claim CASCADE',
        );
    }

    /** A bus that records instead of handling, so the dispatch is observable. */
    private function spyBus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;

                return new Envelope($message);
            }
        };
    }

    private function club(string $name): Club
    {
        $user = new User(sprintf('queue-%s@example.com', uniqid('', true)));
        $user->setPassword('x');
        $club = new Club($name, $user);
        $club->setBalance(1_000_000);
        $this->em->persist($user);
        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function competition(int $capacity = 4): ActiveCompetition
    {
        $template = new CompetitionTemplate('Queue Cup', 'queue-cup-' . uniqid('', true), $capacity, CompetitionDuration::TEN_HOURS);
        $this->em->persist($template);
        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);
        $this->em->flush();

        return $instance;
    }

    /** @return array<string, mixed> */
    private function envelope(Club $club, string $name): array
    {
        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = ['id' => "$name-p$i", 'name' => "$name Player $i", 'position' => 'MID', 'currentAbility' => 60];
        }

        return [
            'club'    => ['id' => (string) $club->getId(), 'name' => $name, 'cupTrophies' => [['prizePence' => 1000]]],
            'players' => $players,
            'staff'   => [['id' => 's1', 'role' => 'MANAGER', 'name' => "$name Boss"]],
        ];
    }

    private function serviceWith(MessageBusInterface $bus): CompetitionRegistrationService
    {
        $container = self::getContainer();

        return new CompetitionRegistrationService(
            $this->em,
            $this->entrantRepository,
            $container->get(\App\Service\Competition\CompetitionLockService::class),
            $container->get(\App\Repository\Competition\CompetitionRoundRepository::class),
            null,
            null,
            $bus,
        );
    }

    public function testTheBulkyWriteIsDispatchedAndNotDoneInTheLockedTransaction(): void
    {
        $bus      = $this->spyBus();
        $instance = $this->competition();
        $club     = $this->club('Queued FC');
        $envelope = $this->envelope($club, 'Queued FC');

        $this->serviceWith($bus)->register($instance, $club, $envelope);
        $this->em->clear();

        self::assertCount(1, $bus->dispatched, 'registration must queue exactly one submission message');
        $message = $bus->dispatched[0];
        self::assertInstanceOf(StoreEntrantSubmissionMessage::class, $message);
        self::assertSame($envelope, $message->envelope, 'the whole envelope travels with the message');

        $entrant = $this->entrantRepository->find($message->entrantId);
        self::assertInstanceOf(CompetitionEntrant::class, $entrant);

        // The slot is held and the display block is readable — which is why the HTTP response
        // can still be the synchronous 201 it always was.
        self::assertSame(EntrantSquadState::PENDING, $entrant->getSquadState());
        self::assertSame('Queued FC', $entrant->getClubDisplayJson()['name']);

        // ...and the expensive rows are genuinely absent until the handler runs.
        $connection = $this->em->getConnection();
        $id         = $entrant->getId()->toRfc4122();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM competition_entrant_squad WHERE entrant_id = :id', ['id' => $id]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM competition_entrant_submission WHERE entrant_id = :id', ['id' => $id]));
    }

    public function testStoringTheSubmissionFillsBothRowsAndFlipsTheState(): void
    {
        $bus      = $this->spyBus();
        $instance = $this->competition();
        $club     = $this->club('Filled FC');
        $envelope = $this->envelope($club, 'Filled FC');
        $service  = $this->serviceWith($bus);

        $service->register($instance, $club, $envelope);
        $message = $bus->dispatched[0];

        $entrant = $this->entrantRepository->find($message->entrantId);
        $service->storeSubmission($entrant, $message->envelope);
        $this->em->clear();

        $entrant = $this->entrantRepository->find($message->entrantId);
        self::assertSame(EntrantSquadState::STORED, $entrant->getSquadState());

        $connection = $this->em->getConnection();
        $id         = $entrant->getId()->toRfc4122();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM competition_entrant_squad WHERE entrant_id = :id', ['id' => $id]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM competition_entrant_submission WHERE entrant_id = :id', ['id' => $id]));
    }

    /** Replay safety: the handler's writes are upserts, so a duplicate delivery must not duplicate rows. */
    public function testStoringTwiceIsANoOp(): void
    {
        $bus      = $this->spyBus();
        $instance = $this->competition();
        $club     = $this->club('Replay FC');
        $service  = $this->serviceWith($bus);

        $service->register($instance, $club, $this->envelope($club, 'Replay FC'));
        $message = $bus->dispatched[0];
        $entrant = $this->entrantRepository->find($message->entrantId);

        $service->storeSubmission($entrant, $message->envelope);
        $service->storeSubmission($entrant, $message->envelope);

        $id = $entrant->getId()->toRfc4122();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM competition_entrant_squad WHERE entrant_id = :id', ['id' => $id]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM competition_entrant_submission WHERE entrant_id = :id', ['id' => $id]));
    }

    /**
     * The safety property the whole design rests on. A squad row is the match engine's only
     * input, so drawing a round against a PENDING entrant would not error — it would resolve a
     * real fixture against an empty XI and publish the result.
     */
    public function testARoundIsNotDrawnWhileAnyEntrantIsStillPending(): void
    {
        $bus      = $this->spyBus();
        $instance = $this->competition(4);
        $service  = $this->serviceWith($bus);

        foreach (['Pending A', 'Pending B', 'Pending C', 'Pending D'] as $name) {
            $club = $this->club($name);
            $service->register($instance, $club, $this->envelope($club, $name));
        }
        $this->em->flush();

        $drawService = self::getContainer()->get(CompetitionDrawService::class);
        $now         = new \DateTimeImmutable('+1 day');

        self::assertSame(0, $drawService->drawDueRounds($now), 'no round may be drawn while a submission is unstored');

        // Nothing was claimed either — a deferred draw must stay retryable, not sit on a lease.
        $claimed = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM competition_round WHERE draw_locked_at IS NOT NULL',
        );
        self::assertSame(0, $claimed, 'deferring must not take a claim it cannot release');

        // Once both squads land, the same call proceeds.
        foreach ($bus->dispatched as $message) {
            $service->storeSubmission($this->entrantRepository->find($message->entrantId), $message->envelope);
        }
        $this->em->clear();

        self::assertGreaterThan(0, $drawService->drawDueRounds($now), 'with every squad stored the draw goes ahead');
    }

    /**
     * The client-facing half: a reserved-but-unfilled entry must be distinguishable from a
     * settled one, and the vocabulary is the API's own ('confirmed'), not the enum's
     * ('stored'). Contract: docs/api/competition-registration-status.md.
     */
    public function testSubmissionStatusIsExposedToTheClientAndFlipsOnStore(): void
    {
        $bus      = $this->spyBus();
        $instance = $this->competition();
        $club     = $this->club('Visible FC');
        $service  = $this->serviceWith($bus);

        $service->register($instance, $club, $this->envelope($club, 'Visible FC'));
        $message = $bus->dispatched[0];
        $entrant = $this->entrantRepository->find($message->entrantId);

        $builder = self::getContainer()->get(\App\Service\Competition\CompetitionResponseBuilder::class);

        $preview = $builder->serializeEntrantPreview($entrant);
        self::assertSame('pending', $preview['submissionStatus']);
        self::assertSame('registered', $preview['status'], 'the two are different axes — a pending entry is still registered');

        $service->storeSubmission($entrant, $message->envelope);
        $this->em->clear();

        $entrant = $this->entrantRepository->find($message->entrantId);
        self::assertSame('confirmed', $builder->serializeEntrantPreview($entrant)['submissionStatus'], 'clients see "confirmed", never the internal "stored"');
    }

    public function testSettledIsFalseOnlyWhilePending(): void
    {
        self::assertFalse(EntrantSquadState::PENDING->isSettled());
        // FAILED is settled on purpose: terminal without an operator, so a client polling it
        // would poll forever.
        self::assertTrue(EntrantSquadState::FAILED->isSettled());
        self::assertTrue(EntrantSquadState::STORED->isSettled());

        self::assertSame('failed', EntrantSquadState::FAILED->clientValue());
    }

    /**
     * The question the failed-state UI hangs on: can the player act, or only be told?
     *
     * They can. A failed submission leaves `status` at REGISTERED — squadState is a separate
     * axis — so withdraw()'s own guard matches and the entry fee refunds down the ordinary
     * path, with no special-casing for this state.
     */
    public function testAFailedEntryCanBeWithdrawnAndIsRefunded(): void
    {
        $bus      = $this->spyBus();
        $instance = $this->competition();
        $club     = $this->club('Doomed FC');
        $service  = $this->serviceWith($bus);

        $instance->getTemplate()->setEntryFeePence(50_000);
        $this->em->flush();
        $balanceBefore = $club->getBalance();

        $service->register($instance, $club, $this->envelope($club, 'Doomed FC'));
        $service->markSubmissionFailed($bus->dispatched[0]->entrantId);
        $this->em->clear();

        $club     = $this->em->getRepository(Club::class)->find($club->getId());
        $instance = $this->em->getRepository(ActiveCompetition::class)->find($instance->getId());

        $entrant = $service->withdraw($instance, $club);

        self::assertSame(\App\Enum\Competition\CompetitionEntrantStatus::WITHDRAWN, $entrant->getStatus());
        self::assertSame($balanceBefore, $club->getBalance(), 'the entry fee comes back exactly as for any other withdrawal');

        // And withdrawing actually clears the blockage, rather than leaving a dead row that
        // still holds the competition hostage.
        self::assertSame(0, $this->entrantRepository->countUnstoredForCompetition($instance));
    }

    /**
     * The reason the above is reachable at all: a competition must not lock while any entrant
     * is unstored.
     *
     * Locking sets the competition SCHEDULED and entrants ACTIVE, and withdraw() requires
     * REGISTERING + REGISTERED — so locking over an unstored entrant would wedge the
     * competition permanently with no player-side remedy. Deferring the lock is what keeps a
     * failure recoverable.
     */
    public function testAFullCompetitionDoesNotLockWhileAnEntrantIsUnstored(): void
    {
        $bus      = $this->spyBus();
        $instance = $this->competition(4);
        $service  = $this->serviceWith($bus);

        foreach (['Lock A', 'Lock B', 'Lock C', 'Lock D'] as $name) {
            $club = $this->club($name);
            $service->register($instance, $club, $this->envelope($club, $name));
        }
        $this->em->clear();
        $instance = $this->em->getRepository(ActiveCompetition::class)->find($instance->getId());

        self::assertSame(
            \App\Enum\Competition\ActiveCompetitionStatus::REGISTERING,
            $instance->getStatus(),
            'full on slots, but every squad is still pending — locking here would strand them',
        );

        // Store three of the four: still not lockable.
        foreach (array_slice($bus->dispatched, 0, 3) as $message) {
            $service->storeSubmission($this->entrantRepository->find($message->entrantId), $message->envelope);
        }
        $this->em->clear();
        $instance = $this->em->getRepository(ActiveCompetition::class)->find($instance->getId());
        self::assertSame(\App\Enum\Competition\ActiveCompetitionStatus::REGISTERING, $instance->getStatus());

        // The last one completes the field, and the lock lands with it.
        $last = $bus->dispatched[3];
        $service->storeSubmission($this->entrantRepository->find($last->entrantId), $last->envelope);
        $this->em->clear();

        $instance = $this->em->getRepository(ActiveCompetition::class)->find($instance->getId());
        self::assertSame(
            \App\Enum\Competition\ActiveCompetitionStatus::SCHEDULED,
            $instance->getStatus(),
            'the final stored submission is what locks the competition',
        );
    }

    /** A resubmit writes its rows inline, so it must clear a FAILED flag rather than leave it describing data that now exists. */
    public function testResubmittingClearsAFailedState(): void
    {
        $bus      = $this->spyBus();
        $instance = $this->competition();
        $club     = $this->club('Recovered FC');
        $service  = $this->serviceWith($bus);

        $service->register($instance, $club, $this->envelope($club, 'Recovered FC'));
        $service->markSubmissionFailed($bus->dispatched[0]->entrantId);
        $this->em->clear();

        $entrant = $this->entrantRepository->find($bus->dispatched[0]->entrantId);
        self::assertSame(EntrantSquadState::FAILED, $entrant->getSquadState());

        $service->resubmit($entrant, $this->envelope($entrant->getClub(), 'Recovered FC'));
        $this->em->clear();

        $entrant = $this->entrantRepository->find($bus->dispatched[0]->entrantId);
        self::assertSame(EntrantSquadState::STORED, $entrant->getSquadState(), 'the rows are written, so the flag must say so');

        // And the client is told, which is the whole point of resubmitting a failed entry.
        self::assertSame('confirmed', $entrant->getSquadState()->clientValue());
    }
}
