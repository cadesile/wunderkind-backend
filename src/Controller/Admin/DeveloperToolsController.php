<?php

namespace App\Controller\Admin;

use App\Entity\Academy;
use App\Service\EconomicService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/developer-tools')]
#[IsGranted('ROLE_ADMIN')]
class DeveloperToolsController extends AbstractController
{
    #[Route('/trigger-age21', name: 'admin_trigger_age21', methods: ['GET'])]
    public function triggerAge21Deletion(
        EconomicService $economicService,
        EntityManagerInterface $em,
    ): Response {
        $academies     = $em->getRepository(Academy::class)->findAll();
        $processedCount = 0;
        $deletedCount   = 0;

        foreach ($academies as $academy) {
            $playersBefore = $academy->getPlayers()->count();
            $economicService->checkAgeOutPlayers($academy, $academy->getLastSyncedWeek(), new \DateTimeImmutable());
            $deletedCount  += max(0, $playersBefore - $academy->getPlayers()->count());
            $processedCount++;
        }

        $this->addFlash('success', "Age-21 check run across {$processedCount} academies — {$deletedCount} player(s) removed.");

        return $this->redirectToRoute('admin_settings');
    }

    #[Route('/cleanup-entities', name: 'admin_cleanup_entities', methods: ['GET'])]
    public function cleanupEntities(KernelInterface $kernel): Response
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input  = new ArrayInput(['command' => 'app:cleanup:assigned-entities']);
        $output = new BufferedOutput();
        $application->run($input, $output);

        $result = $output->fetch();

        $this->addFlash('success', 'Cleanup command executed.');
        $this->addFlash('info', nl2br(htmlspecialchars(trim($result))));

        return $this->redirectToRoute('admin_settings');
    }

    #[Route('/reset-database', name: 'admin_reset_database', methods: ['GET'])]
    public function resetDatabase(): Response
    {
        // The reset script is interactive and must be run from the CLI.
        $this->addFlash('warning', 'Database reset must be run from the CLI:');
        $this->addFlash('info', 'bash scripts/reset_and_seed.sh');

        return $this->redirectToRoute('admin_settings');
    }
}
