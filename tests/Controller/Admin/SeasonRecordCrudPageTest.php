<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Club;
use App\Entity\League;
use App\Entity\SeasonRecord;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The overwrite audit is the ONLY place a superseded season result survives — nothing derived
 * from a SeasonRecord is recomputed when a resubmission corrects it, so a disputed Hall of
 * Fame title is settled by reading this page. That makes "does it actually render" load-bearing
 * rather than cosmetic.
 *
 * It is worth a test because the field is wired in a way that is easy to get wrong twice over:
 * `overwriteAudit` is a Doctrine `json` column, which EasyAdmin auto-configures as a collection
 * (see CLAUDE.md), so the page reads the entity's own pretty-printing accessor instead — and
 * a CodeEditorField has to cope with the null that every never-corrected row carries.
 */
class SeasonRecordCrudPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'season-record-test-admin@example.com';

    /** @var object[] Removed in reverse creation order. */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $em->remove($managed);
            }
        }
        $this->cleanup = [];
        $em->flush();

        parent::tearDown();
    }

    public function testTheDetailPageShowsTheOverwriteCountAndTheAudit(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $record = $this->correctedRecord();

        $crawler = $client->request('GET', self::getContainer()->get('router')->generate(
            'admin_season_record_detail',
            ['entityId' => (string) $record->getId()],
        ));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Overwrite audit');
        // The superseded result: position 4, W/D/L 19-8-11 (the row itself now reads 1 / 26-6-6).
        self::assertSelectorTextContains('body', '"finalPosition": 4');
        self::assertSelectorTextContains('body', '"wins": 19');
        self::assertSelectorTextContains('body', '"losses": 11');
    }

    /** A row that has never been corrected carries a null audit, which must not break the page. */
    public function testTheDetailPageRendersForANeverCorrectedRecord(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $record = $this->record();

        $client->request('GET', self::getContainer()->get('router')->generate(
            'admin_season_record_detail',
            ['entityId' => (string) $record->getId()],
        ));

        self::assertResponseIsSuccessful();
        self::assertSame(0, $record->getOverwriteCount(), 'precondition');
        self::assertNull($record->getOverwriteAudit(), 'precondition');
    }

    public function testTheIndexListsTheOverwriteCount(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $record = $this->correctedRecord();

        $crawler = $client->request('GET', self::getContainer()->get('router')->generate('admin_season_record_index'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Overwrites');

        $row = $crawler->filter(sprintf('tr[data-id="%s"]', $record->getId()));
        self::assertGreaterThan(0, $row->count(), 'the record should be listed');
        self::assertStringContainsString('1', $row->text(), 'the row should show its overwrite count');
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function correctedRecord(): SeasonRecord
    {
        $record = $this->record();

        $record->overwriteWith([
            'finalPosition' => 1,
            'gamesPlayed'   => 38,
            'wins'          => 26,
            'draws'         => 6,
            'losses'        => 6,
            'goalsFor'      => 82,
            'goalsAgainst'  => 30,
            'points'        => 84,
            'promoted'      => true,
            'relegated'     => false,
        ], new \DateTimeImmutable('2026-11-01T14:02:11+00:00'));

        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $record;
    }

    private function record(): SeasonRecord
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@season-record-admin.test'));
        $user->setPassword('x');

        $league = $this->persist(new League($this->unusedCountryCode(), 4, 'Season Record Admin Tier 4'));
        $club   = $this->persist(new Club('Season Record Admin FC', $user));
        $club->setCurrentLeague($league);

        $record = $this->persist(new SeasonRecord(
            club:          $club,
            league:        $league,
            season:        1,
            finalPosition: 4,
            gamesPlayed:   38,
            wins:          19,
            draws:         8,
            losses:        11,
            goalsFor:      61,
            goalsAgainst:  44,
            points:        65,
            promoted:      false,
            relegated:     false,
        ));

        $em->flush();

        return $record;
    }

    private function loginAsAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    /** uq_league_country_tier spans only 676 two-letter codes — don't collide with a leaked row. */
    private function unusedCountryCode(): string
    {
        $repo = self::getContainer()->get(\App\Repository\LeagueRepository::class);

        for ($i = 0; $i < 50; $i++) {
            $code = chr(random_int(65, 90)) . chr(random_int(65, 90));
            if ($repo->findByCountry($code) === []) {
                return $code;
            }
        }

        self::fail('could not find an unused country code');
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        self::getContainer()->get(EntityManagerInterface::class)->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }
}
