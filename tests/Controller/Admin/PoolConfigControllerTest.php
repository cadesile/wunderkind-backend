<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Agent;
use App\Entity\Investor;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Sponsor;
use App\Entity\Staff;
use App\Enum\StaffRole;
use App\Repository\PoolConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Covers the 3-way split of the former single "Pool Config" admin page into
 * Player / Staff (+scouts) / Investor (agents+sponsors+investors) sections.
 * Each group's save + generate-chunk + clear actions are independently
 * CSRF-gated and scoped to only their own PoolConfig fields / entity tables.
 */
class PoolConfigControllerTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'pool-config-split-test-admin@example.com';

    /**
     * A flash reached the page.
     *
     * UPPER-CASED ON THE WAY OUT, which is why these assertions were red long before this
     * file was touched: the redesigned shell renders flashes as toasts and upper-cases
     * whatever it is given (layout.html.twig's body_javascript), so every assertion written
     * against the controller's own mixed-case string could never match. Comparing upper to
     * upper keeps the test about "did the message arrive" rather than about letter case,
     * and survives the §11 copy rewrite still working through these pages.
     */
    private function assertFlash(string $message): void
    {
        $body = static::getClient()->getResponse()->getContent();

        self::assertStringContainsString(
            mb_strtoupper($message),
            mb_strtoupper($body),
            \sprintf('expected the flash "%s" on the page', $message),
        );
    }

    /** Same pattern as SocialAuthControllerTest::loginAsAdmin(). */
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

    /** Pre-seeds a session value, mirroring what a real form render would have stored. */
    private function seedSession(KernelBrowser $client, array $values): void
    {
        $session = $client->getContainer()->get('session.factory')->createSession();

        $existing = $client->getCookieJar()->get($session->getName());
        if ($existing !== null) {
            $session->setId($existing->getValue());
        }
        $session->start();

        foreach ($values as $key => $value) {
            $session->set($key, $value);
        }
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    /** Same pattern as NpcClubSizeWeightsControllerTest::seedValidCsrfToken(). */
    private function seedValidCsrfToken(KernelBrowser $client, string $tokenId): string
    {
        $value = 'test-csrf-token-' . bin2hex(random_bytes(8));
        $this->seedSession($client, ["_csrf/{$tokenId}" => $value]);
        return $value;
    }

    private function getConfig(): \App\Entity\PoolConfig
    {
        return self::getContainer()->get(PoolConfigRepository::class)->getConfig();
    }

    // ── Page rendering ───────────────────────────────────────────────────

    /**
     * Custom admin pages must be reached via /admin?routeName=... — EasyAdmin's
     * `ea` Twig context (required by @EasyAdmin/layout.html.twig) is only
     * populated for requests that pass through the dashboard's own /admin
     * route; hitting a custom route's own path directly throws "Impossible to
     * access an attribute (i18n) on a null variable" (documented in CLAUDE.md).
     */
    public function testPlayerPoolConfigPageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $client->request('GET', '/admin?routeName=admin_player_pool_config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.bmc-title', 'PLAYER POOL');
        // The picker is the part with a backend behind it: every nationality gets a row,
        // whether or not anyone of that nationality is in the pool.
        $this->assertSelectorExists('#picklist .bmc-pick-row[data-nat="English"]');
    }

    public function testStaffPoolConfigPageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $client->request('GET', '/admin?routeName=admin_staff_pool_config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.bmc-title', 'STAFF POOL');
        $this->assertSelectorExists('#picklist .bmc-pick-row[data-nat="English"]');
        // Six role tiles plus scouts.
        $this->assertSelectorExists('.bmc-pooltile[data-type="facility_managers"]');
    }

    public function testInvestorPoolConfigPageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $client->request('GET', '/admin?routeName=admin_investor_pool_config');
        $this->assertResponseIsSuccessful();
        // Redesigned: the title moved out of an <h5> in the body and into the shell's page
        // strip, upper-cased from the bmcTitle the controller passes.
        $this->assertSelectorTextContains('.bmc-title', 'INVESTOR POOL');
    }

    // ── Player pool ──────────────────────────────────────────────────────

    public function testSavePlayerPoolConfigPersistsNewValues(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $token = $this->seedValidCsrfToken($client, 'save_player_pool_config');

        $client->request('POST', '/admin/player-pool-config/save', [
            '_token'          => $token,
            'playerAgeMin'    => '15',
            'playerPoolTarget'=> '77',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertFlash('Player pool rules saved');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $config = $this->getConfig();
        $this->assertSame(15, $config->getPlayerAgeMin());
        $this->assertSame(77, $config->getPlayerPoolTarget());
    }

    public function testSavePlayerPoolConfigRequiresValidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $config = $this->getConfig();
        $config->setPlayerAgeMin(12);
        $em->flush();

        $client->request('POST', '/admin/player-pool-config/save', [
            '_token'       => 'not-a-real-token',
            'playerAgeMin' => '99',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertFlash('Invalid CSRF token.');

        $em->clear();
        $this->assertSame(12, $this->getConfig()->getPlayerAgeMin());
    }

    public function testGeneratePlayerPoolChunkRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/player-pool-config/generate-chunk', [
            '_token' => 'not-a-real-token',
            'type'   => 'players',
            'mode'   => 'force',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testClearPlayerPoolOnlyRemovesPlayers(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $player = new Player('Clear', 'Test');
        $staff  = new Staff('Clear', 'Test', StaffRole::COACH);
        $em->persist($player);
        $em->persist($staff);
        $em->flush();

        $token = $this->seedValidCsrfToken($client, 'clear_player_pool');
        $client->request('POST', '/admin/player-pool-config/clear', ['_token' => $token]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertFlash('Player pool cleared');

        $em->clear();
        $this->assertNull($em->find(Player::class, $player->getId()), 'player should be deleted');
        $this->assertNotNull($em->find(Staff::class, $staff->getId()), 'staff must NOT be touched by the player-pool clear');

        $em->remove($em->find(Staff::class, $staff->getId()));
        $em->flush();
    }

    // ── Staff pool (roles + scouts) ─────────────────────────────────────

    public function testSaveStaffPoolConfigPersistsNewValues(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $token = $this->seedValidCsrfToken($client, 'save_staff_pool_config');

        $client->request('POST', '/admin/staff-pool-config/save', [
            '_token'          => $token,
            'coachAbilityMin' => '55',
            'scoutPoolTarget' => '9',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertFlash('Staff pool rules saved');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $config = $this->getConfig();
        $this->assertSame(55, $config->getCoachAbilityMin());
        $this->assertSame(9, $config->getScoutPoolTarget());
    }

    /**
     * Scout wages are edited in pounds and stored in pence.
     *
     * The one unit conversion on these screens. PoolConfig holds pence, an operator thinks in
     * pounds per week, and a field showing 2500 when it means £25 is a trap you fall into once.
     */
    public function testScoutWagesArePostedInPoundsAndStoredInPence(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $token = $this->seedValidCsrfToken($client, 'save_staff_pool_config');

        $client->request('POST', '/admin/staff-pool-config/save', [
            '_token'              => $token,
            'scoutWagePoundsMin'  => '30',
            'scoutWagePoundsMax'  => '90',
        ]);

        $this->assertResponseRedirects();

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $config = $this->getConfig();
        $this->assertSame(3000, $config->getScoutWageMin());
        $this->assertSame(9000, $config->getScoutWageMax());
    }

    /** The older pence fields still work, so anything posting the previous shape is unaffected. */
    public function testScoutWagesStillAcceptTheOlderPenceFields(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $token = $this->seedValidCsrfToken($client, 'save_staff_pool_config');

        $client->request('POST', '/admin/staff-pool-config/save', [
            '_token'       => $token,
            'scoutWageMin' => '2500',
            'scoutWageMax' => '7000',
        ]);

        $this->assertResponseRedirects();

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $config = $this->getConfig();
        $this->assertSame(2500, $config->getScoutWageMin());
        $this->assertSame(7000, $config->getScoutWageMax());
    }

    public function testSaveStaffPoolConfigRequiresValidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $config = $this->getConfig();
        $config->setCoachAbilityMin(40);
        $em->flush();

        $client->request('POST', '/admin/staff-pool-config/save', [
            '_token'          => 'not-a-real-token',
            'coachAbilityMin' => '99',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertFlash('Invalid CSRF token.');

        $em->clear();
        $this->assertSame(40, $this->getConfig()->getCoachAbilityMin());
    }

    public function testGenerateStaffPoolChunkRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/staff-pool-config/generate-chunk', [
            '_token' => 'not-a-real-token',
            'type'   => 'coaches',
            'mode'   => 'force',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testClearStaffPoolOnlyRemovesStaffAndScouts(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $player = new Player('Clear', 'Test2');
        $staff  = new Staff('Clear', 'Test2', StaffRole::MANAGER);
        $scout  = new Scout('Clear Scout');
        $em->persist($player);
        $em->persist($staff);
        $em->persist($scout);
        $em->flush();

        $token = $this->seedValidCsrfToken($client, 'clear_staff_pool');
        $client->request('POST', '/admin/staff-pool-config/clear', ['_token' => $token]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertFlash('Staff pool cleared');

        $em->clear();
        $this->assertNull($em->find(Staff::class, $staff->getId()), 'staff should be deleted');
        $this->assertNull($em->find(Scout::class, $scout->getId()), 'scout should be deleted');
        $this->assertNotNull($em->find(Player::class, $player->getId()), 'player must NOT be touched by the staff-pool clear');

        $em->remove($em->find(Player::class, $player->getId()));
        $em->flush();
    }

    // ── Investor pool (agents + sponsors + investors) ───────────────────

    public function testSaveInvestorPoolConfigPersistsNewValues(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $token = $this->seedValidCsrfToken($client, 'save_investor_pool_config');

        $client->request('POST', '/admin/investor-pool-config/save', [
            '_token'             => $token,
            'agentReputationMin' => '33',
            'sponsorPoolTarget'  => '14',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertFlash('Investor pool rules saved');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $config = $this->getConfig();
        $this->assertSame(33, $config->getAgentReputationMin());
        $this->assertSame(14, $config->getSponsorPoolTarget());
    }

    public function testSaveInvestorPoolConfigRequiresValidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $config = $this->getConfig();
        $config->setAgentReputationMin(30);
        $em->flush();

        $client->request('POST', '/admin/investor-pool-config/save', [
            '_token'             => 'not-a-real-token',
            'agentReputationMin' => '99',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertFlash('Invalid CSRF token.');

        $em->clear();
        $this->assertSame(30, $this->getConfig()->getAgentReputationMin());
    }

    public function testGenerateInvestorPoolChunkRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/investor-pool-config/generate-chunk', [
            '_token' => 'not-a-real-token',
            'type'   => 'agents',
            'mode'   => 'force',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testClearInvestorPoolOnlyRemovesAgentsSponsorsInvestors(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $player   = new Player('Clear', 'Test3');
        $agent    = new Agent('Clear Agent');
        $sponsor  = new Sponsor('Clear Sponsor Co');
        $investor = new Investor('Clear Investor Co');
        $em->persist($player);
        $em->persist($agent);
        $em->persist($sponsor);
        $em->persist($investor);
        $em->flush();

        $token = $this->seedValidCsrfToken($client, 'clear_investor_pool');
        $client->request('POST', '/admin/investor-pool-config/clear', ['_token' => $token]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertFlash('Investor pool cleared');

        $em->clear();
        $this->assertNull($em->find(Agent::class, $agent->getId()), 'agent should be deleted');
        $this->assertNull($em->find(Sponsor::class, $sponsor->getId()), 'sponsor should be deleted');
        $this->assertNull($em->find(Investor::class, $investor->getId()), 'investor should be deleted');
        $this->assertNotNull($em->find(Player::class, $player->getId()), 'player must NOT be touched by the investor-pool clear');

        $em->remove($em->find(Player::class, $player->getId()));
        $em->flush();
    }
}
