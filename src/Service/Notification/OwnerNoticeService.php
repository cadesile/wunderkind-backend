<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Admin;
use App\Entity\AdminMessage;
use App\Entity\Club;
use App\Entity\User;
use App\Enum\MessageDisplayType;
use App\Enum\MessagePriority;
use App\Enum\MessageTargetType;
use App\Enum\UserLedgerEntryType;
use App\Repository\AdminMessageRepository;
use App\Repository\Club\ClubSaleRepository;
use App\Repository\UserDeviceRepository;
use App\Repository\UserLedgerRepository;
use App\Service\AdminMessageService;
use App\Service\Mail\BrandedEmailLayout;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * One-off notices from an operator to one owner, sent from the admin user page's Notify form
 * (UserNotifyController). Built for go-live comms: "your clubs were sold, here is your reward".
 *
 * Three channels, each optional:
 * - EMAIL, in the same branded shell as the verification mails.
 * - DEVICE MESSAGE, an AdminMessage targeted DIRECT_USER. It is polled by whichever club the
 *   owner plays next, so it still arrives when every club they had has been sold.
 * - PUSH, a short OS notification pointing at that device message (data type ADMIN_MESSAGE,
 *   which the app already answers with an immediate message poll). Push needs the device
 *   message, because tapping it has to land on something.
 *
 * The body is written as plain text: blank lines split paragraphs and lines starting "* " or
 * "- " become a bullet list. It is escaped and converted once here, so the email and the
 * device message carry the same words.
 */
class OwnerNoticeService
{
    public const TEMPLATE_GO_LIVE = 'go_live_land_ownership';

    /** A [PLACEHOLDER] still in the text means a field was not filled; refuse to send it. */
    private const PLACEHOLDER_PATTERN = '/\[[A-Z][A-Z_]{2,}\]/';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly BrandedEmailLayout $layout,
        private readonly EntityManagerInterface $em,
        private readonly AdminMessageService $adminMessageService,
        private readonly PushNotificationService $pushNotificationService,
        private readonly ClubSaleRepository $saleRepository,
        private readonly UserDeviceRepository $deviceRepository,
        private readonly AdminMessageRepository $messageRepository,
        private readonly UserLedgerRepository $ledgerRepository,
        private readonly string $mailerFrom,
        private readonly string $mailerFromName,
    ) {}

    /**
     * The prefilled form: the go-live template with the owner's own values substituted where
     * the server knows them, and each value also returned on its own so the form can show
     * where it came from.
     *
     * @param list<Club> $clubs the account's current clubs (unused by the go-live template,
     *        kept so a later template can name them)
     * @return array{subject: string, body: string, pushBody: string, values: array<string, array{value: ?string, source: string}>}
     */
    public function defaultsFor(User $user, array $clubs): array
    {
        $values = [
            'OWNER_NAME'                  => $this->ownerName($user),
            'LIST_CLUB_SALE_LEDGER_ITEMS' => $this->clubSaleLedgerItems($user),
            'BALANCE_AMOUNT'              => $this->ownerBalance($user),
        ];

        $fill = static function (string $text) use ($values): string {
            foreach ($values as $key => $v) {
                if ($v['value'] !== null) {
                    $text = str_replace('[' . $key . ']', $v['value'], $text);
                }
            }

            return $text;
        };

        return [
            'subject'  => 'New Era: Land Ownership & Chairman Compensation',
            'body'     => $fill(self::goLiveBody()),
            'pushBody' => $fill('Chairman [OWNER_NAME], the Board has credited your owner account. Open Build My Club to claim your ground.'),
            'values'   => $values,
        ];
    }

    /** @return list<string> placeholders like [REWARD_AMOUNT] still present in any of the texts */
    public function unfilledPlaceholders(string ...$texts): array
    {
        $found = [];

        foreach ($texts as $text) {
            if (preg_match_all(self::PLACEHOLDER_PATTERN, $text, $m)) {
                array_push($found, ...$m[0]);
            }
        }

        return array_values(array_unique($found));
    }

    public function sendEmail(User $user, string $subject, string $body, ?string $overrideTo = null): string
    {
        $to = $overrideTo ?? $user->getEmail();

        $this->mailer->send(
            (new Email())
                ->from(new Address($this->mailerFrom, $this->mailerFromName))
                ->to($to)
                ->subject($subject)
                ->html($this->layout->render(
                    htmlspecialchars($subject, ENT_QUOTES),
                    $this->toHtml($body, emailStyles: true),
                    "<span style='color:#9bb0c4;font-size:8px'>You are receiving this because you have a Build My Club account.</span>",
                ))
                ->text($body),
        );

        return $to;
    }

    /**
     * Creates the device message and, when asked, pushes it. Returns the message so the caller
     * can report what was sent.
     */
    public function sendDeviceMessage(
        User $user,
        string $title,
        string $body,
        MessageDisplayType $displayType,
        bool $push,
        string $pushBody,
        ?Admin $author,
    ): AdminMessage {
        $message = (new AdminMessage())
            ->setTitle(mb_substr($title, 0, 150))
            ->setBodyHtml($this->adminMessageService->sanitize($this->toHtml($body, emailStyles: false)))
            ->setTargetType(MessageTargetType::DIRECT_USER)
            ->setTargetUser($user)
            ->setDisplayType($displayType)
            ->setPriority(MessagePriority::URGENT)
            ->setSendAsPush($push)
            ->setIsActive(true)
            ->setCreatedBy($author);

        if ($push) {
            $message->markPushSent(new \DateTimeImmutable());
        }

        $this->em->persist($message);
        $this->em->flush();

        if ($push) {
            $this->pushNotificationService->notifyUsers(
                [(string) $user->getId()],
                $message->getTitle(),
                $pushBody,
                ['type' => 'ADMIN_MESSAGE', 'adminMessageId' => (string) $message->getId()],
            );
        }

        return $message;
    }

    public function countPushDevices(User $user): int
    {
        return count($this->deviceRepository->findByUserIds([(string) $user->getId()]));
    }

    /** @return list<AdminMessage> device messages already sent to this account, newest first */
    public function previousNotices(User $user): array
    {
        return $this->messageRepository->findBy(
            ['targetType' => MessageTargetType::DIRECT_USER, 'targetUser' => $user],
            ['createdAt' => 'DESC'],
            10,
        );
    }

    /**
     * Plain text to the small HTML subset the device message sanitizer allows (p, ul, li,
     * strong, br). A bullet's leading "Label:" is bolded, matching how the template reads.
     */
    public function toHtml(string $text, bool $emailStyles): string
    {
        $pStyle  = $emailStyles ? " style='color:#e8f0f4;font-size:9px;line-height:2;margin:0 0 12px'" : '';
        $ulStyle = $emailStyles ? " style='color:#e8f0f4;font-size:9px;line-height:2;margin:0 0 12px;padding-left:18px'" : '';
        $liStyle = $emailStyles ? " style='margin:0 0 6px'" : '';

        $text   = str_replace(["\r\n", "\r"], "\n", trim($text));
        $blocks = preg_split('/\n\s*\n/', $text) ?: [];
        $html   = '';

        foreach ($blocks as $block) {
            $lines   = array_values(array_filter(array_map('trim', explode("\n", $block)), static fn ($l) => $l !== ''));
            $para    = [];
            $bullets = [];

            $flushPara = function () use (&$para, &$html, $pStyle): void {
                if ($para !== []) {
                    $html .= "<p{$pStyle}>" . implode('<br>', $para) . '</p>';
                    $para = [];
                }
            };
            $flushBullets = function () use (&$bullets, &$html, $ulStyle, $liStyle): void {
                if ($bullets !== []) {
                    $html .= "<ul{$ulStyle}>" . implode('', array_map(static fn ($b) => "<li{$liStyle}>{$b}</li>", $bullets)) . '</ul>';
                    $bullets = [];
                }
            };

            foreach ($lines as $line) {
                if (preg_match('/^[*\-•]\s+(.*)$/u', $line, $m)) {
                    $flushPara();
                    $bullets[] = $this->bulletHtml($m[1]);
                    continue;
                }

                $flushBullets();
                $para[] = htmlspecialchars($line, ENT_QUOTES);
            }

            $flushPara();
            $flushBullets();
        }

        return $html;
    }

    private function bulletHtml(string $item): string
    {
        // "Claim Your Ground: Choose your nation..." -> bold label. Only a short leading label
        // counts, so a sentence that merely contains a colon is left alone.
        if (preg_match('/^([^:]{1,40}):\s*(.*)$/u', $item, $m)) {
            return '<strong>' . htmlspecialchars($m[1], ENT_QUOTES) . ':</strong> ' . htmlspecialchars($m[2], ENT_QUOTES);
        }

        return htmlspecialchars($item, ENT_QUOTES);
    }

    /** @return array{value: ?string, source: string} */
    private function ownerName(User $user): array
    {
        $name = trim((string) $user->getName());

        return $name !== ''
            ? ['value' => $name, 'source' => 'Owner profile name']
            : ['value' => null, 'source' => 'No owner name on the profile'];
    }

    /**
     * The account's club-sale credits as they appear in its owner ledger, written inline:
     * "£1,000.00 for Milano Vigor and £500.00 for FC Milano". The club name comes from the
     * sale row the entry points at (sourceRef), because the ledger entry itself is attributed
     * to no club; the entry description is the fallback.
     *
     * @return array{value: ?string, source: string}
     */
    private function clubSaleLedgerItems(User $user): array
    {
        $entries = $this->ledgerRepository->findBy(
            ['user' => $user, 'type' => UserLedgerEntryType::CLUB_SALE],
            ['occurredAt' => 'ASC'],
        );

        if ($entries === []) {
            return ['value' => null, 'source' => 'No club sale in the owner ledger yet'];
        }

        $salesById = [];
        foreach ($this->saleRepository->findForUser($user) as $sale) {
            $salesById[(string) $sale->getId()] = $sale;
        }

        $items = [];
        foreach ($entries as $entry) {
            $sale  = $salesById[(string) $entry->getSourceRef()] ?? null;
            // ClubSaleService's default description is "Club sale — {name}"; keep just the name.
            $label = $sale?->getClubName()
                ?? preg_replace('/^Club sale\s*[—-]\s*/u', '', (string) $entry->getDescription())
                ?: 'a club sale';
            $items[] = self::money($entry->getAmountPence()) . ' for ' . $label;
        }

        return [
            'value'  => self::joinNames($items),
            'source' => sprintf('%d club sale ledger item%s', count($items), count($items) === 1 ? '' : 's'),
        ];
    }

    /**
     * Current owner credit (latest ledger balance). Read when the page renders, so reload the
     * page after a sale or adjustment before sending.
     *
     * @return array{value: ?string, source: string}
     */
    private function ownerBalance(User $user): array
    {
        return [
            'value'  => self::money($this->ledgerRepository->getCurrentBalance($user)),
            'source' => 'Owner ledger balance now',
        ];
    }

    private static function money(int $pence): string
    {
        return ($pence < 0 ? '-£' : '£') . number_format(abs($pence) / 100, 2);
    }

    /** @param list<string> $names */
    private static function joinNames(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }

    private static function goLiveBody(): string
    {
        return <<<'TXT'
Chairman [OWNER_NAME],

Football has changed. In the new version of Build My Club, you no longer just inherit a badge—you buy the ground beneath it.

Because you backed us early, we have deposited [LIST_CLUB_SALE_LEDGER_ITEMS] directly into your personal owner credit. Balance: [BALANCE_AMOUNT]

How Land Ownership Works:

* Claim Your Ground: Choose your nation and town. Major cities hold multiple clubs; villages hold just one. Once a town's plots are taken, it's sold out.
* Owner Capital: Plots are bought with your personal owner credit, not club funds. Profits from one club can bankroll land in a bigger city—up to two plots across your save slots.
* Survive Liquidation: The land belongs to you, not the save. If your club collapses and the fans turn on you, the club is liquidated, but you keep the deed to start again.
* Stay Active: Sync your club at least once every 30 days (or build on an empty plot within 30 days of liquidation) to keep your land. Go quiet, or sell up voluntarily, and the plot is bought back at today's revalued land price plus your club's value.

We hope to welcome you back!
TXT;
    }
}
