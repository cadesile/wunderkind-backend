<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Competition\CompetitionRoundReminderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron entry point (every 5 min — the reminder's own 15-minute lead time has plenty of
 * slack, unlike app:competition:process-rounds' 1-minute cadence).
 */
#[AsCommand(
    name: 'app:competition:send-round-reminders',
    description: 'Sends a "starting soon" push for every CompetitionRound due within its reminder lead time.',
)]
class CompetitionSendRoundRemindersCommand extends Command
{
    public function __construct(
        private readonly CompetitionRoundReminderService $reminderService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sent = $this->reminderService->sendDueReminders(new \DateTimeImmutable());

        $io->writeln($sent > 0 ? "Sent {$sent} round reminder(s)." : 'No round reminder is due.');

        return Command::SUCCESS;
    }
}
