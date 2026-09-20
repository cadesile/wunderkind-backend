<?php

namespace App\Command;

use App\Message\ResolveNewCompetitionAudienceForPushMessage;
use App\Repository\Competition\CompetitionTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Cron entry point (every 10 min — not time-precision sensitive): ensures every active
 * CompetitionTemplate has an open (REGISTERING) instance. Registration-fill itself locks
 * an instance synchronously (see CompetitionLockService, called from
 * CompetitionRegistrationService), so this command's only job is replenishing the slot
 * that leaves behind.
 *
 * Same idiom as registration's idempotency: raw INSERT ... ON CONFLICT DO NOTHING against
 * the partial unique index (uq_active_competition_one_open_per_template), not flush+catch.
 */
#[AsCommand(
    name: 'app:competition:provision-instances',
    description: 'Ensures every active CompetitionTemplate has an open (REGISTERING) instance.',
)]
class CompetitionProvisionInstancesCommand extends Command
{
    public function __construct(
        private readonly CompetitionTemplateRepository $templateRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $now          = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $provisioned   = 0;

        foreach ($this->templateRepository->findAllActive() as $template) {
            $id       = new UuidV7();
            $affected = $this->em->getConnection()->executeStatement(
                <<<'SQL'
                INSERT INTO active_competition
                    (id, template_id, status, entrant_capacity, duration_option, registration_opened_at, created_at)
                VALUES (:id, :templateId, 'registering', :capacity, :duration, :now, :now)
                ON CONFLICT (template_id) WHERE (status = 'registering') DO NOTHING
                SQL,
                [
                    'id'         => $id->toRfc4122(),
                    'templateId' => $template->getId()->toRfc4122(),
                    'capacity'   => $template->getEntrantCapacity(),
                    'duration'   => $template->getDurationOption()->value,
                    'now'        => $now,
                ],
            );

            if ($affected > 0) {
                $provisioned++;
                $io->writeln("Provisioned instance for template: {$template->getName()}");
                $this->messageBus->dispatch(new ResolveNewCompetitionAudienceForPushMessage($id->toRfc4122()));
            }
        }

        $io->success("Provisioned {$provisioned} new instance(s).");

        return Command::SUCCESS;
    }
}
