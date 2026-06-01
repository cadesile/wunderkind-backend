<?php

namespace App\Controller\Admin;

use App\Entity\Club;
use App\Entity\Investor;
use App\Entity\Player;
use App\Entity\LeaderboardEntry;
use App\Entity\MatchResult;
use App\Entity\RefreshToken;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
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
        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function deleteEntity(EntityManagerInterface $em, $entityInstance): void
    {
        /** @var Club $club */
        $club  = $entityInstance;
        $user  = $club->getUser();
        $email = $user->getUserIdentifier();

        // Delete records with FK to Club that have no Doctrine cascade
        foreach ([MatchResult::class, SeasonRecord::class, SeasonSnapshot::class, Transfer::class] as $entityClass) {
            $em->createQueryBuilder()
                ->delete($entityClass, 'e')
                ->where('e.club = :club')
                ->setParameter('club', $club)
                ->getQuery()->execute();
        }

        // Null out club FK on Investors and Sponsors — these are market-pool rows, keep them
        $em->createQueryBuilder()
            ->update(Investor::class, 'i')
            ->set('i.club', ':null')
            ->where('i.club = :club')
            ->setParameter('null', null)
            ->setParameter('club', $club)
            ->getQuery()->execute();

        $em->createQueryBuilder()
            ->update(Sponsor::class, 's')
            ->set('s.club', ':null')
            ->where('s.club = :club')
            ->setParameter('null', null)
            ->setParameter('club', $club)
            ->getQuery()->execute();

        // Remove orphaned refresh tokens (stored by username string, no FK)
        $em->createQueryBuilder()
            ->delete(RefreshToken::class, 'rt')
            ->where('rt.username = :email')
            ->setParameter('email', $email)
            ->getQuery()->execute();

        // Remove User → Doctrine cascades to Club → Club cascades to players, staff,
        // sync records, leaderboard entries, inbox messages
        $em->remove($user);
        $em->flush();
    }

    /**
     * Override EasyAdmin's detail action to render the custom club profile.
     * Runs inside EasyAdmin's context, so @EasyAdmin/layout.html.twig works correctly.
     * The URL stays /admin/club/{uuid} — no separate controller or route needed.
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

        // Build a UUID→name map for player stats rows in the latest valid sync payload
        $playerNames = [];
        if ($latestValidSync !== null) {
            $stats = $latestValidSync->getPayload()['playerStats'] ?? [];
            $ids   = array_filter(array_column($stats, 'playerId'));
            if ($ids) {
                $players = $this->em->createQueryBuilder()
                    ->select('p.id, p.firstName, p.lastName')
                    ->from(Player::class, 'p')
                    ->where('p.id IN (:ids)')
                    ->setParameter('ids', $ids)
                    ->getQuery()
                    ->getArrayResult();

                foreach ($players as $row) {
                    $playerNames[(string) $row['id']] = trim($row['firstName'] . ' ' . $row['lastName']);
                }
            }
        }

        return $this->render('admin/club_profile.html.twig', [
            'club'               => $club,
            'syncRecords'        => $syncRecords,
            'latestValidSync'    => $latestValidSync,
            'leaderboardEntries' => $leaderboardEntries,
            'recentTransfers'    => $recentTransfers,
            'debugLogs'          => $debugLogs,
            'playerNames'        => $playerNames,
        ]);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name');
        yield TextField::new('abbreviation')->setHelp('Up to 5 chars, e.g. MAN, BARCA');
        yield TextField::new('country');
        yield AssociationField::new('user');
        yield IntegerField::new('reputation');
        yield IntegerField::new('totalCareerEarnings')
            ->formatValue(fn($v) => $v !== null ? '£' . number_format((int) $v / 100) : '—');
        yield IntegerField::new('hallOfFamePoints');
        yield IntegerField::new('lastSyncedWeek');
        yield DateTimeField::new('lastSyncedAt')->setFormat('yyyy-MM-dd HH:mm')->setRequired(false);
        yield DateTimeField::new('createdAt')->setFormat('yyyy-MM-dd HH:mm');
    }
}
