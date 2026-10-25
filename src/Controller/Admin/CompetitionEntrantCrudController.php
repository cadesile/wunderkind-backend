<?php

namespace App\Controller\Admin;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Exception\SpoofSnapshotValidationException;
use App\Repository\Competition\ActiveCompetitionRepository;
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
 * Two deliberate exceptions to "never hand-edited", both admin test-data tools:
 *
 * - "Generate Spoof Entrants" lives on ActiveCompetitionCrudController (the competition
 *   row/detail page), not here — spoofing fills out a competition, so the trigger belongs
 *   at the competition level even though it clones an entrant's snapshot under the hood.
 * - "Create Spoof" lives HERE: it takes a hand-pasted club/players/staff/facilities
 *   snapshot (the same shape a real client POSTs to /api/competitions/{id}/register) and
 *   registers it into a chosen open competition, for precisely reproducing a specific test
 *   scenario rather than cloning/randomly varying an existing one. See
 *   CompetitionSpoofEntrantService::createSpoofEntrantFromSnapshot().
 *
 * `club.isSpoof` is surfaced on this index/detail so any spoof-generated entrant (from
 * either tool) stays identifiable from this debugging view.
 */
class CompetitionEntrantCrudController extends AbstractCrudController
{
    public function __construct(private readonly ActiveCompetitionRepository $activeCompetitionRepository) {}

    public static function getEntityFqcn(): string
    {
        return CompetitionEntrant::class;
    }

    /**
     * The page size is a deliberate guard, not a style choice.
     *
     * Every row on this index carries a full club snapshot — squad, staff, tactics, each
     * player's attributes and appearance — and Doctrine hydrates every mapped column of the
     * root entity, so listing N entrants decodes N whole snapshots into PHP arrays whether or
     * not a column renders them. That is what made this screen time out (504) in production.
     * There is no way to exclude the column: ORM 3 removed PARTIAL hydration, and the
     * property is read by 25 call sites across the match engine and results services, so it
     * cannot simply be unmapped.
     *
     * Halving the page therefore halves the decode cost, which is the only lever available
     * without restructuring. **It is proportional relief, not a cure** — if this screen still
     * times out, the permanent fix is to move `snapshotJson` into its own table behind a lazy
     * one-to-one, so the index never touches it and the detail page loads exactly one. That
     * is a data migration plus a change to the raw-SQL writes in
     * CompetitionRegistrationService, which is why it was not done blind: it needs a real
     * measurement of production snapshot sizes to justify, and no environment outside
     * production has representative rows.
     */
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['registeredAt' => 'DESC'])
            ->setPaginatorPageSize(10)
            ->setHelp('index', 'Each row carries a full squad snapshot, so this list is deliberately paginated small. Use the filters rather than paging through everything.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $createSpoof = Action::new('createSpoof', 'Create Spoof', 'fa fa-plus')
            ->createAsGlobalAction()
            ->linkToUrl(fn () => $this->generateUrl('admin', ['routeName' => 'admin_competition_entrant_create_spoof']));

        // EDIT is disabled, so EasyAdmin's default row-click fallback ([EDIT, DETAIL])
        // needs DETAIL explicitly present in the index actions to click through to it.
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $createSpoof);
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

    // A single extra path segment here (e.g. /admin/competition-entrant/create-spoof) would
    // collide with EasyAdmin's own pretty-URL detail route (/admin/competition-entrant/{entityId})
    // and get shadowed by it — Symfony matches that wildcard route first. This global action has
    // no entity to scope a two-segment path under (contrast admin_active_competition_generate_spoof,
    // which nests under a real {activeCompetition} id), so it lives under a distinct path segment
    // instead.
    #[Route('/admin/competition-entrants/create-spoof', name: 'admin_competition_entrant_create_spoof', methods: ['GET', 'POST'])]
    public function createSpoof(Request $request, CompetitionSpoofEntrantService $service): Response
    {
        $openCompetitions = $this->activeCompetitionRepository->findByStatus(ActiveCompetitionStatus::REGISTERING);
        $indexUrl         = $this->generateUrl('admin_competition_entrant_index');

        $formState = [
            'activeCompetitionId' => $request->request->get('activeCompetitionId', ''),
            'snapshot'            => (string) $request->request->get('snapshot', ''),
            'randomise'           => (bool) $request->request->get('randomise', false),
            'errors'              => [],
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('create_spoof_entrant', $request->request->get('_token'))) {
                $formState['errors'][] = 'Invalid CSRF token.';
            } elseif ($formState['activeCompetitionId'] === '') {
                $formState['errors'][] = 'Choose a competition to register this spoof club into.';
            } else {
                try {
                    $activeCompetition = $this->activeCompetitionRepository->find($formState['activeCompetitionId']);
                } catch (\Throwable) {
                    $activeCompetition = null;
                }
                if ($activeCompetition === null) {
                    $formState['errors'][] = 'That competition no longer exists.';
                } else {
                    $decoded = json_decode($formState['snapshot'], true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                        $formState['errors'][] = 'That is not valid JSON: ' . json_last_error_msg();
                    } else {
                        try {
                            $entrant = $service->createSpoofEntrantFromSnapshot($activeCompetition, $decoded, $formState['randomise']);

                            $this->addFlash('success', sprintf('Created spoof entrant for "%s".', $entrant->getClub()->getName()));

                            return $this->redirect($this->generateUrl('admin_competition_entrant_detail', ['entityId' => (string) $entrant->getId()]));
                        } catch (SpoofSnapshotValidationException $e) {
                            $formState['errors'] = $e->getViolations();
                        } catch (\RuntimeException $e) {
                            $formState['errors'][] = $e->getMessage();
                        }
                    }
                }
            }
        }

        return $this->render('admin/competition/create_spoof_entrant.html.twig', [
            'openCompetitions' => $openCompetitions,
            'formState'        => $formState,
            'indexUrl'         => $indexUrl,
        ]);
    }
}
