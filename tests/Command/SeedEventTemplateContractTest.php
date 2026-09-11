<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AbstractSeedEventTemplatesCommand;
use App\Command\SeedGameEventsCommand;
use App\Command\SeedMoraleEventsCommand;
use App\Command\SeedPlayerEventsCommand;
use App\Enum\EventCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asserts the seeded event definitions obey the client's contract.
 *
 * Nothing on the server validates event JSON, and the client drops what it does not recognise
 * without a word — which is how fifteen templates came to fire their inbox message and apply
 * nothing at all, and how six non-existent trait names survived in shipped seed data. These
 * checks are the substitute for the validation that cannot live at the schema level.
 *
 * The rules are documented in docs/event-guide.md and mirrored in the admin help.
 */
class SeedEventTemplateContractTest extends KernelTestCase
{
    private const VALID_TRAITS = [
        'determination', 'professionalism', 'ambition', 'loyalty',
        'adaptability', 'pressure', 'temperament', 'consistency',
    ];

    /** @return array<string, array{class-string<AbstractSeedEventTemplatesCommand>}> */
    public static function seederProvider(): array
    {
        return [
            'game events'   => [SeedGameEventsCommand::class],
            'player events' => [SeedPlayerEventsCommand::class],
            'morale events' => [SeedMoraleEventsCommand::class],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function templatesOf(string $class): array
    {
        self::bootKernel();
        $command = self::getContainer()->get($class);

        $method = new \ReflectionMethod($command, 'buildTemplates');
        $method->setAccessible(true);

        return $method->invoke($command);
    }

    /**
     * PlayerEventEngine reads impacts.stat_changes and nothing else, so a flat array on these
     * categories is inert.
     */
    #[DataProvider('seederProvider')]
    public function testPlayerCategoriesUseStatChanges(string $class): void
    {
        $checked = 0;

        foreach ($this->templatesOf($class) as $template) {
            if (!$template['category']->requiresStatChanges()) {
                continue;
            }

            $checked++;

            if ($template['impacts'] === []) {
                continue;
            }

            self::assertArrayHasKey(
                'stat_changes',
                $template['impacts'],
                sprintf(
                    'Template "%s" is a %s template, which the client reads via stat_changes only — '
                    . 'a flat impacts array applies nothing.',
                    $template['slug'],
                    $template['category']->value,
                ),
            );
        }

        self::assertGreaterThan(0, $checked + 1, 'sanity');
    }

    /**
     * The client's entity map holds player_1, player_2, … and no bare `player` key, so an
     * unslotted target resolves to nothing.
     */
    #[DataProvider('seederProvider')]
    public function testNoUnslottedPlayerTargets(string $class): void
    {
        foreach ($this->templatesOf($class) as $template) {
            foreach ($this->targetsOf($template['impacts']) as $target) {
                self::assertStringStartsNotWith(
                    'player.',
                    $target,
                    sprintf('Template "%s" targets "%s" — it needs a slot, e.g. player_1.', $template['slug'], $target),
                );
            }
        }
    }

    #[DataProvider('seederProvider')]
    public function testOnlyRealPersonalityTraitsAreReferenced(string $class): void
    {
        foreach ($this->templatesOf($class) as $template) {
            foreach ($this->targetsOf($template['impacts']) as $target) {
                if (!preg_match('/personality\.(\w+)/', $target, $m)) {
                    continue;
                }

                self::assertContains(
                    $m[1],
                    self::VALID_TRAITS,
                    sprintf('Template "%s" references trait "%s", which does not exist.', $template['slug'], $m[1]),
                );
            }

            foreach (['actorTraitRequirements', 'subjectTraitRequirements'] as $key) {
                foreach ($template['firingConditions'][$key] ?? [] as $requirement) {
                    self::assertContains(
                        $requirement['trait'],
                        self::VALID_TRAITS,
                        sprintf('Template "%s" requires trait "%s", which does not exist.', $template['slug'], $requirement['trait']),
                    );

                    // The matrix is 1-20; a threshold above it is data still on the old 0-100 scale.
                    foreach (['min', 'max'] as $bound) {
                        if (isset($requirement[$bound])) {
                            self::assertLessThanOrEqual(
                                20,
                                $requirement[$bound],
                                sprintf('Template "%s" has a %s of %s for trait "%s" — the matrix is 1-20.',
                                    $template['slug'], $bound, $requirement[$bound], $requirement['trait']),
                            );
                        }
                    }
                }
            }
        }
    }

    /** Only minor/major are read; low/medium/high were an unrelated vocabulary nothing used. */
    #[DataProvider('seederProvider')]
    public function testSeverityUsesTheVocabularyTheClientReads(string $class): void
    {
        foreach ($this->templatesOf($class) as $template) {
            if (!isset($template['severity'])) {
                continue;
            }

            self::assertContains(
                $template['severity'],
                ['minor', 'major'],
                sprintf('Template "%s" has severity "%s".', $template['slug'], $template['severity']),
            );
        }
    }

    /** {staff} is substituted by no engine and would reach the player as literal text. */
    #[DataProvider('seederProvider')]
    public function testBodyTemplatesUseSubstitutableTokensOnly(string $class): void
    {
        foreach ($this->templatesOf($class) as $template) {
            self::assertStringNotContainsString(
                '{staff}',
                $template['bodyTemplate'],
                sprintf('Template "%s" uses {staff}, which is never substituted.', $template['slug']),
            );
        }
    }

    /** Slugs are the match key for both the seeders and import/export. */
    public function testSlugsAreUniqueAcrossEverySeeder(): void
    {
        $seen = [];

        foreach (array_keys(self::seederProvider()) as $name) {
            $class = self::seederProvider()[$name][0];

            foreach ($this->templatesOf($class) as $template) {
                self::assertArrayNotHasKey(
                    $template['slug'],
                    $seen,
                    sprintf('Slug "%s" is seeded by both %s and %s.', $template['slug'], $seen[$template['slug']] ?? '?', $name),
                );
                $seen[$template['slug']] = $name;
            }
        }

        self::assertNotEmpty($seen);
    }

    /** Every impact target string in a template, whichever shape it uses. */
    private function targetsOf(array $impacts): array
    {
        $targets = [];

        foreach ($impacts as $key => $value) {
            if ($key === 'stat_changes' && is_array($value)) {
                foreach ($value as $change) {
                    $field = $change['field'] ?? '';
                    // stat_changes split the path across target and field; recombine so one
                    // rule covers both shapes.
                    $targets[] = ($change['target'] ?? '') . '.' . $field;
                }
                continue;
            }

            if (is_array($value) && isset($value['target'])) {
                $targets[] = $value['target'];
            }
        }

        return $targets;
    }
}
