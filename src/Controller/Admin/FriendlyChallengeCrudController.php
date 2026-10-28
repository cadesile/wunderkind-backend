<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Friendly\FriendlyChallenge;
use App\Enum\Friendly\FriendlyChallengeStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use App\Enum\Friendly\FriendlySide;
use App\Repository\Friendly\FriendlyChallengeAuditRepository;
use App\Repository\Friendly\FriendlyChallengeSquadRepository;

/**
 * Read-only view of the friendly challenge board — one row per challenge posted by a club,
 * through its whole life: OPEN (waiting for a taker), ACCEPTED (matched, awaiting kickoff),
 * COMPLETE (played, with a score and a full result payload), CANCELLED or EXPIRED.
 *
 * Every action is disabled deliberately, same reasoning as NotificationLogCrudController and
 * DeletionRequestCrudController: a challenge is a record of what two clubs actually agreed and
 * played, and the lifecycle is owned end-to-end by FriendlyChallengeService (posting, accepting,
 * the resolve claim-lock, expiry). Hand-editing a row here would desynchronise it from the
 * clubs' own stored outcome without any of that service's guards — notably `resolveLockedAt`,
 * which exists precisely to stop two resolutions racing.
 *
 * The club columns are nullable on purpose and will show empty for a deleted club: both FKs are
 * `ON DELETE SET NULL` so a GDPR deletion can't erase the other club's match history. The
 * `challengerClubId`/`acceptorClubId` string columns on the detail page survive that deletion
 * and are the only remaining trace of who played — which is why they are shown alongside the
 * live associations rather than treated as redundant.
 */
class FriendlyChallengeCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly FriendlyChallengeSquadRepository $squadRepository,
        private readonly FriendlyChallengeAuditRepository $auditRepository,
    ) {}

    public static function getEntityFqcn(): string
    {
        return FriendlyChallenge::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // DETAIL is not a default index row action — EasyAdmin only auto-adds EDIT/DELETE, so
        // disabling those on a read-only screen leaves the actions column empty rather than
        // falling back to DETAIL. It has to be added explicitly for setDefaultRowAction() below
        // to have anything to find. Same reasoning as NotificationLogCrudController.
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Challenge')
            ->setEntityLabelInPlural('Challenges')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setDefaultRowAction(Action::DETAIL)
            ->setHelp(
                'index',
                'The friendly challenge board: every challenge a club has posted, and what became of it. '
                . 'Read-only — the lifecycle is owned by FriendlyChallengeService. '
                . 'Click a row for the full match result, both squads and the event log.',
            );
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices(self::statusChoices()))
            ->add(EntityFilter::new('challengerClub'))
            ->add(EntityFilter::new('acceptorClub'))
            ->add(NumericFilter::new('stakePence'))
            ->add(DateTimeFilter::new('createdAt'))
            ->add(DateTimeFilter::new('expiresAt'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnIndex();

        yield ChoiceField::new('status')
            ->setChoices(self::statusChoices())
            ->renderAsBadges([
                FriendlyChallengeStatus::OPEN->value      => 'info',
                FriendlyChallengeStatus::ACCEPTED->value  => 'warning',
                FriendlyChallengeStatus::COMPLETE->value  => 'success',
                FriendlyChallengeStatus::CANCELLED->value => 'secondary',
                FriendlyChallengeStatus::EXPIRED->value   => 'dark',
            ]);

        yield AssociationField::new('challengerClub', 'Challenger');
        yield AssociationField::new('acceptorClub', 'Acceptor')
            ->setHelp('Empty while the challenge is still open — nobody has taken it yet.');

        // Virtual column: needs a non-null placeholder value or CommonPreConfigurator renders
        // the "inaccessible property" template and never calls formatValue(). See CLAUDE.md.
        yield TextField::new('score')
            ->setValue('')
            ->formatValue(static function ($value, FriendlyChallenge $challenge): string {
                $home = $challenge->getHomeScore();
                $away = $challenge->getAwayScore();

                return $home === null || $away === null ? '—' : sprintf('%d – %d', $home, $away);
            })
            ->setSortable(false);

        yield TextField::new('venue')
            ->setHelp('From the poster\'s perspective: "home" means the challenger hosts.');
        yield IntegerField::new('stakePence', 'Stake (pence)');

        yield DateTimeField::new('createdAt', 'Posted')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('expiresAt', 'Expires')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('acceptedAt', 'Accepted')->setFormat('yyyy-MM-dd HH:mm')->hideOnIndex();
        yield DateTimeField::new('kickoffAt', 'Kickoff')->setFormat('yyyy-MM-dd HH:mm')->hideOnIndex();
        yield DateTimeField::new('resolvedAt', 'Resolved')->setFormat('yyyy-MM-dd HH:mm')->hideOnIndex();
        yield DateTimeField::new('resolveLockedAt', 'Resolve claimed')
            ->setFormat('yyyy-MM-dd HH:mm:ss')
            ->onlyOnDetail()
            ->setHelp('The claim-lock FriendlyChallengeService takes before resolving, so two passes can\'t both play the match.');

        // Challenger-side denormalised matchmaking fields — what the board was filtered on at
        // post time, kept so a completed challenge still shows the strength it was posted at.
        yield IntegerField::new('challengerOvr', 'Challenger OVR')->onlyOnDetail();
        yield IntegerField::new('challengerReputation', 'Challenger reputation')->onlyOnDetail();
        yield TextField::new('challengerReputationTier', 'Challenger tier')->onlyOnDetail();

        // Survive their club being deleted (both FKs are ON DELETE SET NULL) — see the class
        // docblock. Shown as plain strings, not links.
        yield TextField::new('challengerClubId', 'Challenger club UUID')->onlyOnDetail();
        yield TextField::new('acceptorClubId', 'Acceptor club UUID')->onlyOnDetail();
        yield TextField::new('challengerUserId', 'Challenger user UUID')->onlyOnDetail();
        yield TextField::new('acceptorUserId', 'Acceptor user UUID')->onlyOnDetail();

        yield $this->jsonField('challengerDisplayPretty', 'Challenger club (display block)', static fn (FriendlyChallenge $c): ?array => $c->getChallengerClubDisplayJson());
        yield $this->jsonField('acceptorDisplayPretty', 'Acceptor club (display block)', static fn (FriendlyChallenge $c): ?array => $c->getAcceptorClubDisplayJson());

        // The submitted XIs, from friendly_challenge_squad. Detail-only, like every field
        // built by jsonField(): these two are the only ones here that load extra rows, and
        // the public challenge board must never touch them.
        yield $this->jsonField(
            'challengerSquadPretty',
            'Challenger squad (XI + staff)',
            fn (FriendlyChallenge $c): ?array => $this->squadPayload($c, FriendlySide::CHALLENGER),
        );
        yield $this->jsonField(
            'acceptorSquadPretty',
            'Acceptor squad (XI + staff)',
            fn (FriendlyChallenge $c): ?array => $this->squadPayload($c, FriendlySide::ACCEPTOR),
        );
        yield $this->jsonField('homeClubPretty', 'Home club (result)', static fn (FriendlyChallenge $c): ?array => $c->getHomeClubJson());
        yield $this->jsonField('awayClubPretty', 'Away club (result)', static fn (FriendlyChallenge $c): ?array => $c->getAwayClubJson());
        yield $this->jsonField('homeLineupPretty', 'Home lineup', static fn (FriendlyChallenge $c): ?array => $c->getHomeLineupJson());
        yield $this->jsonField('awayLineupPretty', 'Away lineup', static fn (FriendlyChallenge $c): ?array => $c->getAwayLineupJson());
        // From friendly_challenge_audit. Detail-only, like the squad fields above — the
        // public board must not pull this.
        yield $this->jsonField(
            'eventLogPretty',
            'Event log',
            fn (FriendlyChallenge $c): ?array => $this->auditRepository->findForChallenge($c)?->getEventLogJson(),
        );
        yield $this->jsonField('narrativePretty', 'Narrative', static fn (FriendlyChallenge $c): ?array => $c->getNarrativePayload());
    }

    /**
     * A detail-only pretty-printed view of one `json` column.
     *
     * **Virtual field name + a placeholder setValue(), not the real property.** Binding a
     * field straight to an array-valued `json` property does not work in either obvious
     * direction: TextField's configurator rejects an array ("can't be converted into a
     * string"), and a generic Field::new() silently renders the literal text "Array" and
     * never calls formatValue() at all — measured, not assumed. So the field is given a name
     * no property matches plus a non-null placeholder (which is what stops
     * CommonPreConfigurator hitting the PropertyAccessor and bailing to the "inaccessible"
     * template — see CLAUDE.md), and $extract pulls the real array off the entity.
     *
     * The JSON is escaped before being wrapped in <pre>. TextField renders
     * `field.formattedValue|raw`, so the <pre> markup survives — and that is exactly why the
     * content inside it must be escaped here: these blobs carry client-supplied squad, lineup
     * and narrative data, which would otherwise go straight into the admin's DOM.
     *
     * @param callable(FriendlyChallenge): ?array $extract
     */
    private function jsonField(string $virtualName, string $label, callable $extract): TextField
    {
        return TextField::new($virtualName, $label)
            ->onlyOnDetail()
            ->setValue('')
            ->setSortable(false)
            ->formatValue(static function ($value, FriendlyChallenge $challenge) use ($extract): string {
                $data = $extract($challenge);

                if ($data === null || $data === []) {
                    return '—';
                }

                $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return sprintf(
                    '<pre class="mb-0" style="max-height:28rem;overflow:auto;white-space:pre-wrap;">%s</pre>',
                    htmlspecialchars($json === false ? '—' : $json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
            });
    }

    /** @return array<string, string> label => value, as EasyAdmin expects. */
    private static function statusChoices(): array
    {
        $choices = [];
        foreach (FriendlyChallengeStatus::cases() as $status) {
            $choices[ucfirst($status->value)] = $status->value;
        }

        return $choices;
    }

    /**
     * One side's stored engine inputs, shaped like the envelope it arrived as so the admin
     * view reads the way a submitted snapshot did before the split.
     *
     * @return array<string, mixed>|null
     */
    private function squadPayload(FriendlyChallenge $challenge, FriendlySide $side): ?array
    {
        $squad = $this->squadRepository->findForSide($challenge, $side);

        if ($squad === null) {
            return null;
        }

        return [
            'club'    => $squad->getClubEngineJson(),
            'players' => $squad->getPlayersJson(),
            'staff'   => $squad->getStaffJson(),
        ];
    }
}
