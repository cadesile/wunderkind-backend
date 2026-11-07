<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\Setup\SetupQuizAnswerRepository;
use App\Repository\Setup\SetupQuizQuestionRepository;
use App\Service\Setup\SetupQuizImportExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Export and import the onboarding quiz.
 *
 * A PER-DOMAIN PAGE, like admin_config_content and admin_world_content, reachable from the
 * Import / Export hub's card and from the Setup quiz list. The hub exists because four separate
 * sidebar entries all called "Import / Export" were impossible to navigate; this adds a fifth
 * card there rather than a fifth entry.
 *
 * THE PAGE EXISTS TO CARRY THE WARNINGS. A quiz export contains `correctIndex` — the answer key
 * for a bonus that multiplies a player's opening credit — so unlike the config export it must
 * not be committed to a public repository or handed to a player. And a question cannot be
 * deleted safely, because answers cascade off it. Neither of those fits on a button.
 *
 * Every write is POST-only and CSRF-guarded, and every redirect goes through the `admin` entry
 * point per src/Controller/Admin/CLAUDE.md.
 */
#[IsGranted('ROLE_ADMIN')]
class SetupQuizImportExportController extends AbstractController
{
    public function __construct(
        private readonly SetupQuizImportExportService $service,
        private readonly SetupQuizQuestionRepository $questionRepository,
        private readonly SetupQuizAnswerRepository $answerRepository,
    ) {}

    #[Route('/admin/setup-quiz/content', name: 'admin_setup_quiz_content', methods: ['GET'])]
    public function overview(): Response
    {
        $all = $this->questionRepository->findAll();

        return $this->render('admin/setup_quiz_content.html.twig', [
            'total'    => \count($all),
            'active'   => \count(array_filter($all, static fn ($q): bool => $q->isActive())),
            // Answerable, not merely active — a question whose correct answer does not exist
            // among its options is served to nobody, and an operator staring at "12 active, 11
            // served" needs that difference visible rather than inferred.
            'servable' => \count($this->questionRepository->findServable()),
            // The reason import never deletes. If this is non-zero, a delete would be taking
            // real player history with it.
            'answers'  => $this->answerRepository->count([]),
        ]);
    }

    /**
     * Download every question, active and withdrawn.
     *
     * GET, and safe to be: it reads and streams and writes nothing. The file it produces is
     * not safe to publish, which is the page's business to say, not this route's.
     */
    #[Route('/admin/setup-quiz/export', name: 'admin_setup_quiz_export', methods: ['GET'])]
    public function export(): StreamedResponse
    {
        $json = (string) json_encode(
            $this->service->export(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $response = new StreamedResponse(static function () use ($json): void {
            echo $json;
        });

        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'wunderkind-setup-quiz-' . date('Y-m-d') . '.json',
        ));

        return $response;
    }

    #[Route('/admin/setup-quiz/import', name: 'admin_setup_quiz_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('setup_quiz_import', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->backToScreen();
        }

        $file = $request->files->get('quiz_file');

        if ($file === null || !$file->isValid()) {
            $this->addFlash('danger', 'No valid file uploaded.');

            return $this->backToScreen();
        }

        $raw = file_get_contents($file->getPathname());

        if ($raw === false) {
            $this->addFlash('danger', 'Could not read the uploaded file.');

            return $this->backToScreen();
        }

        $data = json_decode($raw, true);

        if (!\is_array($data)) {
            $this->addFlash('danger', 'Invalid JSON — could not parse the uploaded file.');

            return $this->backToScreen();
        }

        $dryRun = $request->request->getBoolean('dry_run');

        $result = $this->service->import(
            $data,
            $dryRun,
            $request->request->getBoolean('deactivate_missing'),
        );

        foreach ($result['errors'] as $error) {
            $this->addFlash('warning', $error);
        }

        $touched = $result['created'] + $result['updated'] + $result['deactivated'];

        if ($touched === 0 && $result['unchanged'] === 0) {
            $this->addFlash('danger', 'Nothing was imported — see the messages above.');

            return $this->backToScreen();
        }

        $this->addFlash($dryRun ? 'info' : 'success', \sprintf(
            '%s %d added, %d updated, %d unchanged, %d withdrawn, %d skipped.',
            $dryRun ? 'Dry run —' : 'Imported:',
            $result['created'],
            $result['updated'],
            $result['unchanged'],
            $result['deactivated'],
            $result['skipped'],
        ));

        return $this->backToScreen();
    }

    private function backToScreen(): Response
    {
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_setup_quiz_content']));
    }
}
