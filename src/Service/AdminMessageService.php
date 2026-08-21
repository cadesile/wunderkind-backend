<?php

namespace App\Service;

use App\Entity\AdminMessage;
use App\Entity\Club;
use App\Entity\User;
use App\Enum\AudienceCriteriaType;
use App\Enum\MessageDeliveryStatus;
use App\Enum\MessageDisplayType;
use App\Enum\MessageTargetType;
use App\Repository\AdminMessageRepository;
use App\Repository\AudienceGroupMemberRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Resolves which announcements a club should see, and records that it saw them.
 */
class AdminMessageService
{
    /**
     * At most one blocking modal per response — the "pop-up fatigue" rule, enforced
     * server-side so a misconfigured campaign cannot stack modals on a player.
     */
    public const MAX_BLOCKING = 1;

    /** Plus up to five non-blocking messages, which the client routes to its inbox. */
    public const MAX_NON_BLOCKING = 5;

    public function __construct(
        private readonly AdminMessageRepository $messageRepository,
        private readonly AudienceGroupMemberRepository $memberRepository,
        private readonly AudienceCriteriaEvaluator $criteriaEvaluator,
        private readonly Connection $connection,
        private readonly HtmlSanitizerInterface $adminMessage,
    ) {}

    /**
     * Undelivered messages for this club, priority-ordered and capped.
     *
     * @return AdminMessage[]
     */
    public function findPendingForClub(Club $club): array
    {
        $candidates = $this->messageRepository->findCandidatesForClub($club);

        $blocking    = [];
        $nonBlocking = [];

        // $candidates is already ordered priority DESC, createdAt ASC, so the first blocking
        // message we meet is the one that wins.
        foreach ($candidates as $message) {
            if (!$this->isEligible($message, $club)) {
                continue;
            }

            if ($message->getDisplayType() === MessageDisplayType::MODAL_BLOCKING) {
                if (count($blocking) < self::MAX_BLOCKING) {
                    $blocking[] = $message;
                }

                continue;
            }

            if (count($nonBlocking) < self::MAX_NON_BLOCKING) {
                $nonBlocking[] = $message;
            }
        }

        return array_merge($blocking, $nonBlocking);
    }

    /**
     * Records an acknowledgement against the USER, not one of their clubs, so a person sees an
     * announcement once however many clubs they start. Guests and registered accounts are both
     * plain User rows and go through this identically.
     *
     * Written as a PostgreSQL upsert rather than a read-then-write through the ORM: the client
     * acks on render AND on dismiss, so two acks for the same (user, message) routinely race.
     * ON CONFLICT makes the unique constraint the arbiter atomically. Catching
     * UniqueConstraintViolationException from a flush() would not work here — Doctrine closes
     * the EntityManager on a failed flush, so there is no recovering inside the same request.
     *
     * The `WHERE ... status <> 'dismissed'` clause keeps DISMISSED terminal: a late "displayed"
     * ack must not walk the row back to a weaker state. This mirrors
     * MessageDelivery::acknowledge(), which enforces the same rule at the entity level.
     */
    public function acknowledge(User $user, AdminMessage $message, MessageDeliveryStatus $status): void
    {
        $now = new \DateTimeImmutable();

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO message_delivery (id, user_id, message_id, delivered_at, displayed_at, status)
            VALUES (:id, :user, :message, :now, :displayedAt, :status)
            ON CONFLICT (user_id, message_id) DO UPDATE
                SET status       = EXCLUDED.status,
                    displayed_at = COALESCE(message_delivery.displayed_at, EXCLUDED.displayed_at)
                WHERE message_delivery.status <> :dismissed
            SQL,
            [
                'id'          => (new \Symfony\Component\Uid\UuidV7())->toRfc4122(),
                'user'        => $user->getId()->toRfc4122(),
                'message'     => $message->getId()->toRfc4122(),
                'now'         => $now->format('Y-m-d H:i:s'),
                'displayedAt' => $status === MessageDeliveryStatus::PENDING
                    ? null
                    : $now->format('Y-m-d H:i:s'),
                'status'      => $status->value,
                'dismissed'   => MessageDeliveryStatus::DISMISSED->value,
            ],
        );
    }

    /**
     * Strips everything outside the allowlist in config/packages/html_sanitizer.yaml. Called
     * from AdminMessageCrudController so the column only ever holds clean markup — the single
     * chokepoint where untrusted HTML could enter.
     */
    public function sanitize(string $html): string
    {
        return $this->adminMessage->sanitize($html);
    }

    /**
     * Confirms group targeting in PHP.
     *
     * The candidate query proves that AT LEAST ONE of the message's groups qualified, but not
     * which. A message carrying both a manual group this club is not in and a dynamic group is
     * returned by that query on the dynamic branch alone — so each group must be re-checked on
     * its own terms here, manual ones included.
     */
    private function isEligible(AdminMessage $message, Club $club): bool
    {
        // Broadcast and direct targeting were fully resolved in SQL.
        if ($message->getTargetType() !== MessageTargetType::GROUP_SEGMENTED) {
            return true;
        }

        foreach ($message->getAudienceGroups() as $group) {
            $matched = $group->getCriteriaType() === AudienceCriteriaType::DYNAMIC
                ? $this->criteriaEvaluator->matches($group, $club)
                : $this->memberRepository->isMember($club, $group);

            // Any one matching group is enough.
            if ($matched) {
                return true;
            }
        }

        return false;
    }
}
