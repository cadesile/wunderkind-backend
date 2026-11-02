<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\StarterConfigRepository;
use App\Repository\TranslationKeyRepository;
use App\Service\SetupTutorialValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only check of `starter_config.setup_tutorial_config`.
 *
 * Exists because the consumer deliberately reports nothing. The block is served verbatim as
 * `GET /api/starter-config`'s `tutorial`, and the app's `parseTutorial` cannot throw — setup
 * is the one route a new account cannot skip — so it drops anything malformed, of an unknown
 * kind, anchored to a step that does not exist, or naming a copy key it cannot resolve, item
 * by item, in silence. Without something like this, a typo is invisible until someone notices
 * a card is missing.
 *
 * Two halves, and they are not equally severe:
 *
 *  - **Structure** (`SetupTutorialValidator`) — the client's own drop rules. A problem here
 *    means an item will NOT render.
 *  - **Translation rows** — whether each `ngs.tut.*` key has a `TranslationKey`. A missing
 *    row does NOT stop the card rendering: the app ships all 34 keys in its bundled English
 *    catalogue and merges API translations on top of it. It stops the copy being reworded or
 *    localised without an app release, which is the whole point of the block living server
 *    side. So this half is a warning, never a failure.
 *
 * Exit code is 1 only for structural problems, so CI or a deploy can gate on it meaningfully.
 */
#[AsCommand(
    name: 'app:check-setup-tutorial',
    description: 'Report anything the app would silently drop from the setup tutorial block, plus missing translation rows.',
)]
class CheckSetupTutorialCommand extends Command
{
    public function __construct(
        private readonly StarterConfigRepository  $starterConfigRepository,
        private readonly TranslationKeyRepository $translationKeyRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $payload = $this->starterConfigRepository->getConfig()->getSetupTutorialConfig();

        $io->title('Setup tutorial — starter_config.setup_tutorial_config');

        if ($payload === []) {
            $io->warning('Empty. The app will show no setup tutorial at all — a valid "off" state.');

            return Command::SUCCESS;
        }

        $items     = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $briefings = 0;
        $notes     = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['kind'] ?? null) === 'briefing') {
                ++$briefings;
            } elseif (($item['kind'] ?? null) === 'note') {
                ++$notes;
            }
        }

        $io->definitionList(
            ['version' => var_export($payload['version'] ?? null, true)],
            ['items'   => sprintf('%d (%d briefings, %d notes)', count($items), $briefings, $notes)],
        );

        // ── Structure: what the client would drop ────────────────────────────────
        $problems = SetupTutorialValidator::problems($payload);

        if ($problems !== []) {
            $io->section(sprintf('%d structural problem(s) — these items will NOT render', count($problems)));
            $io->listing($problems);
        } else {
            $io->success('Structure: every item passes the rules the client applies.');
            $io->writeln(' <comment>Note this cannot tell you an anchor is on the WRONG step, only that the step exists.</comment>');
            $io->newLine();
        }

        // ── Translation rows: reword-ability, not render-ability ──────────────────
        $keys = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (['titleKey', 'bodyKey', 'ctaKey'] as $field) {
                $v = $item[$field] ?? null;
                if (is_string($v) && $v !== '') {
                    $keys[$v] = true;
                }
            }
            foreach (is_array($item['pointKeys'] ?? null) ? $item['pointKeys'] : [] as $k) {
                if (is_string($k) && $k !== '') {
                    $keys[$k] = true;
                }
            }
        }

        $missing = [];
        foreach (array_keys($keys) as $key) {
            if ($this->translationKeyRepository->findOneByKey($key) === null) {
                $missing[] = $key;
            }
        }
        sort($missing);

        $io->section(sprintf('Translation rows — %d of %d key(s) present', count($keys) - count($missing), count($keys)));

        if ($missing === []) {
            $io->success('Every copy key has a TranslationKey row, so the copy can be reworded without an app release.');
        } else {
            $io->warning(sprintf(
                "%d key(s) have no TranslationKey row. The cards still RENDER — the app ships these keys in its\n"
                . "bundled English catalogue and merges API translations on top of it — but the copy cannot be\n"
                . "reworded or localised without an app release.",
                count($missing),
            ));
            $io->listing($missing);
            $io->writeln(
                " To fix: import the app's <info>locales/en/translations.json</info> through"
                . " <info>/admin/translations/import</info>.\n"
                . " That file is the single source of these strings — do NOT re-type them here, or the same copy\n"
                . " exists in two repositories and drifts.",
            );
            $io->newLine();
        }

        return $problems === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
