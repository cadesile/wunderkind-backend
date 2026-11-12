<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Repository\GameConfigRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Clearing the contract-value Rand Min/Max fields must not rescale every generated wage.
 *
 * THESE TWO FIELDS ARE A MULTIPLIER ON EVERY PLAYER THE WORLD GENERATES —
 * WorldPackClubGenerationService::buildPlayer() and MarketPoolService::generatePlayers() both
 * roll `ability * random_int(min, max)`. The save handler used to read them through a bare
 * `(int)`, so a CLEARED field posted '', cast to 0, and the entity setters floored it to 1.
 * Live values of 2000/2800 collapsed to 1/1 and every player generated afterwards was priced
 * at roughly a 2,400th of what it should have been.
 *
 * WHY THAT COULD NOT BE CAUGHT BY LOOKING. The form reports a successful save, the two numbers
 * it shows afterwards are a legal configuration, and nothing on the screen prices a player —
 * the damage only surfaces as NPC squads that are implausibly cheap, in packs generated days
 * later. The old GET fallbacks of 10/40 were no protection either: this screen is ONE form
 * that posts all 155 fields, so an ABSENT key is not a state an operator can reach, while a
 * BLANK one is a single keystroke.
 *
 * DRIVEN THROUGH THE REAL RENDERED FORM rather than a hand-built body, because "what a browser
 * posts" is the whole question. Hand-assembling the payload would let this test agree with
 * itself about which fields exist and miss the next field added to the handler.
 */
class GameConfigContractValueRandGuardTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'game-config-rand-guard@example.com';

    /** Deliberately nothing like the 10/40 defaults, so a reset to them is visible. */
    private const SEED_MIN = 2000;
    private const SEED_MAX = 2800;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->loginAsAdmin();
        $this->storeRand(self::SEED_MIN, self::SEED_MAX);
    }

    public function testClearingRandMinLeavesTheStoredPairAlone(): void
    {
        $this->submitWith(['contractValueRandMin' => '']);

        self::assertSame(
            [self::SEED_MIN, self::SEED_MAX],
            $this->storedRand(),
            'a blank Rand Min must leave both stored values untouched, not collapse them to 1',
        );
    }

    public function testClearingRandMaxLeavesTheStoredPairAlone(): void
    {
        $this->submitWith(['contractValueRandMax' => '']);

        self::assertSame([self::SEED_MIN, self::SEED_MAX], $this->storedRand());
    }

    public function testClearingBothLeavesTheStoredPairAlone(): void
    {
        $this->submitWith(['contractValueRandMin' => '', 'contractValueRandMax' => '']);

        self::assertSame([self::SEED_MIN, self::SEED_MAX], $this->storedRand());
    }

    public function testRealValuesAreStillSaved(): void
    {
        // The guard must not make the fields read-only — that would be the same defect wearing
        // the opposite sign.
        $this->submitWith(['contractValueRandMin' => '1500', 'contractValueRandMax' => '2200']);

        self::assertSame([1500, 2200], $this->storedRand());
    }

    public function testATransposedPairIsStillNormalised(): void
    {
        $this->submitWith(['contractValueRandMin' => '2200', 'contractValueRandMax' => '1500']);

        self::assertSame([1500, 2200], $this->storedRand(), 'min/max are set independently, so either order is a legitimate submission');
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /**
     * Submits the game-config form exactly as rendered, with $overrides applied.
     *
     * @param array<string, string> $overrides
     */
    private function submitWith(array $overrides): void
    {
        $crawler = $this->client->request('GET', '/admin?routeName=admin_game_config');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form#game-config-form')->form();

        foreach ($overrides as $field => $value) {
            self::assertTrue($form->has($field), "the form no longer renders {$field}");
            $form->get($field)->setValue($value);
        }

        $this->client->submit($form);
        self::assertResponseRedirects();
    }

    /** @return array{int, int} */
    private function storedRand(): array
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();

        return [$config->getContractValueRandMin(), $config->getContractValueRandMax()];
    }

    private function storeRand(int $min, int $max): void
    {
        // THE ROW HAS TO BE FORCED INTO EXISTENCE FIRST. The test database is built by
        // doctrine:schema:create and carries no operator content, so `game_config` can be
        // genuinely empty — and then an UPDATE matches zero rows, the first request creates the
        // singleton from entity defaults (10/40), and the assertion fails against numbers the
        // seed never wrote. getConfig(flush: true) is the repository's own "create if absent".
        self::getContainer()->get(GameConfigRepository::class)->getConfig(flush: true);

        // Then straight SQL: the setters floor at 1, which would hide the very collapse this
        // test is about if the seed went through them.
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE game_config SET contract_value_rand_min = :min, contract_value_rand_max = :max',
            ['min' => $min, 'max' => $max],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function loginAsAdmin(): void
    {
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $this->client->loginUser($admin, 'admin');
    }
}
