<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\NotificationLog;
use App\Enum\NotificationLogStatus;
use App\EventSubscriber\NotificationLoggingSubscriber;
use App\Message\ResolveAdminMessageAudienceForPushMessage;
use App\Message\SendPushNotificationMessage;
use App\Repository\NotificationLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Regression coverage for the invisible-failure bug that motivated this subscriber: a handler
 * that throws during *construction* (e.g. Kreait's Messaging service failing because
 * FIREBASE_SERVICE_ACCOUNT_JSON is missing/malformed) still only ever surfaces as a plain
 * WorkerMessageFailedEvent — so this subscriber must record it the same way it would record any
 * other handler-body exception.
 */
class NotificationLoggingSubscriberTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private NotificationLogRepository $repo;
    private NotificationLoggingSubscriber $subscriber;
    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo       = self::getContainer()->get(NotificationLogRepository::class);
        $this->subscriber = new NotificationLoggingSubscriber($this->em);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $id) {
            $managed = $this->em->find(NotificationLog::class, $id);
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    public function testSuccessfullyHandledPushMessageIsLoggedAsSuccess(): void
    {
        $message = new SendPushNotificationMessage(['user-1', 'user-2'], 'Full-time!', 'Home 1-0 Away', ['type' => 'MATCH_RESULT']);
        $event   = new WorkerMessageHandledEvent(new Envelope($message), 'async');

        $this->subscriber->onMessageHandled($event);

        $log = $this->latestLogFor($message);
        $this->cleanup[] = (string) $log->getId();

        self::assertSame(NotificationLogStatus::SUCCESS, $log->getStatus());
        self::assertSame('SendPushNotificationMessage', $log->getMessageType());
        self::assertStringContainsString('Full-time!', $log->getSummary());
        self::assertNull($log->getErrorMessage());
        self::assertSame(['user-1', 'user-2'], $log->getDetailJson()['userIds']);
    }

    public function testHandlerConstructionFailureIsLoggedAsFailed(): void
    {
        // Simulates the exact bug class this subscriber exists to catch: an exception raised
        // before the handler's own body ever runs (e.g. DI failing to build the Messaging
        // service because the Firebase service-account JSON is empty/malformed).
        $message   = new SendPushNotificationMessage(['user-1'], '[TEST] Round drawn', 'Debug trigger', ['type' => 'ROUND_DRAWN']);
        $throwable = new \JsonException('Syntax error');
        $event     = new WorkerMessageFailedEvent(new Envelope($message), 'async', $throwable);

        $this->subscriber->onMessageFailed($event);

        $log = $this->latestLogFor($message);
        $this->cleanup[] = (string) $log->getId();

        self::assertSame(NotificationLogStatus::FAILED, $log->getStatus());
        self::assertSame('Syntax error', $log->getErrorMessage());
        self::assertSame(\JsonException::class, $log->getDetailJson()['exception']['class']);
    }

    public function testResolveAdminMessageAudienceMessageIsLoggedWithItsOwnSummary(): void
    {
        $message = new ResolveAdminMessageAudienceForPushMessage('admin-message-42');
        $event   = new WorkerMessageHandledEvent(new Envelope($message), 'async');

        $this->subscriber->onMessageHandled($event);

        $log = $this->latestLogFor($message);
        $this->cleanup[] = (string) $log->getId();

        self::assertSame('ResolveAdminMessageAudienceForPushMessage', $log->getMessageType());
        self::assertStringContainsString('admin-message-42', $log->getSummary());
    }

    public function testUnrelatedMessageTypesAreNotLogged(): void
    {
        $before  = $this->repo->count([]);
        $message = new \stdClass();
        $event   = new WorkerMessageHandledEvent(new Envelope($message), 'async');

        $this->subscriber->onMessageHandled($event);

        self::assertSame($before, $this->repo->count([]));
    }

    private function latestLogFor(object $message): NotificationLog
    {
        $shortName = (function () use ($message): string {
            $parts = explode('\\', $message::class);

            return end($parts);
        })();

        $logs = $this->repo->findBy(['messageType' => $shortName], ['createdAt' => 'DESC'], 1);
        self::assertNotEmpty($logs, sprintf('Expected a NotificationLog row for %s to have been persisted', $shortName));

        return $logs[0];
    }
}
