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
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
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
    public function __construct(private EntityManagerInterface $em) {}

    public static function getEntityFqcn(): string
    {
        return Club::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $deleteAction = Action::new('confirmDelete', 'Delete', 'fa fa-trash')
            ->linkToUrl(fn(Club $entity) => $this->generateUrl('admin_club_delete_info', ['id' => $entity->getId()]))
            ->setHtmlAttributes(['data-delete-trigger' => '1', 'data-delete-mode' => 'club'])
            ->setCssClass('btn btn-sm btn-outline-danger');

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $deleteAction)
            ->add(Crud::PAGE_DETAIL, $deleteAction);
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
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->overrideTemplate('crud/index', 'admin/club_index.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->onlyOnDetail();
        yield TextField::new('name', 'Club Name');
        yield BooleanField::new('isSpoof', 'Spoof')->renderAsSwitch(false);
        yield TextField::new('country', 'Country');
        yield TextField::new('user.email', 'User')
            ->formatValue(fn($v, Club $c) => $c->getUser()->getEmail())
            ->setSortable(false);
        yield IntegerField::new('lastSyncedWeek', 'Last Sync Week');
        yield DateTimeField::new('lastSyncedAt', 'Last Sync Date')
            ->setFormat('yyyy-MM-dd HH:mm')
            ->setRequired(false);
        yield DateTimeField::new('createdAt', 'Created')
            ->setFormat('yyyy-MM-dd HH:mm');
    }
}
