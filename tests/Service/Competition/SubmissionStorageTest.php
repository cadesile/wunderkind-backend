<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Repository\Competition\CompetitionEntrantSubmissionRepository;
use App\Service\Competition\CompetitionRegistrationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the verbatim envelope actually reaches the database and stays there.
 *
 * SubmittedSnapshotSplitTest covers the decision (what splitSnapshot() puts where); this
 * covers the write. They are separate because the thing that went wrong historically was not
 * the rule but the plumbing — `facilities` was dropped at the DTO, so no amount of correct
 * splitting would have stored it.
 */
class SubmissionStorageTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CompetitionRegistrationService $registrationService;
    private CompetitionEntrantSubmissionRepository $submissionRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em                   = self::getContainer()->get(EntityManagerInterface::class);
        $this->registrationService  = self::getContainer()->get(CompetitionRegistrationService::class);
        $this->submissionRepository = self::getContainer()->get(CompetitionEntrantSubmissionRepository::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_entrant_squad, competition_entrant_submission,
                      competition_fixture, competition_result, competition_result_audit,
                      competition_round, competition_response_cache, active_competition,
                      competition_template, entrant_reward_claim CASCADE',
        );
    }

    private function club(string $name): Club
    {
        $user = new User(sprintf('submission-%s@example.com', uniqid('', true)));
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
        $template = new CompetitionTemplate('Submission Cup', 'submission-cup-' . uniqid('', true), $capacity, CompetitionDuration::TEN_HOURS);
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
            'club' => [
                'id'   => (string) $club->getId(),
                'name' => $name,
                // The keys clubDisplayBlock() drops — in a real payload these are ~99% of it.
                'balance'          => 4_250_000,
                'cupTrophies'      => [['prizePence' => 250_000, 'season' => 2]],
                'facilityUpgrades' => [['slug' => 'north_stand', 'level' => 4, 'week' => 51]],
                'seasonTickets'    => ['sold' => 3100, 'pricePence' => 32_000],
            ],
            'players'          => $players,
            'staff'            => [['id' => 's1', 'role' => 'MANAGER', 'name' => "$name Boss"]],
            'facilities'       => ['north_stand' => 4, 'museum' => 0],
            'clientSnapshotAt' => '2026-08-15T00:00:00+00:00',
        ];
    }

    public function testRegisterStoresTheWholeEnvelope(): void
    {
        $instance = $this->competition();
        $club     = $this->club('Submission FC');
        $envelope = $this->envelope($club, 'Submission FC');

        $this->registrationService->register($instance, $club, $envelope);
        $this->em->clear();

        $entrant    = $this->em->getRepository(\App\Entity\Competition\CompetitionEntrant::class)->findOneBy([]);
        $submission = $this->submissionRepository->findForEntrant($entrant);

        self::assertNotNull($submission, 'a registration must leave a submission row');
        self::assertSame($envelope, $submission->getEnvelopeJson(), 'stored byte-for-byte as posted');

        // And the bounded row stays bounded — storing everything must not widen what the
        // public bracket decodes.
        self::assertArrayNotHasKey('cupTrophies', $entrant->getClubDisplayJson());
        self::assertArrayNotHasKey('balance', $entrant->getClubDisplayJson());
    }

    public function testResubmitReplacesTheStoredEnvelope(): void
    {
        $instance = $this->competition();
        $club     = $this->club('Resubmit FC');

        $this->registrationService->register($instance, $club, $this->envelope($club, 'Resubmit FC'));
        $this->em->clear();

        $entrant = $this->em->getRepository(\App\Entity\Competition\CompetitionEntrant::class)->findOneBy([]);

        $second                        = $this->envelope($entrant->getClub(), 'Resubmit FC');
        $second['club']['balance']     = 9_999_999;
        $second['club']['cupTrophies'] = [];

        $this->registrationService->resubmit($entrant, $second);
        $this->em->clear();

        $entrant    = $this->em->getRepository(\App\Entity\Competition\CompetitionEntrant::class)->findOneBy([]);
        $submission = $this->submissionRepository->findForEntrant($entrant);

        self::assertNotNull($submission);
        self::assertSame(9_999_999, $submission->getEnvelopeJson()['club']['balance'], 'the upsert replaced, not duplicated');
        self::assertSame([], $submission->getEnvelopeJson()['club']['cupTrophies']);

        $count = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM competition_entrant_submission WHERE entrant_id = :id',
            ['id' => $entrant->getId()->toRfc4122()],
        );
        self::assertSame(1, $count, 'ON CONFLICT (entrant_id) keeps exactly one row per entrant');
    }

    /**
     * The whole reason this is a separate table from competition_entrant_squad: that one is
     * dropped when a competition completes, and this one must not be.
     */
    public function testTheCompletionPruneLeavesSubmissionsAlone(): void
    {
        $instance = $this->competition();
        $club     = $this->club('Survivor FC');
        $this->registrationService->register($instance, $club, $this->envelope($club, 'Survivor FC'));
        $this->em->clear();

        $entrant = $this->em->getRepository(\App\Entity\Competition\CompetitionEntrant::class)->findOneBy([]);
        $id      = $entrant->getId()->toRfc4122();

        self::getContainer()->get(\App\Repository\Competition\CompetitionEntrantSquadRepository::class)
            ->deleteForCompetition((string) $entrant->getActiveCompetition()->getId());

        $connection = $this->em->getConnection();
        self::assertSame(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM competition_entrant_squad WHERE entrant_id = :id', ['id' => $id]),
            'the squad row is pruned, as before',
        );
        self::assertSame(
            1,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM competition_entrant_submission WHERE entrant_id = :id', ['id' => $id]),
            'the submission survives — a finished tournament is exactly when someone asks what a club looked like',
        );
    }
}
