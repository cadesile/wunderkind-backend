<?php

namespace App\Controller\Admin;

use App\Entity\FacilityTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class FacilityAdminController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/admin/facilities/{id}/quick-edit', name: 'admin_facility_quick_edit', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function quickEdit(Request $request, FacilityTemplate $facility): Response
    {
        if (!$this->isCsrfTokenValid('facility_qe_' . $facility->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_facilities_overview']));
        }

        $label = trim((string) $request->request->get('label', ''));
        if ($label !== '') {
            $facility->setLabel($label);
        }

        $description = trim((string) $request->request->get('description', ''));
        if ($description !== '') {
            $facility->setDescription($description);
        }

        $category = $request->request->get('category');
        if (in_array($category, ['TRAINING', 'MEDICAL', 'SCOUTING', 'STADIUM'], true)) {
            $facility->setCategory($category);
        }

        $baseCost = $request->request->get('baseCost');
        if ($baseCost !== null && $baseCost !== '') {
            $facility->setBaseCost((int) round((float) $baseCost * 100));
        }

        $weeklyUpkeepBase = $request->request->get('weeklyUpkeepBase');
        if ($weeklyUpkeepBase !== null && $weeklyUpkeepBase !== '') {
            $facility->setWeeklyUpkeepBase((int) round((float) $weeklyUpkeepBase * 100));
        }

        $maxLevel = $request->request->get('maxLevel');
        if ($maxLevel !== null && $maxLevel !== '') {
            $facility->setMaxLevel((int) $maxLevel);
        }

        $baseConstructionWeeks = $request->request->get('baseConstructionWeeks');
        if ($baseConstructionWeeks !== null && $baseConstructionWeeks !== '') {
            $facility->setBaseConstructionWeeks((int) $baseConstructionWeeks);
        }

        $sortOrder = $request->request->get('sortOrder');
        if ($sortOrder !== null && $sortOrder !== '') {
            $facility->setSortOrder((int) $sortOrder);
        }

        $reputationBonus = $request->request->get('reputationBonus');
        if ($reputationBonus !== null && $reputationBonus !== '') {
            $facility->setReputationBonus((float) $reputationBonus);
        }

        $decayBase = $request->request->get('decayBase');
        if ($decayBase !== null && $decayBase !== '') {
            $facility->setDecayBase((float) $decayBase);
        }

        $facility->setIsActive($request->request->has('isActive'));
        $facility->touch();

        $this->em->flush();

        $this->addFlash('success', sprintf('Facility "%s" updated.', $facility->getLabel()));
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_facilities_overview']));
    }
}
