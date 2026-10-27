<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionResponseCache;
use App\Repository\Competition\ActiveCompetitionRepository;
use App\Repository\Competition\CompetitionResponseCacheRepository;
use App\Service\Competition\CompetitionResponseBuilder;
use App\Service\Competition\CompetitionResponseCacheService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:competition:cache:warm',
    description: 'Prebuild the viewer-independent GET /api/competitions/{id} document for live competitions.',
)]
class WarmCompetitionResponseCacheCommand extends Command
{
    public function __construct(
        private readonly ActiveCompetitionRepository $activeCompetitionRepository,
        private readonly CompetitionResponseCacheRepository $cacheRepository,
        private readonly CompetitionResponseBuilder $responseBuilder,
        private readonly CompetitionResponseCacheService $responseCache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'stale-only',
                null,
                InputOption::VALUE_NONE,
                'Only rebuild documents stored under a superseded payload version — what to run after a deploy that changes the response shape.',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Rebuild even when the stored document is already current.',
            )
            ->setHelp(<<<'TXT'
                The cron passes that change a bracket already rebuild their own competition, so
                this command is for the cases they cannot cover:

                  * after a deploy that changes CompetitionResponseCache::PAYLOAD_VERSION, when
                    every stored document is unreadable by the new code and would otherwise be
                    rebuilt one at a time by whichever request arrived first (--stale-only);
                  * after restoring a database, or any time you want live competitions warm
                    without waiting for their next draw or results tick.

                Nothing here is required for correctness. The cache validates itself against
                each competition's state version, so a cold or superseded document is a miss,
                never a wrong answer.
                TXT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $staleOnly = (bool) $input->getOption('stale-only');
        $force     = (bool) $input->getOption('force');

        $competitions = $staleOnly
            ? $this->staleCompetitions()
            // Completed competitions are deliberately not swept. Their document can never
            // change again, so one is either already stored or will be built once by the
            // first reader — whereas sweeping them would grow with every tournament ever run.
            : $this->activeCompetitionRepository->findActive();

        if ($competitions === []) {
            $io->success($staleOnly ? 'No documents are on a superseded payload version.' : 'No live competitions to warm.');

            return Command::SUCCESS;
        }

        $built   = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($competitions as $competition) {
            $label = sprintf('%s (%s)', $competition->getTemplate()->getName(), $competition->getId());

            try {
                $stateVersion = $this->responseBuilder->stateVersion($competition);

                if (!$force && $this->responseCache->isCached($competition, $stateVersion)) {
                    $skipped++;
                    continue;
                }

                $this->responseCache->rebuild(
                    $competition,
                    $stateVersion,
                    fn (): array => $this->responseBuilder->sharedDocument($competition),
                );
                $built++;
            } catch (\Throwable $e) {
                // One competition's failure must not abandon the rest — a single bad row is
                // exactly the situation a bulk warm exists to work around.
                $failed++;
                $io->warning(sprintf('%s: %s', $label, $e->getMessage()));
            }
        }

        $io->success(sprintf('Built %d, already current %d, failed %d.', $built, $skipped, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<ActiveCompetition> */
    private function staleCompetitions(): array
    {
        $ids = $this->cacheRepository->findStalePayloadVersionIds(CompetitionResponseCache::PAYLOAD_VERSION);

        $competitions = [];
        foreach ($ids as $id) {
            $competition = $this->activeCompetitionRepository->find($id);
            if ($competition !== null) {
                $competitions[] = $competition;
            }
        }

        return $competitions;
    }
}
