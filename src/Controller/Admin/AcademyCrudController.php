<?php

namespace App\Controller\Admin;

use App\Entity\Academy;
use App\Entity\LeaderboardEntry;
use App\Entity\SyncRecord;
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

class AcademyCrudController extends AbstractCrudController
{
    public function __construct(private EntityManagerInterface $em) {}

    public static function getEntityFqcn(): string
    {
        return Academy::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    /**
     * Override EasyAdmin's detail action to render the custom academy profile.
     * Runs inside EasyAdmin's context, so @EasyAdmin/layout.html.twig works correctly.
     * The URL stays /admin/academy/{uuid} — no separate controller or route needed.
     */
    public function detail(AdminContext $context): Response
    {
        /** @var Academy $academy */
        $academy = $context->getEntity()->getInstance();

        $syncRecords = $this->em->getRepository(SyncRecord::class)
            ->findBy(['academy' => $academy], ['serverTimestamp' => 'DESC'], 25);

        $latestValidSync = null;
        foreach ($syncRecords as $record) {
            if ($record->isValid()) {
                $latestValidSync = $record;
                break;
            }
        }

        $leaderboardEntries = $this->em->getRepository(LeaderboardEntry::class)
            ->findBy(['academy' => $academy], ['updatedAt' => 'DESC']);

        return $this->render('admin/academy_profile.html.twig', [
            'academy'            => $academy,
            'syncRecords'        => $syncRecords,
            'latestValidSync'    => $latestValidSync,
            'leaderboardEntries' => $leaderboardEntries,
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
