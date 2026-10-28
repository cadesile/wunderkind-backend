<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Club;
use App\Entity\Friendly\FriendlyChallenge;
use App\Entity\Friendly\FriendlyChallengeAudit;
use App\Entity\User;
use App\Enum\Friendly\FriendlyChallengeStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Tests\Support\FriendlyFixtureTrait;

/**
 * The read-only "view challenges" admin, loaded against real persisted rows rather than
 * reviewed in the abstract — this repo has caught EasyAdmin rendering failures (missing
 * __toString on an association, an array-valued json column handed to a Text field) only by
 * actually requesting the pages. Both lifecycle shapes are covered because they render
 * differently: an OPEN challenge has no acceptor and no score, a COMPLETE one has both plus
 * eight json blobs on its detail page.
 */
class FriendlyChallengeCrudPageTest extends WebTestCase
{
    use FriendlyFixtureTrait;

    private const TEST_ADMIN_EMAIL = 'friendly-challenge-crud-test-admin@example.com';

    private function loginAsAdmin(KernelBrowser $client): void
    {
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    private function club(EntityManagerInterface $em, string $name): Club
    {
        $user = new User(sprintf('friendly-admin-%s@example.com', uniqid('', true)));
        $user->setPassword('x');
        $club = new Club($name, $user);

        $em->persist($user);
        $em->persist($club);

        return $club;
    }

    private function seedOpenChallenge(EntityManagerInterface $em): FriendlyChallenge
    {
        $challenge = $this->makeOpenChallenge(
            $em,
            $this->club($em, 'Challenger Rovers'),
            $this->friendlyEnvelope('Challenger Rovers'),
            stakePence: 25_000,
        );

        $em->flush();

        return $challenge;
    }

    private function seedCompleteChallenge(EntityManagerInterface $em): FriendlyChallenge
    {
        $challenge = $this->seedOpenChallenge($em);
        $acceptor  = $this->club($em, 'Acceptor Athletic');

        $this->acceptChallengeFixture($em, $challenge, $acceptor, $this->friendlyEnvelope('Acceptor Athletic'));
        $challenge->recordResult(
            3,
            1,
            ['name' => 'Challenger Rovers'],
            ['name' => 'Acceptor Athletic'],
            [['id' => 'p1', 'position' => 'ST', 'rating' => 8.4]],
            [['id' => 'p2', 'position' => 'GK', 'rating' => 5.9]],
            ['timeline' => [['minute' => 12, 'text' => 'A fine finish.']]],
            new \DateTimeImmutable('-10 minutes'),
        );

        // The event log is admin-only and lives in its own row now, so the detail page can
        // only render it if one was actually written.
        $em->persist(new FriendlyChallengeAudit($challenge, [['minute' => 12, 'type' => 'GOAL']]));

        $em->flush();

        return $challenge;
    }

    public function testIndexRendersBothClubsTheStatusAndTheScore(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedCompleteChallenge($em);

        $crawler = $client->request('GET', '/admin/friendly-challenge');
        self::assertResponseIsSuccessful();

        $text = $crawler->text();
        $this->assertStringContainsString('Challenger Rovers', $text);
        $this->assertStringContainsString('Acceptor Athletic', $text, 'Club::__toString() must give the AssociationField a real label.');
        $this->assertStringContainsString('3 – 1', $text, 'The virtual score column needs its placeholder setValue() or formatValue() is skipped entirely.');
    }

    public function testIndexRendersAnOpenChallengeWithNoAcceptorOrScore(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedOpenChallenge($em);

        $crawler = $client->request('GET', '/admin/friendly-challenge');
        self::assertResponseIsSuccessful();

        // The half-populated row is the one that breaks: null acceptor association, null scores.
        $this->assertStringContainsString('Challenger Rovers', $crawler->text());
    }

    public function testDetailRendersTheResultPayloads(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em        = self::getContainer()->get(EntityManagerInterface::class);
        $challenge = $this->seedCompleteChallenge($em);

        $crawler = $client->request('GET', '/admin/friendly-challenge/' . $challenge->getId());
        self::assertResponseIsSuccessful();

        $text = $crawler->text();
        $this->assertStringContainsString('"rating": 8.4', $text, 'Lineup json must be pretty-printed, not rendered as "Array".');
        $this->assertStringContainsString('A fine finish.', $text);
        $this->assertStringContainsString((string) $challenge->getChallengerClubId(), $text, 'The UUID columns outlive a deleted club and must be visible.');
    }

    public function testDetailRendersAnOpenChallengeWhoseResultBlobsAreAllNull(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em        = self::getContainer()->get(EntityManagerInterface::class);
        $challenge = $this->seedOpenChallenge($em);

        $client->request('GET', '/admin/friendly-challenge/' . $challenge->getId());
        self::assertResponseIsSuccessful();
    }

    public function testJsonBlobsAreEscapedRatherThanRenderedAsMarkup(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Squad/lineup/narrative json is client-supplied, and a string returned from
        // formatValue() is auto-wrapped in Twig\Markup and rendered unescaped — so the
        // formatter escapes before wrapping. Without that, this club name would inject a tag.
        $challenge = new FriendlyChallenge(
            $this->club($em, 'Escaping FC'),
            'away',
            0,
            60,
            100,
            'amateur',
            ['club' => ['name' => '<script>alert(1)</script>']],
            new \DateTimeImmutable('+1 day'),
        );
        $em->persist($challenge);
        $em->flush();

        $client->request('GET', '/admin/friendly-challenge/' . $challenge->getId());
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testTheScreenIsReadOnly(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em        = self::getContainer()->get(EntityManagerInterface::class);
        $challenge = $this->seedOpenChallenge($em);

        $client->request('GET', '/admin/friendly-challenge/new');
        self::assertResponseStatusCodeSame(403, 'NEW must be disabled — challenges are posted by clubs, never by an admin.');

        $client->request('GET', '/admin/friendly-challenge/' . $challenge->getId() . '/edit');
        self::assertResponseStatusCodeSame(403, 'EDIT must be disabled — FriendlyChallengeService owns the lifecycle, including the resolve claim-lock.');
    }

    public function testStatusFilterNarrowsTheList(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedCompleteChallenge($em);
        $this->seedOpenChallenge($em);

        $crawler = $client->request('GET', '/admin/friendly-challenge', [
            'filters' => ['status' => ['comparison' => '=', 'value' => FriendlyChallengeStatus::COMPLETE->value]],
        ]);
        self::assertResponseIsSuccessful();

        $this->assertStringContainsString('Acceptor Athletic', $crawler->text(), 'The completed challenge must survive its own filter.');
    }
}
