<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Personal Assistant personalities and the advice call limits (Courtney, 2026-11-27).
 *
 * - personal_assistant: the owner-level PA roster, editable in admin (World › Personal
 *   assistants). Launches with three; admins add more without code.
 * - game_config.pa_advice_daily_call_limit (default 20) and pa_advice_timeout_seconds (default 8).
 *
 * THE SEED NEVER OVERWRITES. Each row is inserted only when its slug is missing, so re-running
 * this (or a restored backup that already has the rows) leaves admin edits alone.
 */
final class Version20261127100000 extends AbstractMigration
{
    /** @var list<array<string, mixed>> */
    private const SEED = [
        [
            'slug'        => 'pa-marcus',
            'name'        => 'Marcus Hale',
            'gender'      => 'male',
            'pronoun'     => 'he',
            'seed'        => 'pa-marcus',
            'overrides'   => ['hair' => 'crop', 'facial' => 'stubble', 'glasses' => false, 'face' => 'cool'],
            'description' => 'A dry-humoured fixer who has seen every boardroom crisis twice. Short, blunt and quietly funny.',
            'tone'        => 'dry',
            'ooo'         => "Marcus is out fixing someone else's mess. His notes are on your desk.",
            'sort'        => 10,
            'prompt'      => "You are Marcus Hale, a veteran fixer who has run the back office of more football clubs than he cares to count. "
                . "You are dry, unflappable and quietly funny: deadpan understatement rather than jokes. You speak plainly and briefly, "
                . "like someone who has seen this exact problem before and knows which lever to pull. You call the owner \"boss\". "
                . "You never panic and never gush; good news gets a raised eyebrow, bad news gets a plan. "
                . "You like to end on one concrete next step.",
        ],
        [
            'slug'        => 'pa-theo',
            'name'        => 'Theo Rush',
            'gender'      => 'male',
            'pronoun'     => 'he',
            'seed'        => 'pa-theo',
            'overrides'   => ['hair' => 'quiff', 'facial' => 'none', 'face' => 'happy'],
            'description' => 'An upbeat hype-man who believes in the project. Energetic and encouraging, even when the news is bad.',
            'tone'        => 'upbeat',
            'ooo'         => "Theo's out drumming up support. He'll be back buzzing.",
            'sort'        => 20,
            'prompt'      => "You are Theo Rush, the owner's relentlessly upbeat personal assistant. You genuinely believe in this club and it shows: "
                . "energetic, warm and encouraging, with the odd exclamation mark. You still tell the truth. When the news is bad you say so clearly, "
                . "then turn it into a rallying point and a practical next step. You talk like a matchday, not a spreadsheet, "
                . "and you never sound fake or salesy.",
        ],
        [
            'slug'        => 'pa-priya',
            'name'        => 'Priya Lund',
            'gender'      => 'female',
            'pronoun'     => 'she',
            'seed'        => 'pa-priya',
            'overrides'   => ['hair' => 'bun', 'facial' => 'none', 'glasses' => true, 'face' => 'focused'],
            'description' => 'A calm, precise analyst who leads with the numbers. Measured, exact and never rattled.',
            'tone'        => 'precise',
            'ooo'         => 'Priya is in a budget review. She will reply with figures shortly.',
            'sort'        => 30,
            'prompt'      => "You are Priya Lund, a calm and precise analyst serving as the owner's personal assistant. "
                . "You lead with the most important figure, then explain what it means in one clear sentence. "
                . "Your tone is measured, courteous and exact; you never exaggerate and never sound alarmed, "
                . "even when something is urgent. You prefer specifics to adjectives, and you finish with the single most useful action.",
        ],
    ];

    public function getDescription(): string
    {
        return 'Add personal_assistant (seeded with three PAs) and the PA advice game_config limits.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE personal_assistant (
                id UUID NOT NULL,
                slug VARCHAR(60) NOT NULL,
                name VARCHAR(60) NOT NULL,
                gender VARCHAR(10) NOT NULL,
                pronoun VARCHAR(10) NOT NULL,
                appearance_seed VARCHAR(80) NOT NULL,
                appearance_overrides JSON DEFAULT NULL,
                description VARCHAR(300) NOT NULL,
                personality_prompt TEXT NOT NULL,
                tone VARCHAR(20) NOT NULL,
                out_of_office_message VARCHAR(200) DEFAULT NULL,
                sort_order SMALLINT DEFAULT 0 NOT NULL,
                is_active BOOLEAN DEFAULT true NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_personal_assistant_slug ON personal_assistant (slug)');

        foreach (self::SEED as $pa) {
            $this->addSql(
                <<<'SQL'
                    INSERT INTO personal_assistant
                        (id, slug, name, gender, pronoun, appearance_seed, appearance_overrides, description,
                         personality_prompt, tone, out_of_office_message, sort_order, is_active, created_at, updated_at)
                    VALUES
                        (gen_random_uuid(), :slug, :name, :gender, :pronoun, :seed, CAST(:overrides AS JSON), :description,
                         :prompt, :tone, :ooo, :sort, true, NOW(), NOW())
                    ON CONFLICT (slug) DO NOTHING
                    SQL,
                [
                    'slug'        => $pa['slug'],
                    'name'        => $pa['name'],
                    'gender'      => $pa['gender'],
                    'pronoun'     => $pa['pronoun'],
                    'seed'        => $pa['seed'],
                    'overrides'   => json_encode($pa['overrides']),
                    'description' => $pa['description'],
                    'prompt'      => $pa['prompt'],
                    'tone'        => $pa['tone'],
                    'ooo'         => $pa['ooo'],
                    'sort'        => $pa['sort'],
                ],
            );
        }

        $this->addSql('ALTER TABLE game_config ADD pa_advice_daily_call_limit INT DEFAULT 20 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD pa_advice_timeout_seconds INT DEFAULT 8 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP pa_advice_timeout_seconds');
        $this->addSql('ALTER TABLE game_config DROP pa_advice_daily_call_limit');
        $this->addSql('DROP TABLE personal_assistant');
    }

    /** @return list<array<string, mixed>> the launch roster, for the seed test */
    public static function seedRows(): array
    {
        return self::SEED;
    }
}
