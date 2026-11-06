<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Admin;
use App\Entity\User;
use App\Admin\FacetCounter;
use App\Entity\UserLedger;
use App\Filter\Admin\PenceRangeFilter;
use App\Twig\BmcAdminExtension;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use App\Enum\UserLedgerEntryType;
use App\Repository\UserLedgerRepository;
use App\Service\OwnerSpotlightService;
use App\Service\UserLedgerService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only audit trail of every UserLedger entry — written by UserLedgerService, never
 * hand-edited, same reasoning as NotificationLogCrudController / DeletionRequestCrudController.
 * balanceBefore/After makes each row self-auditing: a gap or mismatch between one row's
 * balanceAfterPence and the next row's balanceBeforePence (for the same user) is a bug, not
 * expected drift.
 *
 * ONE DELIBERATE EXCEPTION, and it does not weaken the above: the "New Adjustment" form below
 * lets an operator credit or deduct an account by hand. The row it produces is still written by
 * UserLedgerService::append() — the CRUD stays read-only, NEW/EDIT/DELETE stay disabled, and
 * nothing here constructs or mutates a UserLedger.
 *
 * WHY THIS IS NOT JUST AN EASYADMIN `NEW` FORM, because it is the first thing anyone will ask.
 * UserLedger's constructor is private and `balanceAfterPence = balanceBeforePence + amountPence`
 * is computed inside it with no setter. EasyAdmin's NEW action instantiates `new Entity()` and
 * maps form input onto it, which cannot work here — and even if it could, it would take the
 * balance from the form, bypassing both the per-user advisory lock append() holds while it reads
 * the real balance and the (sourceKind, sourceRef, type) idempotency guard. Two admins acting on
 * one account would fork the balance chain. So the form posts three fields and the service does
 * the rest.
 */
class UserLedgerCrudController extends AbstractCrudController
{
    /**
     * Marks a row as hand-entered from this form.
     *
     * Deviates from the convention where sourceKind equals the type value
     * ('starting_grant', 'club_funding', …) on purpose: it distinguishes an operator's
     * adjustment from any future programmatic writer of the same type. 16 chars, and the column
     * is varchar(24).
     */
    public const SOURCE_KIND = 'admin_adjustment';

    /**
     * Largest adjustment accepted, in pence. £100,000,000.
     *
     * Not arbitrary caution: for any account that has ever drawn a dividend, this row becomes
     * their latest ledger row and therefore sets their figure on the PUBLIC landing-page Rich
     * List (see UserLedgerRepository::findTopOwnersByBalance()). One mis-typed extra zero is a
     * public incident, and a cap is the only thing between a fat finger and that board.
     */
    public const MAX_ABS_PENCE = 10_000_000_000;

    public function __construct(
        private readonly UserLedgerService     $ledgerService,
        private readonly UserLedgerRepository  $ledgerRepository,
        private readonly OwnerSpotlightService $ownerSpotlight,
        private readonly EntityManagerInterface $em,
        private readonly FacetCounter $facets,
        private readonly BmcAdminExtension $bmc,
    ) {}

    public static function getEntityFqcn(): string
    {
        return UserLedger::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $createAdjustment = Action::new('createAdjustment', 'New Adjustment', 'fa fa-plus')
            ->createAsGlobalAction()
            // Wrapped through the dashboard entry point: the target renders an
            // @EasyAdmin-extending template and only /admin?routeName=... populates the `ea`
            // Twig context. See src/Controller/Admin/CLAUDE.md.
            ->linkToUrl(fn (): string => $this->generateUrl('admin', [
                'routeName' => 'admin_user_ledger_create_adjustment',
            ]));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $createAdjustment)
            // STAYS DISABLED. See the class docblock — re-enabling NEW here would write balances
            // from form input and fork the balance chain.
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User Ledger Entry')
            ->setEntityLabelInPlural('User Ledger')
            ->setDefaultSort(['recordedAt' => 'DESC'])
            ->setPaginatorPageSize(25)
            ->showEntityActionsInlined()
            ->setSearchFields(['user.email', 'user.name', 'club.name'])
            ->overrideTemplate('crud/index', 'admin/crud/bmc_index.html.twig')
            ->setDefaultRowAction(Action::DETAIL)
            ->setHelp('index', 'Audit trail of every balance movement centralized onto a user across every club they own. '
                . 'Written by UserLedgerService. <strong>New Adjustment</strong> credits or deducts an account by hand — '
                . 'note that for anyone who has drawn a dividend, their newest row is the figure shown on the '
                . '<strong>public</strong> Rich List.');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            // Added with the adjustment form: the first thing anyone wants is "show me the
            // hand-entered rows", and there was no way to ask that.
            ->add(ChoiceFilter::new('type', 'Type')->setChoices(self::typeChoices())->canSelectMultiple())
            ->add(PenceRangeFilter::new('amountPence', 'Amount'))
            ->add(DateTimeFilter::new('recordedAt', 'Recorded'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('bmcGroup', 'Accounts');
        $responseParameters->set('bmcDomain', 'club');
        $responseParameters->set('bmcTotal', $this->em->getRepository(UserLedger::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'User or club');
        $responseParameters->set('bmcUnits', ['amountPence' => '£']);
        $responseParameters->set('bmcWidths', [
            'amountPence' => 104, 'balanceAfterPence' => 120, 'recordedAt' => 140,
        ]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $this->getContext(),
            new FieldCollection(iterator_to_array($this->indexFields())),
            $responseParameters->get('filters'),
            ['type' => 'entity.type'],
        ));

        return $responseParameters;
    }

    /**
     * User, club, amount, resulting balance, when.
     *
     * The design wanted "When" to read "S3 · WK 14", but UserLedger stores
     * neither a season nor a week — only occurredAt (in-game) and recordedAt
     * (real-world). The in-game date is the closest honest equivalent, with
     * the real-world time as the sub-line, so the cell keeps its two-line
     * shape without inventing a season number.
     */
    private function indexFields(): iterable
    {
        yield AssociationField::new('user', 'User')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, UserLedger $l) => [
                'text' => $l->getUser()->getName() ?: 'Unnamed',
                'tone' => $l->getUser()->getName() ? null : 'dim',
                'sub'  => $l->getUser()->getEmail(),
            ]);

        yield AssociationField::new('club', 'Club')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, UserLedger $l) => null === $l->getClub()
                // The club relation is nullable and detachable, so a historic
                // entry can outlive the club it belonged to.
                ? ['text' => 'Deleted club', 'tone' => 'dim']
                : [
                    'img'  => $l->getClub()->getBadgeConfig(),
                    'text' => $l->getClub()->getName(),
                    'sub'  => $l->getClub()->getAbbreviation(),
                ]);

        yield IntegerField::new('amountPence', 'Amount')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('right')
            ->formatValue(fn ($v, UserLedger $l) => [
                'text' => ($l->getAmountPence() > 0 ? '+' : '') . $this->bmc->money($l->getAmountPence()),
                'tone' => match (true) {
                    $l->getAmountPence() > 0 => 'positive',
                    $l->getAmountPence() < 0 => 'danger',
                    default                  => null,
                },
            ]);

        yield IntegerField::new('balanceAfterPence', 'Balance')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('right')
            ->formatValue(fn ($v, UserLedger $l) => [
                'text' => $this->bmc->money($l->getBalanceAfterPence()),
                'sub'  => 'from ' . $this->bmc->money($l->getBalanceBeforePence()),
            ]);

        yield DateTimeField::new('recordedAt', 'When')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, UserLedger $l) => [
                'text' => $this->bmc->date($l->getOccurredAt()),
                'sub'  => $this->bmc->ago($l->getRecordedAt()),
            ]);
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

        yield IdField::new('id')->hideOnIndex();
        yield AssociationField::new('user');
        yield AssociationField::new('club');
        yield ChoiceField::new('type')->setChoices(self::typeChoices());
        yield IntegerField::new('amountPence', 'Amount (pence)');
        yield IntegerField::new('balanceBeforePence', 'Balance before (pence)');
        yield IntegerField::new('balanceAfterPence', 'Balance after (pence)');
        yield DateTimeField::new('occurredAt', 'In-game date')->setFormat('yyyy-MM-dd HH:mm:ss');
        yield DateTimeField::new('recordedAt', 'Real-world date')->setFormat('yyyy-MM-dd HH:mm:ss');
        yield TextField::new('description')->hideOnIndex();
        yield AssociationField::new('sourceSyncRecord', 'Source sync')->hideOnIndex();
        yield IntegerField::new('sourceLedgerIndex', 'Ledger index')->hideOnIndex();
    }

    /**
     * Credit or deduct an account by hand.
     *
     * THE PATH SEGMENT IS DELIBERATELY NOT `/admin/user-ledger/...`. EasyAdmin's own detail
     * route is `/admin/user-ledger/{entityId}` with no requirements on the wildcard, so Symfony
     * would match that first and this action would be silently unreachable — the same trap
     * CompetitionEntrantCrudController documents for its own global action.
     *
     * Must be REACHED via /admin?routeName=... for both GET and POST, because it renders an
     * @EasyAdmin-extending template (src/Controller/Admin/CLAUDE.md). The index button does
     * that, and the template posts back to the same wrapped URL.
     */
    #[Route(
        '/admin/user-ledger-entries/create-adjustment',
        name: 'admin_user_ledger_create_adjustment',
        methods: ['GET', 'POST'],
    )]
    public function createAdjustment(Request $request): Response
    {
        $formState = [
            'userId'          => (string) $request->request->get('userId', ''),
            'amountPence'     => (string) $request->request->get('amountPence', ''),
            'description'     => (string) $request->request->get('description', ''),
            // MINTED WHEN THE FORM IS RENDERED, NOT PER SUBMISSION, and that is what makes a
            // double-click or an F5 safe. append() is idempotent on (sourceKind, sourceRef,
            // type), so a resubmission of the same rendered form writes nothing instead of
            // crediting twice. A fresh form mints a fresh token and can legitimately record a
            // second adjustment.
            'submissionToken' => (string) $request->request->get('submissionToken', '') ?: bin2hex(random_bytes(13)),
            'errors'          => [],
        ];

        if ($request->isMethod('POST')) {
            $entry = $this->attemptAdjustment($request, $formState);

            if ($entry !== null) {
                // EasyAdmin's own pretty route — it builds its `ea` context on a direct match,
                // so this one is NOT wrapped. The other documented exemption in CLAUDE.md.
                return $this->redirect($this->generateUrl('admin_user_ledger_detail', [
                    'entityId' => (string) $entry->getId(),
                ]));
            }
        }

        return $this->render('admin/user_ledger/create_adjustment.html.twig', [
            'formState'    => $formState,
            'users'        => $this->em->getRepository(User::class)->findBy([], ['email' => 'ASC']),
            // Every user's balance in one query, rendered onto the options so the before/after
            // preview is pure arithmetic with no round trip. Absent means no ledger rows yet,
            // which the template reads as 0 — the same answer getCurrentBalance() gives.
            'balances'     => $this->ledgerRepository->findBalancesByUser(),
            'maxAbsPence'  => self::MAX_ABS_PENCE,
            'indexUrl'     => $this->generateUrl('admin_user_ledger_index'),
        ]);
    }

    /**
     * Validate and write, or push messages onto $formState['errors'].
     *
     * @param array<string, mixed> $formState
     *
     * @return UserLedger|null the created entry, or null when nothing was written
     */
    private function attemptAdjustment(Request $request, array &$formState): ?UserLedger
    {
        if (!$this->isCsrfTokenValid('user_ledger_create_adjustment', (string) $request->request->get('_token'))) {
            $formState['errors'][] = 'Invalid CSRF token — nothing was written.';

            return null;
        }

        if (preg_match('/^[0-9a-f]{26}$/', $formState['submissionToken']) !== 1) {
            // Regenerate rather than trust it: the token is half of an idempotency key.
            $formState['submissionToken'] = bin2hex(random_bytes(13));
            $formState['errors'][]        = 'That form had expired. Check the values and submit again.';

            return null;
        }

        $user = $this->resolveUser($formState['userId']);

        if ($user === null) {
            $formState['errors'][] = 'Choose a user to adjust.';

            return null;
        }

        $amountPence = $this->resolveAmount((string) $formState['amountPence'], $formState['errors']);
        $description = trim((string) $formState['description']);

        if ($description === '') {
            // Required: it is the only record of WHY money moved by hand, and the player reads
            // it back on GET /api/account/ledger.
            $formState['errors'][] = 'Describe the adjustment — the player sees this on their own ledger.';
        } elseif (mb_strlen($description) > 255) {
            // mb_strlen, not strlen: the column is varchar(255), which Postgres counts in
            // characters, so a note with accents would otherwise be rejected or truncated wrong.
            $formState['errors'][] = sprintf('Keep the description under 255 characters (it is %d).', mb_strlen($description));
        }

        if ($formState['errors'] !== [] || $amountPence === null) {
            return null;
        }

        $admin     = $this->getUser() instanceof Admin ? $this->getUser() : null;
        $sourceRef = sprintf(
            // adminUuid(36) + ':' + token(26) = 63, and sourceRef is varchar(64). Keeping the
            // admin's uuid whole rather than hex-packing it means attribution is a plain
            // `WHERE source_ref LIKE '<adminId>:%'`.
            '%s:%s',
            $admin !== null ? (string) $admin->getId() : 'unknown',
            $formState['submissionToken'],
        );

        $alreadyRecorded = $this->ledgerRepository->findByBackendSource(
            self::SOURCE_KIND,
            $sourceRef,
            UserLedgerEntryType::CUSTOM_INCOME,
        );

        $entry = $this->ledgerService->append(
            $user,
            // Always null. An adjustment is a fact about the ACCOUNT, like the starting grant.
            null,
            UserLedgerEntryType::CUSTOM_INCOME,
            $amountPence,
            self::SOURCE_KIND,
            $sourceRef,
            $description,
        );

        if ($alreadyRecorded !== null) {
            // A resubmission of an already-recorded form. append() returned the ORIGINAL row and
            // wrote nothing. Reported as a warning naming the recorded amount, never as success:
            // the dangerous case is an operator who went back, changed the figure and resubmitted
            // — they must see that the old amount is what stands.
            $this->addFlash('warning', sprintf(
                'Nothing was written — this form was already submitted and recorded %s for %s. '
                . 'Open a new adjustment if you meant to record another.',
                $this->formatPence($entry->getAmountPence()),
                $user->getEmail(),
            ));

            return $entry;
        }

        // The Rich List publishes the newest row's balance and caches it for 10 minutes, so an
        // adjustment would otherwise show up on a public board at an unpredictable moment.
        $this->ownerSpotlight->clear();

        $this->addFlash('success', sprintf(
            'Recorded %s for %s. Balance %s → %s.',
            $this->formatPence($entry->getAmountPence()),
            $user->getEmail(),
            $this->formatPence($entry->getBalanceBeforePence()),
            $this->formatPence($entry->getBalanceAfterPence()),
        ));

        return $entry;
    }

    /** An id that is not a well-formed uuid throws out of the DBAL type, so this catches. */
    private function resolveUser(string $userId): ?User
    {
        if ($userId === '') {
            return null;
        }

        try {
            return $this->em->getRepository(User::class)->find($userId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse the amount strictly, or null with a reason pushed onto $errors.
     *
     * STRICT RATHER THAN CAST. `(int) '1.5'` is 1 and `(int) '1,000'` is 1 — both silently wrong
     * amounts of real money. Anything that is not a plain optionally-negative integer is
     * refused and the operator retypes it.
     *
     * @param list<string> $errors
     */
    private function resolveAmount(string $raw, array &$errors): ?int
    {
        $raw = trim($raw);

        if ($raw === '') {
            $errors[] = 'Enter an amount in pence.';

            return null;
        }

        if (preg_match('/^-?[0-9]{1,15}$/', $raw) !== 1) {
            $errors[] = 'Enter the amount as whole pence — digits only, with a leading minus to deduct. No decimal point, commas or spaces.';

            return null;
        }

        $amountPence = (int) $raw;

        if ($amountPence === 0) {
            $errors[] = 'A zero adjustment would record a row that moves nothing.';

            return null;
        }

        if (abs($amountPence) > self::MAX_ABS_PENCE) {
            $errors[] = sprintf(
                'That is larger than the %s cap for a single adjustment. Record it in smaller steps if it is genuinely intended.',
                $this->formatPence(self::MAX_ABS_PENCE),
            );

            return null;
        }

        return $amountPence;
    }

    /** Exact, never abbreviated — this is the figure an operator is confirming. */
    private function formatPence(int $pence): string
    {
        return sprintf('%s£%s', $pence < 0 ? '-' : '', number_format(abs($pence) / 100, 2));
    }

    /** @return array<string, string> label => value, as EasyAdmin expects. */
    private static function typeChoices(): array
    {
        $choices = [];
        foreach (UserLedgerEntryType::cases() as $type) {
            $choices[$type->name] = $type->value;
        }

        return $choices;
    }
}
