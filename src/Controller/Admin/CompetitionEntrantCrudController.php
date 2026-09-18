<?php

namespace App\Controller\Admin;

use App\Entity\Competition\CompetitionEntrant;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Service\Competition\CompetitionSpoofEntrantService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only view of every club snapshot submitted at registration/resubmission — the
 * primary use is debugging/support: confirming what a club actually sent (roster size,
 * tactics, attribute values) without needing direct DB access. Entrants are created by
 * CompetitionRegistrationService and updated by round processing/resubmission, never
 * hand-edited, so every action is disabled — same reasoning as
 * ActiveCompetitionCrudController/CompetitionRoundCrudController.
 *
 * The one deliberate exception is "Generate Spoof Entrants": an admin test-data tool
 * that clones THIS entrant's snapshot into N new spoof entrants in the same competition
 * (see CompetitionSpoofEntrantService). It's only ever offered from an existing, real
 * entrant — spoofing always needs a real snapshot as its basis — and only while the
 * competition is still REGISTERING. It's a plain routed action (not linkToCrudAction),
 * following src/Controller/Admin/CLAUDE.md: this project's pretty-URL admin routing only
 * knows the standard CRUD actions, so a custom action must be its own route, reached via
 * `/admin?routeName=...` when it renders an EasyAdmin-layout page directly.
 */
class CompetitionEntrantCrudController extends AbstractCrudController
{
    public function __construct(private readonly CompetitionEntrantRepository $entrantRepository) {}

    public static function getEntityFqcn(): string
    {
        return CompetitionEntrant::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['registeredAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // EDIT is disabled, so EasyAdmin's default row-click fallback ([EDIT, DETAIL])
        // needs DETAIL explicitly present in the index actions to click through to it.
        $generateSpoof = Action::new('generateSpoof', 'Generate Spoof Entrants', 'fa fa-clone')
            ->linkToUrl(fn (CompetitionEntrant $entrant) => $this->generateUrl('admin', [
                'routeName'   => 'admin_competition_entrant_generate_spoof',
                'routeParams' => ['entrant' => (string) $entrant->getId()],
            ]))
            ->displayIf(static fn (CompetitionEntrant $entrant) => $entrant->getActiveCompetition()->getStatus() === ActiveCompetitionStatus::REGISTERING);

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $generateSpoof)
            ->add(Crud::PAGE_DETAIL, $generateSpoof);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('activeCompetition', 'Competition');
        yield AssociationField::new('club');
        yield BooleanField::new('club.isSpoof', 'Spoof')->renderAsSwitch(false);
        yield IntegerField::new('seed');

        yield ChoiceField::new('status')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => CompetitionEntrantStatus::class]);

        // Snapshot-derived summary columns — scan the list without opening every row.
        yield IntegerField::new('snapshotPlayerCount', 'Players');
        yield TextField::new('snapshotFormation', 'Formation')->hideOnIndex();
        yield TextField::new('snapshotPlayingStyle', 'Playing Style');

        yield IntegerField::new('snapshotVersion', 'Version')->hideOnIndex();
        yield DateTimeField::new('snapshotLockedAt')->hideOnIndex();
        yield DateTimeField::new('registeredAt');
        yield AssociationField::new('eliminatedInRound')->hideOnIndex();

        yield CodeEditorField::new('snapshotJsonPretty', 'Full Snapshot')
            ->setLanguage('js')
            ->setNumOfRows(24)
            ->onlyOnDetail();
    }

    #[Route('/admin/competition-entrant/{entrant}/generate-spoof', name: 'admin_competition_entrant_generate_spoof', methods: ['GET', 'POST'])]
    public function generateSpoofEntrants(CompetitionEntrant $entrant, Request $request, CompetitionSpoofEntrantService $service): Response
    {
        $activeCompetition = $entrant->getActiveCompetition();
        // admin_competition_entrant_detail is one of EasyAdmin's own pretty CRUD routes
        // (already wired to build the full crud/entity context on a direct match) — unlike
        // this action's own route, it must NOT go through the /admin?routeName=... forwarding
        // trick, which is only for routes EasyAdmin doesn't already know how to contextualise.
        $detailUrl = $this->generateUrl('admin_competition_entrant_detail', ['entityId' => (string) $entrant->getId()]);

        if ($activeCompetition->getStatus() !== ActiveCompetitionStatus::REGISTERING) {
            $this->addFlash('danger', 'This competition is no longer accepting registrations.');
            return $this->redirect($detailUrl);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('generate_spoof_entrants', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Invalid CSRF token.');
                return $this->redirect($detailUrl);
            }

            $count  = max(1, (int) $request->request->get('count', 1));
            $result = $service->generateSpoofEntrants($entrant, $count);
            $made   = count($result['created']);

            if ($made < $result['requested']) {
                $this->addFlash('warning', sprintf(
                    'Created %d of %d spoof entrants — competition filled and locked.',
                    $made,
                    $result['requested'],
                ));
            } else {
                $this->addFlash('success', sprintf('Created %d spoof entrant%s.', $made, $made === 1 ? '' : 's'));
            }

            return $this->redirect($detailUrl);
        }

        $remaining = $activeCompetition->getEntrantCapacity() - $this->entrantRepository->countForCompetition($activeCompetition);
        $actionUrl = $this->generateUrl('admin', [
            'routeName'   => 'admin_competition_entrant_generate_spoof',
            'routeParams' => ['entrant' => (string) $entrant->getId()],
        ]);

        return $this->render('admin/competition/generate_spoof_entrants.html.twig', [
            'entrant'   => $entrant,
            'remaining' => max(0, $remaining),
            'actionUrl' => $actionUrl,
            'detailUrl' => $detailUrl,
        ]);
    }
}
