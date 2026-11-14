<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Club;
use App\Entity\Club\ClubSale;
use App\Message\Club\SellClubMessage;
use App\Repository\Club\ClubSaleRepository;
use App\Service\Admin\MoneyInput;
use App\Service\Club\ClubSaleValuationService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The operator's side of a club buyout: see the figure, adjust it, authorise it.
 *
 * TWO ROUTES AND NO WORK DONE IN EITHER. The quote is a read; the POST writes one `club_sale`
 * row and dispatches one message. Nothing is credited and nothing is deleted inside a web
 * request — the handler does that off the `clubsale` queue. That is not only about latency: a
 * request that both pays money and deletes a club has no safe retry, and a browser will retry.
 *
 * `#[IsGranted('ROLE_ADMIN')]` on the class, on top of the `^/admin` firewall rule in
 * security.yaml. Both, deliberately: the firewall is the gate and the attribute is the statement,
 * matching DeleteAdminController and UserSessionController. Note the admin principal is
 * `App\Entity\Admin`, a different provider from `App\Entity\User` — the account being paid is
 * never the one authorising it.
 *
 * NEITHER ROUTE RENDERS AN @EasyAdmin TEMPLATE — one returns JSON, the other only ever redirects
 * — so both may use plain route names, the exemption src/Controller/Admin/CLAUDE.md describes and
 * that `admin_user_kill_session` already relies on. The redirect still goes through
 * AdminUrlGenerator so the page it lands on has its `ea` context.
 */
#[IsGranted('ROLE_ADMIN')]
class ClubSaleAdminController extends AbstractController
{
    public function __construct(
        private readonly ClubSaleValuationService  $valuation,
        private readonly ClubSaleRepository        $saleRepository,
        private readonly EntityManagerInterface    $em,
        private readonly MessageBusInterface       $bus,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly AdminUrlGenerator         $adminUrlGenerator,
    ) {}

    /**
     * What we would offer for this club, and the whole working behind it.
     *
     * Fetched by the overlay rather than rendered into the page, for the reason the delete modal
     * is built the same way: the user page lists every club a user owns, and inlining a full
     * breakdown per club would put a dozen of them in the HTML to show one.
     */
    #[Route('/admin/clubs/{id}/sale-quote', name: 'admin_club_sale_quote', methods: ['GET'])]
    public function quote(Club $club): Response
    {
        $existing = $this->saleRepository->findLiveForClub((string) $club->getId());
        $user     = $club->getUser();

        return $this->json([
            'clubId'    => (string) $club->getId(),
            'clubName'  => $club->getName(),
            'userEmail' => $user->getUserIdentifier(),
            'otherClubs' => $user->getClubs()
                ->filter(fn (Club $c): bool => $c->getId()->toBinary() !== $club->getId()->toBinary())
                ->map(fn (Club $c): string => $c->getName())
                ->getValues(),
            'breakdown' => $this->valuation->breakdown($club),
            // Non-null means the sell button should never have been offered — the overlay
            // refuses rather than trusting the page it was opened from, which may be stale.
            'existingSale' => $existing === null ? null : [
                'id'     => (string) $existing->getId(),
                'status' => $existing->getStatus()->value,
                'label'  => $existing->getStatus()->label(),
            ],
            'csrfToken' => $this->csrf->getToken('sell_club_' . $club->getId())->getValue(),
        ]);
    }

    /**
     * Authorise the buyout: record it, queue it, return.
     *
     * THE ROW IS WRITTEN BEFORE THE MESSAGE IS DISPATCHED, and that ordering is the point of the
     * row existing. The message carries nothing but an id, so the figure and the note must
     * already be durable when it goes out; an operator's authorisation of a payout cannot live
     * inside a Messenger envelope. It also means a dispatch that fails leaves a PENDING row an
     * operator can see and re-queue, rather than a decision that evaporated.
     */
    #[Route('/admin/clubs/{id}/sale', name: 'admin_club_sale', methods: ['POST'])]
    public function sell(Request $request, Club $club): Response
    {
        $user = $club->getUser();

        $backToUser = $this->redirect(
            $this->adminUrlGenerator
                ->setController(UserCrudController::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($user->getId())
                ->generateUrl(),
        );

        if (!$this->isCsrfTokenValid('sell_club_' . $club->getId(), (string) $request->request->get('_token'))) {
            // Every flash on this page renders as an upper-cased toast with its TYPE DISCARDED,
            // so `danger` looks exactly like `success` — see layout.html.twig. The wording has
            // to carry the outcome on its own.
            $this->addFlash('danger', 'Invalid CSRF token — nothing was sold and nobody was paid.');

            return $backToUser;
        }

        if ($this->saleRepository->findLiveForClub((string) $club->getId()) !== null) {
            // The partial unique index would refuse the insert anyway, but a flush violation
            // closes the EntityManager and turns an ordinary double-click into a 500. Checked
            // first for the same reason UserLedgerService::append() pre-checks its source ref.
            $this->addFlash('warning', sprintf(
                'A sale for %s already exists — nothing was queued a second time.',
                $club->getName(),
            ));

            return $backToUser;
        }

        // Recomputed here, never taken from the form. The breakdown the operator looked at was
        // produced by a separate request and the figure they typed is the only part of it we
        // accept; re-deriving the working means the stored audit is the model's own output and
        // not something a crafted POST could put there.
        $breakdown = $this->valuation->breakdown($club);
        $agreed    = MoneyInput::toPence($request->request->getString('agreedAmount'));

        if ($agreed === null) {
            // MoneyInput returns null for blank AND for unparseable, and both mean the same
            // thing here: no figure was authorised. It deliberately does not fall back to 0 —
            // a payout of nothing arrived at by mistyping is not a payout anyone approved.
            $this->addFlash('danger', sprintf(
                'Could not read an amount for %s — nothing was queued. Enter a figure in pounds.',
                $club->getName(),
            ));

            return $backToUser;
        }

        if ($agreed < 0) {
            $this->addFlash('danger', 'A buyout can only credit an account — nothing was queued.');

            return $backToUser;
        }

        $admin = $this->getUser();

        $sale = new ClubSale(
            $user,
            (string) $club->getId(),
            $club->getName(),
            (int) $breakdown['estimatePence'],
            $agreed,
            $breakdown,
            $request->request->getString('ledgerNote') ?: null,
            $admin instanceof Admin ? (string) $admin->getId() : null,
            $admin instanceof Admin ? $admin->getEmail() : null,
        );

        $this->em->persist($sale);
        $this->em->flush();

        $this->bus->dispatch(new SellClubMessage((string) $sale->getId()));

        $this->addFlash('success', sprintf(
            'Queued the sale of %s for £%s. The club is deleted when the worker runs.',
            $club->getName(),
            number_format($agreed / 100, 2),
        ));

        return $backToUser;
    }
}
