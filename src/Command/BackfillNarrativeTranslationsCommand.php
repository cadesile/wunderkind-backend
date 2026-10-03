<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\TranslatableEntityType;
use App\Repository\ExcursionRepository;
use App\Repository\FacilityTemplateRepository;
use App\Repository\GameEventTemplateRepository;
use App\Service\NarrativeTranslationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Find-or-creates a TranslationKey for every translatable field of every existing
 * GameEventTemplate/FacilityTemplate/Excursion row.
 *
 * This only guarantees key EXISTENCE — there is no default-language (EN) Translation row to
 * backfill, since EN is always read live off the entity (see NarrativeTranslationService).
 * It exists so the admin's generic-key list and bulk tools see every narrative key from day
 * one, even for rows no admin has opened the "Translations" quick-edit screen for yet (that
 * screen's own GET action also calls ensureKeysForEntity() eagerly, so it self-heals on
 * first visit regardless — this command just covers never-visited rows too).
 *
 * Idempotent (find-or-create) and cheap — narrative row counts are nowhere near the scale
 * that excluded app:backfill-appearances from the standard deploy sequence.
 */
#[AsCommand(
    name: 'app:backfill-narrative-translations',
    description: 'Ensures a TranslationKey exists for every translatable field of every existing event/facility/excursion row. Safe to re-run.',
)]
class BackfillNarrativeTranslationsCommand extends Command
{
    public function __construct(
        private readonly GameEventTemplateRepository $eventTemplateRepository,
        private readonly FacilityTemplateRepository   $facilityTemplateRepository,
        private readonly ExcursionRepository           $excursionRepository,
        private readonly NarrativeTranslationService   $translationService,
        private readonly EntityManagerInterface         $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $count = 0;

        foreach ($this->eventTemplateRepository->findAll() as $t) {
            $this->translationService->ensureKeysForEntity(TranslatableEntityType::GAME_EVENT_TEMPLATE, $t->getSlug());
            $count++;
        }
        foreach ($this->facilityTemplateRepository->findAll() as $t) {
            $this->translationService->ensureKeysForEntity(TranslatableEntityType::FACILITY_TEMPLATE, $t->getSlug());
            $count++;
        }
        foreach ($this->excursionRepository->findAll() as $t) {
            $this->translationService->ensureKeysForEntity(TranslatableEntityType::EXCURSION, $t->getSlug());
            $count++;
        }

        $this->em->flush();

        $io->success(sprintf('Ensured translation keys for %d narrative rows.', $count));

        return Command::SUCCESS;
    }
}
