<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameEventTemplate;
use App\Repository\GameEventTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repairs event templates damaged by the old nested admin form, and normalises data the
 * client silently drops.
 *
 * Idempotent — a second run reports nothing to do. Defaults to a dry run; pass --apply to
 * write.
 */
#[AsCommand(
    name: 'app:events:repair',
    description: 'Repairs corrupted impacts, remaps non-existent personality traits, and migrates legacy severity values.',
)]
class EventsRepairCommand extends Command
{
    /**
     * Trait names used by older seed data that do not exist in the client's PersonalityMatrix,
     * mapped onto their closest equivalent in the real eight-trait set.
     */
    private const TRAIT_MAP = [
        'ego'        => 'ambition',
        'teamwork'   => 'adaptability',
        'leadership' => 'determination',
        'maturity'   => 'professionalism',
        'confidence' => 'pressure',
        'bravery'    => 'pressure',
    ];

    /** Severity vocabularies that predate the minor/major pair the client actually reads. */
    private const SEVERITY_MAP = [
        'low'    => 'minor',
        'medium' => 'minor',
        'high'   => 'major',
    ];

    public function __construct(
        private readonly GameEventTemplateRepository $repository,
        private readonly EntityManagerInterface      $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Write the changes. Without this the command only reports what it would do.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        $io->title($apply ? 'Repairing event templates' : 'Event template repair — dry run');

        $changed  = 0;
        $warnings = [];

        foreach ($this->repository->findAll() as $template) {
            $notes = [];

            $impacts = $template->getImpacts();
            $repaired = $this->repairImpacts($impacts, $template->getSlug(), $warnings);
            if ($repaired !== $impacts) {
                $notes[] = 'impacts';
                if ($apply) {
                    $template->setImpacts($repaired);
                }
            }

            $conditions = $template->getFiringConditions();
            if ($conditions !== null) {
                $repairedConditions = $this->repairFiringConditions($conditions);
                if ($repairedConditions !== $conditions) {
                    $notes[] = 'firingConditions';
                    if ($apply) {
                        $template->setFiringConditions($repairedConditions);
                    }
                }
            }

            $severity = $template->getSeverity();
            if ($severity !== null && isset(self::SEVERITY_MAP[$severity])) {
                $notes[] = sprintf('severity %s→%s', $severity, self::SEVERITY_MAP[$severity]);
                if ($apply) {
                    $template->setSeverity(self::SEVERITY_MAP[$severity]);
                }
            }

            if ($notes !== []) {
                $changed++;
                $io->writeln(sprintf('  <info>%s</info> — %s', $template->getSlug(), implode(', ', $notes)));
            }
        }

        if ($apply && $changed > 0) {
            // A json column whose value mixes PHP string and int types can fail Doctrine's
            // dirty check (see CLAUDE.md), so flush explicitly rather than relying on it.
            $this->em->flush();
        }

        foreach ($warnings as $warning) {
            $io->warning($warning);
        }

        if ($changed === 0) {
            $io->success('Nothing to repair.');
            return Command::SUCCESS;
        }

        $apply
            ? $io->success(sprintf('Repaired %d template(s).', $changed))
            : $io->note(sprintf('%d template(s) would change. Re-run with --apply to write.', $changed));

        return Command::SUCCESS;
    }

    /**
     * Undoes the old admin form's damage and normalises trait names.
     *
     * The form re-encoded a flat impacts array as {"0":…,"1":…} and bolted on empty
     * sub-structures for every section it rendered. Numeric entries are lifted back out and
     * expressed in the canonical stat_changes form, which every client engine understands;
     * the null-filled stubs are dropped, and any sub-structure carrying real values is kept.
     *
     * @param string[] $warnings
     */
    private function repairImpacts(array $impacts, string $slug, array &$warnings): array
    {
        $isList = array_is_list($impacts);

        // A flat array only ever needs its trait names remapping.
        if ($isList) {
            return array_map(fn (array $entry) => $this->remapLegacyTarget($entry), $impacts);
        }

        $numbered = [];
        $rest     = [];

        foreach ($impacts as $key => $value) {
            if (is_int($key) || (is_string($key) && ctype_digit($key))) {
                $numbered[(int) $key] = $value;
            } else {
                $rest[$key] = $value;
            }
        }

        // Drop the empty sub-structures the form injected, keep anything with real content.
        foreach ($rest as $key => $value) {
            if ($this->isEmptyStructure($value)) {
                unset($rest[$key]);
            }
        }

        if (isset($rest['stat_changes'])) {
            $rest['stat_changes'] = array_values(array_filter(
                $rest['stat_changes'],
                fn ($change) => !$this->isEmptyStructure($change),
            ));
            if ($rest['stat_changes'] === []) {
                unset($rest['stat_changes']);
            }
        }

        if ($numbered === []) {
            return $rest === [] ? [] : $this->remapTraitsDeep($rest);
        }

        ksort($numbered);
        $converted = [];

        foreach ($numbered as $entry) {
            $change = $this->legacyEntryToStatChange($this->remapLegacyTarget($entry));

            if ($change === null) {
                $warnings[] = sprintf(
                    '%s: impact target "%s" cannot be expressed as a stat_change and was left as-is. Repair it by hand.',
                    $slug,
                    $entry['target'] ?? '?',
                );
                // Bail out on this template rather than silently dropping an effect.
                return $impacts;
            }

            $converted[] = $change;
        }

        $rest['stat_changes'] = array_merge($converted, $rest['stat_changes'] ?? []);

        return $this->remapTraitsDeep($rest);
    }

    /** Rewrites a legacy `{target, delta}` pair into the canonical stat_changes item. */
    private function legacyEntryToStatChange(array $entry): ?array
    {
        $target = (string) ($entry['target'] ?? '');
        $delta  = $entry['delta'] ?? null;

        if ($target === '' || !is_numeric($delta)) {
            return null;
        }

        $mapped = match (true) {
            $target === 'squad.morale'      => ['squad_wide', 'morale'],
            $target === 'pair.relationship' => ['pair', 'relationship'],
            str_starts_with($target, 'player_') => (function () use ($target) {
                [$slot, $field] = explode('.', $target, 2) + [1 => ''];
                return $field === '' ? null : [$slot, $field];
            })(),
            // club.reputation and player.injuredWeeks have no stat_changes equivalent.
            default => null,
        };

        if ($mapped === null) {
            return null;
        }

        return [
            'target'   => $mapped[0],
            'field'    => $mapped[1],
            'operator' => $delta < 0 ? 'subtract' : 'add',
            'value'    => abs($delta),
        ];
    }

    /**
     * Normalises a legacy dotted target: slots an unqualified `player.` prefix and renames
     * any non-existent trait.
     *
     * The client resolves impact targets through an entity map whose only player keys are
     * `player_1`, `player_2`, … — a bare `player.` prefix matches nothing and the effect is
     * dropped without a word.
     */
    private function remapLegacyTarget(array $entry): array
    {
        $target = $entry['target'] ?? null;

        if (is_string($target) && str_starts_with($target, 'player.')) {
            $target = $entry['target'] = 'player_1.' . substr($target, strlen('player.'));
        }

        if (is_string($target) && str_contains($target, '.personality.')) {
            [$prefix, $trait] = explode('.personality.', $target, 2);
            if (isset(self::TRAIT_MAP[$trait])) {
                $entry['target'] = $prefix . '.personality.' . self::TRAIT_MAP[$trait];
            }
        }

        return $entry;
    }

    /** Renames non-existent traits anywhere in a nested impacts structure. */
    private function remapTraitsDeep(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->remapTraitsDeep($value);
                continue;
            }

            if ($key === 'field' && is_string($value) && str_starts_with($value, 'personality.')) {
                $trait = substr($value, strlen('personality.'));
                if (isset(self::TRAIT_MAP[$trait])) {
                    $data[$key] = 'personality.' . self::TRAIT_MAP[$trait];
                }
            }

            if ($key === 'target' && is_string($value) && str_contains($value, '.personality.')) {
                $data[$key] = $this->remapLegacyTarget(['target' => $value])['target'];
            }
        }

        return $data;
    }

    /**
     * Remaps trait requirements and rescales their thresholds.
     *
     * Older data expresses trait requirements on a 0–100 scale; the client's matrix is 1–20.
     * Note this applies to thresholds only — impact deltas were always on the 1–20 scale.
     */
    private function repairFiringConditions(array $conditions): array
    {
        foreach (['actorTraitRequirements', 'subjectTraitRequirements'] as $key) {
            if (!isset($conditions[$key]) || !is_array($conditions[$key])) {
                continue;
            }

            $conditions[$key] = array_map(function (array $requirement) {
                $trait = $requirement['trait'] ?? null;

                if (is_string($trait) && isset(self::TRAIT_MAP[$trait])) {
                    $requirement['trait'] = self::TRAIT_MAP[$trait];
                }

                // A threshold above the matrix ceiling is 0–100 data, whatever the trait is
                // called — real trait names carry the old scale too.
                foreach (['min', 'max'] as $bound) {
                    if (isset($requirement[$bound])
                        && is_numeric($requirement[$bound])
                        && $requirement[$bound] > 20) {
                        $requirement[$bound] = $this->rescaleToMatrix((float) $requirement[$bound]);
                    }
                }

                return $requirement;
            }, $conditions[$key]);
        }

        return $conditions;
    }

    /** 0–100 → 1–20, clamped. */
    private function rescaleToMatrix(float $value): int
    {
        return max(1, min(20, (int) round(1 + ($value * 19 / 100))));
    }

    /** True when a value is null, an empty array, or a structure whose every leaf is null. */
    private function isEmptyStructure(mixed $value): bool
    {
        if ($value === null || $value === []) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                if (!$this->isEmptyStructure($item)) {
                    return false;
                }
                continue;
            }

            if ($item === null) {
                continue;
            }

            // Values the empty form submitted by default carry no intent on their own: a
            // selection_logic of {target_type: player, count: null, filter: all-null} behaves
            // exactly as if it were absent.
            if ($item === false
                || ($key === 'operator' && $item === 'add')
                || ($key === 'target_type' && $item === 'player')) {
                continue;
            }

            return false;
        }

        return true;
    }
}
