<?php

namespace App\Controller\Admin;

use App\Entity\Club;
use App\Entity\Investor;
use App\Entity\LeaderboardEntry;
use App\Entity\MatchResult;
use App\Entity\SeasonRatingsSnapshot;
use App\Entity\SeasonRecord;
use App\Entity\SeasonSnapshot;
use App\Entity\Sponsor;
use App\Entity\SyncRecord;
use App\Entity\Transfer;
use App\Admin\FacetCounter;
use App\Enum\Country;
use App\Filter\Admin\ActivityFilter;
use App\Filter\Admin\PenceRangeFilter;
use App\Repository\UserLedgerRepository;
use App\Twig\BmcAdminExtension;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;

class ClubCrudController extends AbstractCrudController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserLedgerRepository $userLedgerRepository,
        private BmcAdminExtension $bmc,
        private FacetCounter $facets,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Club::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // The index now renders actions as icon-only buttons (the bmc-iconbtn
        // class is what switches crud/action.html.twig onto that branch), so
        // the icons are Lucide names and the accessible name has to be stated.
        // The detail page is not redesigned yet and keeps the Font Awesome
        // button with its visible label.
        $deleteIndex = Action::new('confirmDelete', 'Delete club', 'trash-2')
            ->linkToUrl(fn (Club $entity) => $this->generateUrl('admin_club_delete_info', ['id' => $entity->getId()]))
            ->setHtmlAttributes([
                'title'              => 'Delete club',
                'aria-label'         => 'Delete club',
                // The existing modal flow: fetch /delete-info, then POST to the
                // same URL with /delete-info swapped for /delete.
                'data-delete-trigger' => '1',
                'data-delete-mode'    => 'club',
            ])
            ->setCssClass('bmc-iconbtn bmc-iconbtn--danger');

        $deleteDetail = Action::new('confirmDelete', 'Delete', 'fa fa-trash')
            ->linkToUrl(fn (Club $entity) => $this->generateUrl('admin_club_delete_info', ['id' => $entity->getId()]))
            ->setHtmlAttributes(['data-delete-trigger' => '1', 'data-delete-mode' => 'club'])
            ->setCssClass('btn btn-sm btn-outline-danger');

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a
                ->setLabel('View club')
                ->setIcon('eye')
                ->setHtmlAttributes(['title' => 'View club', 'aria-label' => 'View club'])
                ->setCssClass('bmc-iconbtn'))
            ->add(Crud::PAGE_INDEX, $deleteIndex)
            ->add(Crud::PAGE_DETAIL, $deleteDetail);
    }

    /**
     * Override EasyAdmin's detail action to render the custom club profile.
     * Runs inside EasyAdmin's context, so @EasyAdmin/layout.html.twig works correctly.
     */
    public function detail(AdminContext $context): Response
    {
        /** @var Club $club */
        $club = $context->getEntity()->getInstance();

        $syncRecords = $this->em->getRepository(SyncRecord::class)
            ->findBy(['club' => $club], ['serverTimestamp' => 'DESC'], 25);

        $latestValidSync = null;
        foreach ($syncRecords as $record) {
            if ($record->isValid()) {
                $latestValidSync = $record;
                break;
            }
        }

        $leaderboardEntries = $this->em->getRepository(LeaderboardEntry::class)
            ->findBy(['club' => $club], ['updatedAt' => 'DESC']);

        $recentTransfers = $this->em->getRepository(Transfer::class)
            ->findBy(['club' => $club], ['occurredAt' => 'DESC'], 5);

        $seasonRecords = $this->em->getRepository(SeasonRecord::class)
            ->findBy(['club' => $club], ['season' => 'DESC']);

        $debugLogs = $this->em->createQueryBuilder()
            ->select('s')
            ->from(SyncRecord::class, 's')
            ->where('s.club = :club')
            ->andWhere('s.debugLog IS NOT NULL')
            ->orderBy('s.serverTimestamp', 'DESC')
            ->setMaxResults(10)
            ->setParameter('club', $club)
            ->getQuery()
            ->getResult();

        $payload     = $latestValidSync ? $latestValidSync->getPayload() : [];
        $playerNames = self::buildPlayerNameMap($payload);
        $bondLists   = self::buildBondLists($payload, $playerNames);

        return $this->render('admin/club_profile.html.twig', [
            'club'               => $club,
            'syncRecords'        => $syncRecords,
            'latestValidSync'    => $latestValidSync,
            'leaderboardEntries' => $leaderboardEntries,
            'recentTransfers'    => $recentTransfers,
            'seasonRecords'      => $seasonRecords,
            'debugLogs'          => $debugLogs,
            'toxicBonds'         => $bondLists['toxic'],
            'strongBonds'        => $bondLists['strong'],
            // Money the owner actually drew out of this club. The headline figure is this,
            // not Club::$totalCareerEarnings — that accumulator is being phased out and no
            // longer backs any board (see its own deprecation note); it is kept on the page
            // alongside, labelled legacy, so an admin can still compare the two.
            'dividendsPence'     => $this->userLedgerRepository->getTotalDividendsByClub($club),
        ]);
    }

    /**
     * Deduplicates relationships[] into toxic (bondValue < 0) and strong (bondValue > 0)
     * bond lists for the Squad Culture cards. Real payloads record each bond from both
     * participants' perspective — an A-vs-B entry and its B-vs-A mirror, same bondValue —
     * so treating them as individual rows roughly doubles the true pairing count (and
     * the badge showing it). Deduped by an unordered {min(id), max(id)} pair key (plus
     * `kind`, so a coincidentally-matching player/staff pair can't collide) so either
     * direction collapses to a single row; names are resolved via $playerNames (see
     * buildPlayerNameMap()) before dedup so it doesn't matter which direction "wins".
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $playerNames
     * @return array{toxic: array<int, array<string, mixed>>, strong: array<int, array<string, mixed>>}
     */
    private static function buildBondLists(array $payload, array $playerNames): array
    {
        $seen   = [];
        $toxic  = [];
        $strong = [];

        foreach ($payload['relationships'] ?? [] as $rel) {
            if (!is_array($rel)) {
                continue;
            }

            $bondValue = (int) ($rel['bondValue'] ?? 0);
            $playerId  = (string) ($rel['playerId'] ?? '');
            $otherId   = (string) ($rel['otherId'] ?? '');
            if ($bondValue === 0 || $playerId === '' || $otherId === '') {
                continue;
            }

            $pairKey = ($playerId < $otherId ? $playerId . '|' . $otherId : $otherId . '|' . $playerId)
                . '|' . ($rel['kind'] ?? '');
            if (isset($seen[$pairKey])) {
                continue;
            }
            $seen[$pairKey] = true;

            $entry = [
                'playerName' => $rel['playerName'] ?? $playerNames[$playerId] ?? $playerId,
                'otherName'  => $rel['otherName'] ?? $playerNames[$otherId] ?? $otherId,
                'bondValue'  => $bondValue,
                'kind'       => $rel['kind'] ?? null,
            ];

            if ($bondValue < 0) {
                $toxic[] = $entry;
            } else {
                $strong[] = $entry;
            }
        }

        usort($toxic, static fn (array $a, array $b): int => $a['bondValue'] <=> $b['bondValue']);
        usort($strong, static fn (array $a, array $b): int => $b['bondValue'] <=> $a['bondValue']);

        return ['toxic' => $toxic, 'strong' => $strong];
    }

    /**
     * Best-effort playerId => playerName lookup built from every array in the sync
     * payload that pairs the two. relationships[]/playerStats[]/signings[] are loose,
     * unvalidated client arrays (see SyncRequest's docblocks) and are inconsistent
     * about which entry for a given player carries a name — e.g. a bond's "other side"
     * mirror entry for the same player may omit the name a different entry supplied.
     * Player is a pool entity with no persisted club roster once consumed (see this
     * repo's CLAUDE.md "Pool Lifecycle" section — deleted from the DB on assign), so
     * this payload is the only source of truth available; a name missing from every
     * array here means the client itself never sent one for that id, not a lookup
     * failure this method could fix by trying harder.
     *
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private static function buildPlayerNameMap(array $payload): array
    {
        $names = [];

        foreach ($payload['relationships'] ?? [] as $rel) {
            if (!is_array($rel)) {
                continue;
            }
            if (!empty($rel['playerId']) && !empty($rel['playerName'])) {
                $names[$rel['playerId']] = $rel['playerName'];
            }
            if (!empty($rel['otherId']) && !empty($rel['otherName'])) {
                $names[$rel['otherId']] = $rel['otherName'];
            }
        }

        foreach ($payload['playerStats'] ?? [] as $stat) {
            if (is_array($stat) && !empty($stat['playerId']) && !empty($stat['playerName'])) {
                $names[$stat['playerId']] = $stat['playerName'];
            }
        }

        foreach ($payload['signings'] ?? [] as $signing) {
            if (is_array($signing) && !empty($signing['playerId']) && !empty($signing['playerName'])) {
                $names[$signing['playerId']] = $signing['playerName'];
            }
        }

        return $names;
    }

    /**
     * Override deleteEntity so that batch delete runs FK cleanup before removal.
     * EasyAdmin's batchDelete() calls $this->deleteEntity() for each selected club.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if (!$entityInstance instanceof Club) {
            parent::deleteEntity($entityManager, $entityInstance);
            return;
        }

        foreach ([Transfer::class, MatchResult::class, SeasonRecord::class, SeasonSnapshot::class, SeasonRatingsSnapshot::class] as $class) {
            $entityManager->createQueryBuilder()
                ->delete($class, 'e')
                ->where('e.club = :club')
                ->setParameter('club', $entityInstance)
                ->getQuery()->execute();
        }

        foreach ([Investor::class => 'i', Sponsor::class => 's'] as $class => $alias) {
            $entityManager->createQueryBuilder()
                ->update($class, $alias)
                ->set("{$alias}.club", ':null')
                ->where("{$alias}.club = :club")
                ->setParameter('null', null)
                ->setParameter('club', $entityInstance)
                ->getQuery()->execute();
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            // Without these the page titles itself "Club" off the entity name.
            ->setEntityLabelInSingular('Club')
            ->setEntityLabelInPlural('Clubs')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(25)
            // Row actions sit in the row, not behind a dropdown.
            ->showEntityActionsInlined()
            // Searching the owner as well as the club is the point of the box:
            // most lookups start from "who complained", not from a club name.
            ->setSearchFields(['name', 'abbreviation', 'user.email', 'user.name'])
            ->overrideTemplate('crud/index', 'admin/crud/bmc_index.html.twig');
    }

    /**
     * Six columns, each returning a cell spec that
     * templates/admin/field/bmc_cell.html.twig renders (handoff §6.4).
     *
     * The detail page is not redesigned, so it keeps the old plain fields.
     */
    /**
     * The rail renders itself from these — see _bmc_rail.html.twig.
     *
     * League tier is not a column on Club; it lives on the league the club is
     * currently in, so the filter traverses the association. Note that in the
     * query string that property is spelled with a colon
     * (filters[currentLeague:tier]) — FiltersFormType swaps '.' for ':' — and
     * that the join is nullable, so a club between leagues matches no tier.
     */
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ActivityFilter::new('lastSyncedAt'))
            ->add(BooleanFilter::new('isSpoof', 'Club type'))
            ->add(ChoiceFilter::new('currentLeague.tier', 'League tier')
                ->setChoices(array_combine(
                    array_map(static fn (int $t) => 'Tier ' . $t, range(1, 8)),
                    range(1, 8),
                ))
                ->canSelectMultiple())
            ->add(ChoiceFilter::new('country', 'Country')
                ->setChoices($this->countryChoices())
                ->canSelectMultiple())
            ->add(PenceRangeFilter::new('balance', 'Balance'))
            ->add(DateTimeFilter::new('createdAt', 'Created'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $context = $this->getContext();

        $responseParameters->set('bmcGroup', 'Accounts');
        $responseParameters->set('bmcDomain', 'club');
        // The unfiltered total, for the page strip. The filtered count is
        // already on the paginator, so it costs nothing extra.
        $responseParameters->set('bmcTotal', $this->em->getRepository(Club::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Club, abbreviation, owner');
        $responseParameters->set('bmcFacetColumns', ['currentLeague.tier' => 2]);
        $responseParameters->set('bmcFlagFacets', ['country']);
        $responseParameters->set('bmcSegmentLabels', ['isSpoof' => ['0' => 'REAL', '1' => 'SPOOF']]);
        $responseParameters->set('bmcUnits', ['balance' => '£']);
        $responseParameters->set('bmcHints', [
            'lastSyncedAt' => [ActivityFilter::ACTIVE => '≤ 7d', ActivityFilter::DORMANT => '> 7d'],
        ]);
        $responseParameters->set('bmcWidths', [
            'country' => 92, 'lastSyncedWeek' => 128, 'lastSyncedAt' => 118,
            'balance' => 108, 'createdAt' => 118,
        ]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $context,
            new FieldCollection($this->configureFields(Crud::PAGE_INDEX)),
            $responseParameters->get('filters'),
            [
                'lastSyncedAt'      => ActivityFilter::countExpression('entity.lastSyncedAt'),
                'isSpoof'           => 'entity.isSpoof',
                'currentLeague:tier' => 'bmc_league.tier',
                'country'           => 'entity.country',
            ],
            ['bmc_activity_cutoff' => new \DateTimeImmutable('-' . ActivityFilter::CUTOFF_DAYS . ' days')],
            ['bmc_league' => 'entity.currentLeague'],
        ));

        return $responseParameters;
    }

    /** @return array<string, string> label => ISO-2 value, only countries in use */
    private function countryChoices(): array
    {
        $rows = $this->em->createQuery(
            'SELECT DISTINCT c.country FROM ' . Club::class . ' c WHERE c.country IS NOT NULL ORDER BY c.country ASC'
        )->getScalarResult();

        $choices = [];
        foreach ($rows as $row) {
            $code = $row['country'];
            $choices[Country::tryFrom($code)?->label() ?? strtoupper($code)] = $code;
        }

        return $choices;
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX !== $pageName) {
            yield IdField::new('id')->hideOnForm()->onlyOnDetail();
            yield TextField::new('name', 'Club Name');
            yield BooleanField::new('isSpoof', 'Spoof')->renderAsSwitch(false);
            yield TextField::new('country', 'Country');
            yield TextField::new('user.email', 'User')
                ->formatValue(fn ($v, Club $c) => $c->getUser()->getEmail())
                ->setSortable(false);
            yield IntegerField::new('lastSyncedWeek', 'Last Sync Week');
            yield DateTimeField::new('lastSyncedAt', 'Last Sync Date')
                ->setFormat('yyyy-MM-dd HH:mm')
                ->setRequired(false);
            yield DateTimeField::new('createdAt', 'Created')
                ->setFormat('yyyy-MM-dd HH:mm');

            return;
        }

        $cell = static fn (callable $fn) => $fn;

        // Badge, name, abbreviation, and a SPOOF tag when it is one. The badge
        // config is client-supplied and never validated server-side, so it can
        // be null or malformed; bmc-admin.js draws it as a background image and
        // leaves the slot empty rather than throwing a broken-image error.
        yield TextField::new('name', 'Club')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Club $c) => [
                'img'  => $c->getBadgeConfig(),
                'text' => $c->getName(),
                'sub'  => $c->getAbbreviation(),
                'tag'  => $c->isSpoof() ? ['label' => 'SPOOF', 'color' => 'warning'] : null,
            ]);

        yield TextField::new('country', 'Nation')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Club $c) => $c->getCountry()
                ? ['flag' => $c->getCountry(), 'text' => strtoupper($c->getCountry())]
                : null);

        // "S3 · WK 14". lastSyncedWeek is 0 for a club that has never synced,
        // which is not a week — show nothing rather than "WK 0".
        yield IntegerField::new('lastSyncedWeek', 'Season')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Club $c) => $c->getLastSyncedWeek()
                ? ['text' => 'S' . $c->getCurrentSeason() . ' · WK ' . $c->getLastSyncedWeek()]
                : null);

        // Dim when never synced, warning past a week — the column exists to
        // surface clubs that have gone quiet.
        yield DateTimeField::new('lastSyncedAt', 'Last sync')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(function ($v, Club $c): array {
                $last = $c->getLastSyncedAt();
                $tone = null;
                if (null === $last) {
                    $tone = 'dim';
                } elseif ($last < new \DateTimeImmutable('-7 days')) {
                    $tone = 'warning';
                }

                return ['text' => $this->bmc->ago($last), 'tone' => $tone];
            });

        yield IntegerField::new('balance', 'Balance')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('right')
            ->formatValue(fn ($v, Club $c) => [
                'text' => $this->bmc->money($c->getBalance()),
                'tone' => $c->getBalance() < 0 ? 'danger' : null,
            ]);

        yield DateTimeField::new('createdAt', 'Created')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Club $c) => ['text' => $this->bmc->date($c->getCreatedAt()), 'tone' => 'dim']);
    }
}
