<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\Country;
use App\Form\Type\AppearanceType;
use App\Admin\FacetCounter;
use App\Filter\Admin\AccountTypeFilter;
use App\Filter\Admin\ActivityFilter;
use App\Filter\Admin\ClubsOwnedFilter;
use App\Repository\SyncRecordRepository;
use App\Repository\UserLedgerRepository;
use App\Service\SessionRevocationService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;

class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly SyncRecordRepository $syncRecordRepository,
        private readonly UserLedgerRepository $userLedgerRepository,
        private readonly SessionRevocationService $sessionRevocation,
        private readonly FacetCounter $facets,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {}

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $deleteAction = Action::new('confirmDeleteUser', 'Delete', 'fa fa-user-slash')
            ->linkToUrl(fn(User $entity) => $this->generateUrl('admin_user_delete_info', ['id' => $entity->getId()]))
            ->setHtmlAttributes(['data-delete-trigger' => '1', 'data-delete-mode' => 'user'])
            ->setCssClass('btn btn-sm btn-outline-danger');

        // Icon-only buttons on the index (bmc-iconbtn switches
        // crud/action.html.twig onto the Lucide branch); the detail page is not
        // redesigned and keeps the labelled Font Awesome button.
        $deleteIndex = Action::new('confirmDeleteUser', 'Delete user + clubs', 'user-x')
            ->linkToUrl(fn (User $entity) => $this->generateUrl('admin_user_delete_info', ['id' => $entity->getId()]))
            ->setHtmlAttributes([
                'title'               => 'Delete user + clubs',
                'aria-label'          => 'Delete user and their clubs',
                'data-delete-trigger' => '1',
                'data-delete-mode'    => 'user',
            ])
            ->setCssClass('bmc-iconbtn bmc-iconbtn--danger');

        return $actions
            // NEW/DELETE stay disabled — accounts are created via registration
            // and removed via the account-deletion flow, not admin.
            ->disable(Action::NEW, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a
                ->setLabel('View user')->setIcon('eye')
                ->setHtmlAttributes(['title' => 'View user', 'aria-label' => 'View user'])
                ->setCssClass('bmc-iconbtn'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $a) => $a
                ->setLabel('Edit user')->setIcon('pencil')
                ->setHtmlAttributes(['title' => 'Edit user', 'aria-label' => 'Edit user'])
                ->setCssClass('bmc-iconbtn'))
            ->add(Crud::PAGE_INDEX, $deleteIndex)
            ->add(Crud::PAGE_DETAIL, $deleteAction);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural('Users')
            // A–Z by owner name, not newest-first: this is the page you arrive
            // at knowing who you are looking for.
            ->setDefaultSort(['name' => 'ASC'])
            ->setPaginatorPageSize(25)
            ->showEntityActionsInlined()
            ->setSearchFields(['email', 'name', 'clubs.name'])
            ->addFormTheme('admin/form/appearance_theme.html.twig')
            ->overrideTemplate('crud/index', 'admin/crud/bmc_index.html.twig')
            ->overrideTemplate('crud/edit', 'admin/user_edit.html.twig');
    }

    /**
     * Injects the clubs/earnings panel shown above the edit form — see
     * templates/admin/user_edit.html.twig, which overrides content_header_wrapper (rendered
     * before the form, not part of it) so the form itself is untouched and still submits
     * normally. Same "override one block of a standard CRUD template" pattern as
     * PlayerCrudController::index()'s playerSummary panel.
     */
    public function edit(AdminContext $context): KeyValueStore|Response
    {
        $responseParameters = parent::edit($context);
        if ($responseParameters instanceof KeyValueStore) {
            /** @var User $user */
            $user = $context->getEntity()->getInstance();

            $responseParameters->set('clubsSummary', $this->buildClubsSummary($user));
            $responseParameters->set('clubKitConfigs', $this->buildClubKitConfigs($user));
            $responseParameters->set('overallBalancePence', $this->userLedgerRepository->getCurrentBalance($user));
            // The edit page's header panel carries the same Kill Session control
            // as the detail page; both need `user` and `activeSessions` in scope
            // for admin/_kill_session_button.html.twig.
            $responseParameters->set('user', $user);
            $responseParameters->set('activeSessions', $this->sessionRevocation->countActiveSessions($user));
        }

        return $responseParameters;
    }

    /**
     * @return array<int, array{club: \App\Entity\Club, latestSync: ?\App\Entity\SyncRecord, dividendsPence: int}>
     */
    private function buildClubsSummary(User $user): array
    {
        $summary = [];
        foreach ($user->getClubs() as $club) {
            $summary[] = [
                'club'           => $club,
                'latestSync'     => $this->syncRecordRepository->findLatestValid($club),
                'dividendsPence' => $this->userLedgerRepository->getTotalDividendsByClub($club),
            ];
        }

        return $summary;
    }

    /**
     * Plain-scalar shape for the kit-compositor.js inline script in user_edit.html.twig — built
     * in PHP rather than with a Twig map()/arrow-function one-liner so the id is a predictable
     * string, not a Twig-version-dependent cast of a UuidV7 object.
     *
     * @return array<int, array{id: string, home: ?array, away: ?array, badge: ?array}>
     */
    private function buildClubKitConfigs(User $user): array
    {
        $configs = [];
        foreach ($user->getClubs() as $club) {
            $configs[] = [
                'id'    => (string) $club->getId(),
                'home'  => $club->getHomeKitConfig(),
                'away'  => $club->getAwayKitConfig(),
                'badge' => $club->getBadgeConfig(),
            ];
        }

        return $configs;
    }

    /**
     * Four columns: who they are, what they own, what kind of account, verified.
     *
     * "Owner" leads with the person's name and drops the email to the sub-line,
     * because an unnamed guest account reads as a wall of UUID otherwise.
     */
    private function indexFields(): iterable
    {
        yield TextField::new('name', 'Owner')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, User $u) => [
                // User::$nationality holds a demonym ("English"), not a code,
                // so it has to be resolved before it can name a flag file.
                // Country::fromNationality() is the existing reverse lookup.
                'flag' => $u->getNationality()
                    ? Country::fromNationality($u->getNationality())?->value
                    : null,
                'text' => $u->getName() ?: 'Unnamed',
                'tone' => $u->getName() ? null : 'dim',
                'sub'  => self::accountLabel($u),
            ]);

        // Up to three badges then "+N" — enough to recognise the account at a
        // glance without the column setting the row height.
        yield IntegerField::new('clubs.count', 'Clubs')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setSortable(false)
            ->formatValue(function ($v, User $u): ?array {
                $clubs = $u->getClubs();
                if (0 === $clubs->count()) {
                    return ['text' => 'No clubs', 'tone' => 'dim'];
                }

                $shown = [];
                foreach ($clubs->slice(0, 3) as $club) {
                    $shown[] = ['kit' => $club->getBadgeConfig(), 'title' => $club->getName()];
                }

                return [
                    'imgs' => $shown,
                    'more' => $clubs->count() > 3 ? '+' . ($clubs->count() - 3) : null,
                ];
            });

        yield TextField::new('email', 'Account')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, User $u) => ['chip' => match (true) {
                User::isGuestEmail($u->getEmail()) => ['label' => 'GUEST', 'color' => 'subtle'],
                User::isSpoofEmail($u->getEmail()) => ['label' => 'SPOOF', 'color' => 'warning'],
                default                            => ['label' => 'REAL', 'color' => null],
            }]);

        yield BooleanField::new('isVerified', 'Verified')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('center')
            ->formatValue(fn ($v, User $u) => $u->isVerified()
                ? ['text' => '✓', 'tone' => 'positive']
                : ['text' => '—', 'tone' => 'dim']);
    }

    /** Email, or a readable stand-in for the machine-generated ones. */
    private static function accountLabel(User $user): string
    {
        $email = $user->getEmail();

        if (User::isGuestEmail($email)) {
            return strstr($email, '@', true) . ' · guest';
        }

        return $email;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(AccountTypeFilter::new())
            ->add(BooleanFilter::new('isVerified', 'Verified'))
            ->add(ClubsOwnedFilter::new())
            ->add(ActivityFilter::new('lastLoginAt', 'Last login', [
                'Active' => ActivityFilter::ACTIVE,
                'Lapsed' => ActivityFilter::DORMANT,
                'Never'  => ActivityFilter::NEVER,
            ]))
            ->add(DateTimeFilter::new('createdAt', 'Created'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $context = $this->getContext();
        $qb      = $this->em->createQueryBuilder();

        $responseParameters->set('bmcGroup', 'Accounts');
        $responseParameters->set('bmcDomain', 'people');
        $responseParameters->set('bmcTotal', $this->em->getRepository(User::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Email, owner, club');
        $responseParameters->set('bmcSegmentLabels', ['isVerified' => ['0' => 'NO', '1' => 'YES']]);
        $responseParameters->set('bmcFacetColumns', ['id' => 3]);
        $responseParameters->set('bmcHints', [
            'lastLoginAt' => [ActivityFilter::ACTIVE => '≤ 7d', ActivityFilter::DORMANT => '> 7d'],
            'email'       => [AccountTypeFilter::GUEST => 'device-bound', AccountTypeFilter::SPOOF => 'admin-made'],
        ]);
        $responseParameters->set('bmcWidths', ['clubs.count' => 160, 'email' => 112, 'isVerified' => 96]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $context,
            new FieldCollection(iterator_to_array($this->indexFields())),
            $responseParameters->get('filters'),
            [
                'email'        => AccountTypeFilter::countExpression(),
                'isVerified'   => 'entity.isVerified',
                'id'           => ClubsOwnedFilter::countExpression($qb),
                'lastLoginAt'  => ActivityFilter::countExpression('entity.lastLoginAt'),
            ],
            ['bmc_activity_cutoff' => new \DateTimeImmutable('-' . ActivityFilter::CUTOFF_DAYS . ' days')],
        ));

        return $responseParameters;
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email');
        yield IntegerField::new('clubs.count', 'Clubs')
            ->formatValue(fn($v, User $u) => $u->getClubs()->count())
            ->setSortable(false)
            ->hideOnForm();
        // Both are system-managed timestamps (createdAt has no setter at all) —
        // read-only everywhere now that EDIT is enabled, not just displayed.
        yield DateTimeField::new('lastLoginAt', 'Last Login')
            ->setFormat('yyyy-MM-dd HH:mm')
            ->hideOnForm();
        yield DateTimeField::new('createdAt', 'Created')
            ->setFormat('yyyy-MM-dd HH:mm')
            ->hideOnForm();

        // ── Panel: Owner Identity ────────────────────────────────────────────
        yield FormField::addFieldset('Owner Identity', 'fa fa-user-circle')->hideOnIndex();

        yield TextField::new('name')->setRequired(false);
        yield TextField::new('nationality')->setRequired(false);
        yield ChoiceField::new('gender')
            ->setChoices(['Male' => 'male', 'Female' => 'female'])
            ->setRequired(false);
        yield DateField::new('dob')->setLabel('Date of Birth')->setRequired(false);

        yield Field::new('appearance')
            ->setFormType(AppearanceType::class)
            ->setFormTypeOptions(['person_type' => 'staff'])
            ->onlyOnForms();

        // ── Panel: Developer / Debug ─────────────────────────────────────────
        yield FormField::addFieldset('Developer / Debug', 'fa fa-bug')->hideOnIndex();

        // Rendered as a plain yes/no on the index rather than EasyAdmin's inline AJAX
        // switch (renderAsSwitch(false)): this exposes a panel that logs every API request
        // and response for the account, so it should take opening the edit form, not a
        // stray click while scanning a user list.
        yield BooleanField::new('debugLoggingEnabled', 'Debug panel')
            ->renderAsSwitch(false)
            ->setHelp(
                'Shows the <strong>cog icon</strong> in this account\'s bottom navigation, opening the '
                . 'in-app debug log panel that records all API requests and responses. Use this to debug '
                . 'one player without exposing the panel to everyone. '
                . 'Additive only: the global switch (Game Config &rarr; Developer / Debug) already turns '
                . 'the panel on for every account, so this flag only has an effect while that one is off, '
                . 'and can never turn the panel off for this account. '
                . 'Takes effect on the account\'s next authenticated <code>GET /api/game-config</code>.'
            );
    }

    public function detail(AdminContext $context): Response
    {
        /** @var User $user */
        $user = $context->getEntity()->getInstance();

        return $this->render('admin/user_profile.html.twig', [
            'user'           => $user,
            'clubs'          => $user->getClubs()->toArray(),
            'activeSessions' => $this->sessionRevocation->countActiveSessions($user),
        ]);
    }
}
