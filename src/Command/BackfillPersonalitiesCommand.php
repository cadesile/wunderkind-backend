<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PersonalityProfile;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Service\Personality\PersonalityContext;
use App\Service\Personality\PersonalityGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rolls a Personality Matrix for pool rows still sitting at the all-10s default.
 *
 * Two gaps leave rows ungenerated, and nothing else ever closes them:
 *
 *  - Player: Version20260419160039 added the eight personality_* columns
 *    DEFAULT 10 NOT NULL with no UPDATE backfill. Only PlayerGenerationService
 *    rolls a matrix, and only for players it generates — every pre-existing pool
 *    row keeps the default forever. PersonalityLifecycleSubscriber deliberately
 *    excludes Player, so there is no lazy path either.
 *  - Staff/Scout: Version20260823000000 added their columns the same way. The
 *    subscriber does fill these, but only on prePersist — rows already in the
 *    table are never re-persisted, so they are never filled.
 *
 * This matters because the starter squad and NPC rosters are drawn from the
 * persisted pool rather than generated on demand (StarterPackService), so those
 * defaults are served to real clients. An all-10s matrix normalises to 50 on
 * every trait, which scores identically against every archetype — a whole squad
 * ends up sharing one strength/flaw pair.
 *
 * ⚠️ The default doubles as the "not generated" sentinel (PersonalityProfile::
 * isDefault), so a genuine all-10s roll is indistinguishable from an ungenerated
 * one and would be re-rolled here. The generator's Gaussian roll across eight
 * independent traits makes that outcome vanishingly unlikely.
 *
 * Run this BEFORE bumping WorldInitializationService::WORLD_PACK_VERSION —
 * rebuilding the world-pack cache first would just re-serialise the defaults.
 */
#[AsCommand(
    name: 'app:backfill-personalities',
    description: 'Generate a personality matrix for pool rows still at the all-10s default',
)]
final class BackfillPersonalitiesCommand extends Command
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly PersonalityGeneratorService $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report how many rows would be backfilled without writing anything.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry run — no changes will be written.');
        }

        $total = 0;

        foreach ([Player::class, Staff::class, Scout::class] as $class) {
            $rows    = $this->findDefaultProfiles($class);
            $count   = count($rows);
            $total  += $count;
            $io->text(sprintf('%s: %d row(s) at the default matrix', $class, $count));

            if ($dryRun || $count === 0) {
                continue;
            }

            $n = 0;
            foreach ($rows as $entity) {
                $this->generator->apply($entity->getPersonality(), $this->contextFor($entity));
                if (++$n % self::BATCH_SIZE === 0) {
                    $this->em->flush();
                }
            }
            $this->em->flush();
            $this->em->clear();
            $io->text(sprintf('%s: %d row(s) backfilled', $class, $count));
        }

        $io->success(sprintf(
            $dryRun ? '%d row(s) would be backfilled.' : 'Personality backfill complete — %d row(s) updated.',
            $total,
        ));

        return Command::SUCCESS;
    }

    /**
     * Rows whose eight traits all still equal DEFAULT_TRAIT. Filtered in DQL so a
     * large pool is not hydrated in full just to discard most of it.
     *
     * @param class-string $class
     * @return list<Player|Staff|Scout>
     */
    private function findDefaultProfiles(string $class): array
    {
        $qb = $this->em->getRepository($class)->createQueryBuilder('e');

        foreach (PersonalityGeneratorService::TRAITS as $i => $trait) {
            $qb->andWhere(sprintf('e.personality.%s = :d%d', $trait, $i))
               ->setParameter(sprintf('d%d', $i), PersonalityProfile::DEFAULT_TRAIT);
        }

        return $qb->getQuery()->getResult();
    }

    private function contextFor(Player|Staff|Scout $entity): PersonalityContext
    {
        return match (true) {
            $entity instanceof Player => PersonalityContext::forPlayer($entity->getAge()),
            $entity instanceof Staff  => PersonalityContext::forStaff($entity->getRole()),
            $entity instanceof Scout  => PersonalityContext::forScout(),
        };
    }
}
