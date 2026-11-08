<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The starter-config screen itself: that it renders in the redesigned shell, and — the part
 * worth the most — that it still offers every field the save handler reads.
 *
 * WHY THE FIELD CENSUS. This page is one form of ~25 named fields posting to one handler, and
 * the two sides reference each other through nothing but a string. A rename, or a field lost
 * while moving markup between cards, fails completely silently: the form still submits, the
 * handler still redirects, `$request->request->get('x')` just returns null and that setting
 * quietly keeps its old value. StarterConfigEnrichmentCapTest makes exactly that argument for
 * one field; this makes it for all of them, which is what the rebuild needed.
 *
 * The list is written out rather than scraped from the controller on purpose: a test that
 * derives its expectations from the code under test agrees with that code by construction.
 */
class StarterConfigScreenTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'starter-config-screen@example.com';

    /** Every scalar field name saveStarterConfig() reads. */
    private const SCALAR_FIELDS = [
        'startingBalance',
        'userStartingBalanceMinPounds',
        'userStartingBalanceMaxPounds',
        'maxEnrichmentRequestsPerUser',
        'quizBonusMultiplier',
        'clubFundingMinimumPounds',
        'clubFundingProjectionWeeks',
        'starterSponsorTier',
        'starterPlayerCount',
        'starterManagerCount',
        'starterCoachCount',
        'starterScoutCount',
        'starterDirectorOfFootballCount',
        'starterFacilityManagerCount',
        'starterChairmanCount',
        'starterReputationTier',
        'starterClubTier',
        'defaultFacilities',
        'setupTutorialConfig',
        'fanBasePromotionIncrease',
        'fanBaseRelegationDecrease',
    ];

    private function loginAsAdmin(KernelBrowser $client): void
    {
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    private function open(): KernelBrowser
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $client->request('GET', '/admin?routeName=admin_starter_config');
        self::assertResponseIsSuccessful();

        return $client;
    }

    public function testEveryFieldTheSaveHandlerReadsIsStillOnThePage(): void
    {
        $client  = $this->open();
        $crawler = $client->getCrawler();

        foreach (self::SCALAR_FIELDS as $name) {
            self::assertGreaterThan(
                0,
                $crawler->filter(\sprintf('[name="%s"]', $name))->count(),
                "the form no longer offers {$name}, so saving it would silently do nothing",
            );
        }

        // The three array-shaped groups. Asserted by prefix because their keys are per tier and
        // per country, which vary with the seeded data.
        foreach (['npcConfig', 'leagueRanges', 'fanBaseRanges'] as $prefix) {
            self::assertGreaterThan(
                0,
                $crawler->filter(\sprintf('[name^="%s["]', $prefix))->count(),
                "the form no longer offers any {$prefix}[…] fields",
            );
        }
    }

    /** All of it posts to one handler, so there must be exactly one form and one save bar. */
    public function testItIsASingleFormWithOnePageLevelSaveBar(): void
    {
        $crawler = $this->open()->getCrawler();

        self::assertCount(1, $crawler->filter('form[data-bmc-form]'));
        self::assertCount(1, $crawler->filter('[data-bmc-savebar]'));
        self::assertCount(
            0,
            $crawler->filter('.bmc-savebar--inline'),
            'one form means the page-level sticky bar, not an inline one',
        );
    }

    /**
     * The selects and the JSON editors declare data-kind="text".
     *
     * WITHOUT IT THE PAGE CANNOT BE SAVED. bmc-pages.js disables [data-bmc-save] while nothing
     * is dirty, and its field check runs an integer validator by default — so an untagged
     * <select> reports "Whole number" on a value of "MEDIUM" and pins the bar to invalid. The
     * decimal multipliers have the same problem with their own kind.
     */
    public function testNonNumericFieldsDeclareTheirKind(): void
    {
        $crawler = $this->open()->getCrawler();

        foreach (['starterSponsorTier', 'starterReputationTier', 'starterClubTier', 'defaultFacilities', 'setupTutorialConfig'] as $name) {
            self::assertCount(
                1,
                $crawler->filter(\sprintf('[name="%s"][data-kind="text"]', $name)),
                "{$name} must be data-kind=\"text\" or the numeric check will reject it",
            );
        }

        foreach (['quizBonusMultiplier', 'fanBasePromotionIncrease', 'fanBaseRelegationDecrease'] as $name) {
            self::assertCount(
                1,
                $crawler->filter(\sprintf('[name="%s"][data-kind="decimal"]', $name)),
                "{$name} steps in fractions, so the integer check would reject every valid value",
            );
        }
    }

    /** Dirty tracking is keyed on data-saved, so a field without one never registers a change. */
    public function testEveryTrackedFieldCarriesItsSavedValue(): void
    {
        $crawler = $this->open()->getCrawler();

        foreach (self::SCALAR_FIELDS as $name) {
            self::assertCount(
                1,
                $crawler->filter(\sprintf('[name="%s"][data-saved]', $name)),
                "{$name} has no data-saved, so editing it would not mark the form dirty",
            );
        }
    }

    public function testThePageRendersInTheRedesignedShell(): void
    {
        $this->open();

        self::assertSelectorTextContains('.bmc-title', 'STARTER CONFIG');
        self::assertSelectorTextContains('.bmc-crumb', 'CONFIG');
        self::assertSelectorExists('.bmc-page');
        self::assertSelectorNotExists('.card-body', 'no Bootstrap cards should survive the rebuild');
        self::assertSelectorNotExists('.form-control', 'no Bootstrap inputs either');
    }
}
