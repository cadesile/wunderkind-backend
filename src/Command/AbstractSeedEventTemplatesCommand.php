<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameEventTemplate;
use App\Enum\EventCategory;
use App\Repository\GameEventTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared upsert loop for the event-template seeders.
 *
 * By default an existing slug is left alone, so a re-run cannot clobber templates edited in
 * the admin. That also means edits to seed content never reach a database that has already
 * been seeded — pass --update when the intent is to push corrected definitions out.
 */
abstract class AbstractSeedEventTemplatesCommand extends Command
{
    public function __construct(
        protected readonly GameEventTemplateRepository $repository,
        protected readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    /**
     * @return array<int, array{slug: string, category: EventCategory, weight: int, title: string, bodyTemplate: string, impacts: array, firingConditions?: array|null, severity?: string|null, noInteract?: bool}>
     */
    abstract protected function buildTemplates(): array;

    /** Noun used in the summary line, e.g. "player event". */
    abstract protected function templateLabel(): string;

    protected function configure(): void
    {
        $this->addOption(
            'update',
            null,
            InputOption::VALUE_NONE,
            'Overwrite templates whose slug already exists, instead of skipping them.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $update = (bool) $input->getOption('update');

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($this->buildTemplates() as $data) {
            $template = $this->repository->findBySlug($data['slug']);

            if ($template !== null && !$update) {
                $io->note("Skipping existing slug: {$data['slug']}");
                $skipped++;
                continue;
            }

            if ($template === null) {
                $template = new GameEventTemplate($data['slug']);
                $this->em->persist($template);
                $created++;
            } else {
                $io->writeln("  Updating: {$data['slug']}");
                $updated++;
            }

            $template->setCategory($data['category']);
            $template->setTitle($data['title']);
            $template->setBodyTemplate($data['bodyTemplate']);
            $template->setImpacts($data['impacts']);
            $template->setWeight($data['weight']);
            $template->setFiringConditions($data['firingConditions'] ?? null);
            $template->setSeverity($data['severity'] ?? null);
            $template->setNoInteract($data['noInteract'] ?? false);
        }

        $this->em->flush();

        $io->success(sprintf(
            'Seeded %d %s template(s). Updated %d. Skipped %d existing.',
            $created,
            $this->templateLabel(),
            $updated,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
