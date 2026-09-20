<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationLogStatus;
use App\Repository\NotificationLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Audit record of one push-notification message actually processed by the async Messenger
 * transport — one row per `SendPushNotificationMessage`/`ResolveAdminMessageAudienceForPushMessage`
 * handled, success or failure. Written by `NotificationLoggingSubscriber`, listening to
 * `WorkerMessageHandledEvent`/`WorkerMessageFailedEvent` — deliberately not written by the
 * handlers themselves, since that event pair is the only place that uniformly catches both a
 * handler's own thrown exception AND a handler-construction failure (e.g. the
 * `FIREBASE_SERVICE_ACCOUNT_JSON`-missing bug this table exists to make visible, which throws
 * before `SendPushNotificationMessageHandler`'s own code ever runs).
 *
 * This is a visibility record, not a delivery guarantee — pair with the `failed` Messenger
 * transport (`config/packages/messenger.yaml`) for actually retrying a transient failure by hand.
 */
#[ORM\Entity(repositoryClass: NotificationLogRepository::class)]
#[ORM\Table(name: 'notification_log')]
#[ORM\Index(columns: ['created_at'], name: 'idx_notification_log_created_at')]
class NotificationLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'string', length: 20, enumType: NotificationLogStatus::class)]
    private NotificationLogStatus $status;

    /** Short class name of the message handled — `SendPushNotificationMessage` or `ResolveAdminMessageAudienceForPushMessage`. */
    #[ORM\Column(type: 'string', length: 100)]
    private string $messageType;

    /** Short human line for the admin list view, e.g. "Push to 2 user(s): Full-time! Oxford Harriers 0-1 Bristol Wednesday". */
    #[ORM\Column(type: 'text')]
    private string $summary;

    /**
     * Full context: the message's own public properties, plus (on failure) the exception
     * class/message/file/line.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $detailJson;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(NotificationLogStatus $status, string $messageType, string $summary, array $detailJson, ?string $errorMessage = null)
    {
        $this->id          = new UuidV7();
        $this->status      = $status;
        $this->messageType = $messageType;
        $this->summary     = $summary;
        $this->detailJson  = $detailJson;
        // Truncate rather than let a long exception message break the insert — same convention
        // as DeletionRequest::setFailureReason().
        $this->errorMessage = $errorMessage === null ? null : mb_substr($errorMessage, 0, 255);
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getStatus(): NotificationLogStatus { return $this->status; }

    public function getMessageType(): string { return $this->messageType; }

    public function getSummary(): string { return $this->summary; }

    public function getDetailJson(): array { return $this->detailJson; }

    /** Read-only virtual accessor for the admin detail view. */
    public function getDetailJsonPretty(): string
    {
        return json_encode($this->detailJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function getErrorMessage(): ?string { return $this->errorMessage; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
