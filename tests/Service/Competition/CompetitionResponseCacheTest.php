<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrantSquad;
use App\Entity\Competition\CompetitionResponseCache;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Repository\Competition\CompetitionResponseCacheRepository;
use App\Service\Competition\CompetitionRegistrationService;
use App\Service\Competition\CompetitionResponseBuilder;
use App\Service\Competition\CompetitionResponseCacheService;
use App\Tests\Support\EntrantFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The prebuilt bracket response: that it is reused, and — the part that actually needed
 * fixing — that it stops being reused when it should.
 *
 * The invalidation case is not hypothetical. The state version this cache keys on used to
 * count only entrants and round statuses, so a resubmission moved nothing: a club could
 * swap its whole squad and the version would hold still. The ETag survived that only by
 * separately folding in the *viewing* club's own snapshot version, which meant another
 * club's resubmission already failed to invalidate your ETag. Keying a shared document on
 * that version would have turned a tolerable ETag bug into served-stale brackets, so the
 * version now includes the sum of entrant snapshot versions and this test pins it.
 */
class CompetitionResponseCacheTest extends KernelTestCase
{
    use EntrantFixtureTrait;

    private EntityManagerInterface $em;
    private CompetitionResponseBuilder $builder;
    private CompetitionResponseCacheService $cache;
    private CompetitionResponseCacheRepository $cacheRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em              = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder         = self::getContainer()->get(CompetitionResponseBuilder::class);
        $this->cache           = self::getContainer()->get(CompetitionResponseCacheService::class);
        $this->cacheRepository = self::getContainer()->get(CompetitionResponseCacheRepository::class);
    }

    private function club(string $name): Club
    {
        $user = new User(sprintf('comp-cache-%s@example.com', uniqid('', true)));
        $user->setPassword('x');
        $club = new Club($name, $user);
        $this->em->persist($user);
        $this->em->persist($club);

        return $club;
    }

    /** @return array<string, mixed> */
    private function envelope(Club $club, string $name): array
    {
        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = ['id' => "$name-p$i", 'name' => "$name Player $i", 'position' => 'MID', 'currentAbility' => 60];
        }

        return [
            'club'    => ['id' => (string) $club->getId(), 'name' => $name, 'formation' => '4-4-2'],
            'players' => $players,
            'staff'   => [['id' => 's1', 'role' => 'MANAGER', 'name' => "$name Boss"]],
        ];
    }

    private function competitionWithTwoEntrants(): ActiveCompetition
    {
        $template = new CompetitionTemplate('Cache Cup', 'cache-cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $this->em->persist($template);
        $competition = new ActiveCompetition($template);
        $this->em->persist($competition);

        foreach (['Alpha', 'Beta'] as $i => $name) {
            $club = $this->club($name . ' ' . uniqid('', true));
            $this->makeEntrantFromEnvelope($this->em, $competition, $club, $i + 1, $this->envelope($club, $name));
        }

        $this->em->flush();

        return $competition;
    }

    private function warm(ActiveCompetition $competition): array
    {
        return $this->cache->getOrBuild(
            $competition,
            $this->builder->stateVersion($competition),
            fn (): array => $this->builder->sharedDocument($competition),
        );
    }

    public function testTheDocumentIsStoredAndReusedWithoutRebuilding(): void
    {
        $competition = $this->competitionWithTwoEntrants();

        $first = $this->warm($competition);
        self::assertNotEmpty($first['entrants']);

        $row = $this->cacheRepository->findForCompetition($competition);
        self::assertNotNull($row, 'the first read must store a document');
        self::assertSame(CompetitionResponseCache::PAYLOAD_VERSION, $row->getPayloadVersion());

        // A second read must not call the builder at all. If it does, the closure throws and
        // the assertion below never runs — which is the point of asserting it this way
        // rather than comparing two payloads that would match either way.
        $second = $this->cache->getOrBuild(
            $competition,
            $this->builder->stateVersion($competition),
            fn (): array => throw new \LogicException('the builder must not run for a cache hit'),
        );

        self::assertSame($first, $second);
    }

    /**
     * `viewer` is present and null in the stored document, so the request path overwrites it
     * rather than appending — which is what keeps the key order a client sees independent of
     * whether the response was cached.
     */
    public function testTheStoredDocumentCarriesANullViewerKey(): void
    {
        $document = $this->warm($this->competitionWithTwoEntrants());

        self::assertArrayHasKey('viewer', $document);
        self::assertNull($document['viewer']);
        self::assertArrayNotHasKey('serverTime', $document, 'serverTime must be per-request, never cached');
    }

    public function testAResubmissionInvalidatesTheDocument(): void
    {
        $competition = $this->competitionWithTwoEntrants();
        $this->warm($competition);

        $before = $this->builder->stateVersion($competition);

        // Resubmit one entrant's squad — changing neither the entrant count nor any round
        // status, which is exactly the shape that used to leave the version untouched.
        $entrant = $this->em->getRepository(\App\Entity\Competition\CompetitionEntrant::class)
            ->findOneBy(['activeCompetition' => $competition]);
        self::assertNotNull($entrant);

        self::getContainer()->get(CompetitionRegistrationService::class)->resubmit(
            $entrant,
            $this->envelope($entrant->getClub(), 'Alpha Reshuffled'),
        );
        $this->em->refresh($entrant);

        self::assertNotSame($before, $this->builder->stateVersion($competition), 'a resubmission must move the state version');
        self::assertFalse(
            $this->cache->isCached($competition, $this->builder->stateVersion($competition)),
            'the stored document must no longer be considered fresh',
        );
    }

    public function testASupersededPayloadVersionIsNotServed(): void
    {
        $competition = $this->competitionWithTwoEntrants();
        $this->warm($competition);

        $row = $this->cacheRepository->findForCompetition($competition);
        self::assertNotNull($row);

        // Simulate a deploy that changed the response shape.
        $version = new \ReflectionProperty(CompetitionResponseCache::class, 'payloadVersion');
        $version->setValue($row, CompetitionResponseCache::PAYLOAD_VERSION + 1);
        $this->em->flush();

        self::assertFalse($this->cache->isCached($competition, $this->builder->stateVersion($competition)));
        self::assertContains(
            (string) $competition->getId(),
            $this->cacheRepository->findStalePayloadVersionIds(CompetitionResponseCache::PAYLOAD_VERSION),
            'the warm command must be able to find it',
        );
    }

    /**
     * The bracket must be renderable with no squad rows at all, because a completed
     * competition has none — they are pruned once no further fixture can resolve. If the
     * shared document ever reached for a squad, every finished tournament would break.
     */
    public function testTheDocumentBuildsWithNoSquadRowsPresent(): void
    {
        $competition = $this->competitionWithTwoEntrants();

        self::getContainer()->get(\App\Repository\Competition\CompetitionEntrantSquadRepository::class)
            ->deleteForCompetition((string) $competition->getId());
        $this->em->clear();

        $competition = $this->em->getRepository(ActiveCompetition::class)->find($competition->getId());

        // Scoped to this competition's entrants, not the whole table — other tests in this
        // suite leave squad rows behind, so a global count would assert nothing about the
        // prune and would fail depending on execution order.
        $remaining = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM competition_entrant_squad s
               JOIN competition_entrant e ON e.id = s.entrant_id
              WHERE e.active_competition_id = :id',
            ['id' => $competition->getId()->toRfc4122()],
        );
        self::assertSame(0, $remaining);

        $document = $this->builder->sharedDocument($competition);
        self::assertNotEmpty($document['entrants']);
        self::assertNotEmpty($document['entrants'][0]['clubName']);
    }
}
