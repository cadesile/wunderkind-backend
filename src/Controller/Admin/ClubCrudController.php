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

        return $this->render('admin/club_profile.html.twig', [
            'club'               => $club,
            'syncRecords'        => $syncRecords,
            'latestValidSync'    => $latestValidSync,
            'leaderboardEntries' => $leaderboardEntries,
            'recentTransfers'    => $recentTransfers,
            'debugLogs'          => $debugLogs,
        ]);
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
