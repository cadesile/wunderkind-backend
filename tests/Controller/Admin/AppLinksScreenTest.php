<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Repository\GameConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Config → App links.
 *
 * THE PAGE HAD NO TESTS AT ALL before the rebuild, which for a form of seven fields posting to
 * one handler is the worst case: the two sides are joined by nothing but a string, and a name
 * that stops matching fails silently — the form submits, the handler redirects, get() returns
 * null, and that link keeps whatever it had. The field census is the point of this file.
 *
 * GameConfig IS A SINGLETON ROW shared by the whole run, so the save test records every value
 * it finds and puts them all back afterwards.
 */
class AppLinksScreenTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'app-links-screen@example.com';

    /** Every field name saveAppLinks() reads. */
    private const FIELDS = [
        'androidDownloadUrl',
        'iosDownloadUrl',
        'facebookPageUrl',
        'xProfileUrl',
        'betaRequestEmail',
        'recaptchaSiteKey',
        'recaptchaSecretKey',
    ];

    private KernelBrowser $client;

    /** @var array<string, ?string> */
    private array $original = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $this->client->loginUser($admin, 'admin');

        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();
        foreach (self::FIELDS as $f) {
            $this->original[$f] = $config->{'get' . ucfirst($f)}();
        }
    }

    protected function tearDown(): void
    {
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();
        foreach ($this->original as $f => $v) {
            $config->{'set' . ucfirst($f)}($v);
        }
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        parent::tearDown();
    }

    private function open(): Crawler
    {
        $crawler = $this->client->request('GET', '/admin', ['routeName' => 'admin_app_links']);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    public function testEveryFieldTheSaveHandlerReadsIsOnThePage(): void
    {
        $crawler = $this->open();

        foreach (self::FIELDS as $name) {
            self::assertCount(
                1,
                $crawler->filter(\sprintf('input[name="%s"]', $name)),
                "the form no longer offers {$name}, so saving it would silently do nothing",
            );
        }
    }

    /**
     * BLANK IS A VALUE HERE, NOT AN OMISSION. An empty field is what hides a download button, a
     * social link or the CAPTCHA widget — so every field has to carry data-blank="ok", or the
     * form layer marks the page invalid and disables saving the moment one is cleared.
     */
    public function testBlankIsAllowedOnEveryField(): void
    {
        $crawler = $this->open();

        foreach (self::FIELDS as $name) {
            self::assertCount(
                1,
                $crawler->filter(\sprintf('input[name="%s"][data-blank="ok"][data-kind="text"]', $name)),
                "{$name} must accept blank and skip the numeric check",
            );
        }
    }

    public function testEveryFieldIsTrackedForDirtyState(): void
    {
        $crawler = $this->open();

        foreach (self::FIELDS as $name) {
            self::assertCount(
                1,
                $crawler->filter(\sprintf('input[name="%s"][data-saved]', $name)),
                "{$name} has no data-saved, so editing it would not enable the save button",
            );
        }
    }

    /** The round trip the page exists for, including clearing a value back to blank. */
    public function testSavingPersistsAValueAndClearingItPersistsTheBlank(): void
    {
        $token = $this->open()->filter('input[name="_token"]')->attr('value');
        $post  = static fn (array $values): array => array_merge(['_token' => $token], $values);

        $this->client->request('POST', '/admin/app-links/save', $post([
            'androidDownloadUrl' => 'https://play.google.com/store/apps/details?id=test',
            'iosDownloadUrl'     => '',
            'facebookPageUrl'    => '',
            'xProfileUrl'        => '',
            'betaRequestEmail'   => 'beta-test@example.com',
            'recaptchaSiteKey'   => '',
            'recaptchaSecretKey' => '',
        ]));
        self::assertResponseRedirects();

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();

        self::assertSame('https://play.google.com/store/apps/details?id=test', $config->getAndroidDownloadUrl());
        self::assertSame('beta-test@example.com', $config->getBetaRequestEmail());

        // And back to blank, which is the state that HIDES the button rather than leaving the
        // old URL in place — the failure mode worth a test.
        $this->client->request('POST', '/admin/app-links/save', $post([
            'androidDownloadUrl' => '',
            'iosDownloadUrl'     => '',
            'facebookPageUrl'    => '',
            'xProfileUrl'        => '',
            'betaRequestEmail'   => '',
            'recaptchaSiteKey'   => '',
            'recaptchaSecretKey' => '',
        ]));
        self::assertResponseRedirects();

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();

        self::assertEmpty($config->getAndroidDownloadUrl(), 'clearing a link must actually clear it');
    }

    public function testThePageRendersInTheRedesignedShell(): void
    {
        $this->open();

        self::assertSelectorTextContains('.bmc-title', 'APP LINKS');
        self::assertSelectorTextContains('.bmc-crumb', 'CONFIG');
        self::assertSelectorExists('.bmc-page');
        self::assertSelectorNotExists('.card-body', 'no Bootstrap cards should survive the rebuild');
        self::assertSelectorNotExists('.form-control', 'no Bootstrap inputs either');
    }

    /** A target=_blank link without rel=noopener lets the opened page reach back. */
    public function testOutboundLinksCarryNoopener(): void
    {
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();
        $config->setAndroidDownloadUrl('https://play.google.com/store/apps/details?id=noopener');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $this->open();
        $links   = $crawler->filter('a[target="_blank"]');

        self::assertGreaterThan(0, $links->count());
        $links->each(static function (Crawler $a): void {
            self::assertStringContainsString('noopener', (string) $a->attr('rel'));
        });
    }
}
