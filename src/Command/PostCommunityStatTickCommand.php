<?php

namespace App\Command;

use App\Enum\StatsPeriod;
use App\Repository\GameConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron entry point: checks every period tier's admin-configured auto-post schedule
 * (GameConfig::statPostSchedule/statPostLastRunAt) and dispatches app:post-community-stat
 * for any period whose configured interval has elapsed. This is what makes posting
 * cadence editable from the admin UI without a redeploy — the OS crontab only ever
 * invokes this tick command on one fixed, fine-grained schedule (see Dockerfile);
 * app:post-community-stat and its period argument are unchanged and still directly
 * invokable for manual/ops use.
 */
#[AsCommand(
    name: 'app:post-community-stat-tick',
    description: 'Runs app:post-community-stat for every period tier whose admin-configured auto-post interval is due.',
)]
class PostCommunityStatTickCommand extends Command
{
    public function __construct(
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $config = $this->gameConfigRepository->getConfig(flush: true);
        $now = new \DateTimeImmutable();

        $due = array_filter(StatsPeriod::cases(), fn (StatsPeriod $period) => $config->isAutoPostDue($period, $now));

        if (empty($due)) {
            $io->writeln('No period is due for auto-posting.');
            return Command::SUCCESS;
        }

        $application = $this->getApplication();
        if ($application === null) {
            throw new \LogicException('No console application available to dispatch app:post-community-stat.');
        }
        $postCommand = $application->find('app:post-community-stat');

        $anyFailure = false;

        foreach ($due as $period) {
            $io->section("Auto-post due for period: {$period->value}");
            $exitCode = $postCommand->run(new ArrayInput(['period' => $period->value]), $output);
            if ($exitCode !== Command::SUCCESS) {
                $anyFailure = true;
            }

            // Mark as run regardless of the sub-command's own outcome, so a transient
            // posting failure or an empty-connections run waits a full interval before
            // being retried, same as a period that succeeded.
            $config->markAutoPostRun($period, $now);
        }

        $this->em->flush();

        return $anyFailure ? Command::FAILURE : Command::SUCCESS;
    }
}
