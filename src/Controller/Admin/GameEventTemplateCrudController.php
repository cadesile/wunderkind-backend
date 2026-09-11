<?php

namespace App\Controller\Admin;

use App\Entity\GameEventTemplate;
use App\Enum\EventCategory;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * The three JSON columns are edited as raw JSON with a documented key reference, following
 * the FacilityTemplate pattern.
 *
 * They used to be nested EasyAdmin form types (EventImpactsType was four levels of
 * collections deep). That UI was unusable, and it silently corrupted data: saving a template
 * whose `impacts` was a flat array re-encoded it as {"0":…,"1":…} and appended null-filled
 * selection_logic/duration_config/choices stubs. Binding to the entity's virtual *Json string
 * properties also sidesteps EasyAdmin's json-column-to-CollectionType auto-configuration
 * (see CLAUDE.md, "EasyAdmin custom form type on a json/array column").
 *
 * The help tables below describe what the CLIENT actually consumes, not what the schema
 * permits — several plausible-looking keys are inert, and they are marked as such. Keep them
 * in step with docs/event-guide.md if the engines change.
 */
class GameEventTemplateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameEventTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['category' => 'ASC', 'weight' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield TextField::new('slug')
            ->setHelp('Unique snake_case identifier, e.g. player_homesick');

        yield ChoiceField::new('category')
            ->setChoices(array_combine(
                array_map(fn (EventCategory $c) => $c->label(), EventCategory::cases()),
                EventCategory::cases(),
            ))
            ->formatValue(fn ($v) => $v instanceof EventCategory ? $v->label() : (string) $v)
            ->setHelp(
                'Determines which client engine fires this template — and therefore which '
                . '<code>impacts</code> shape and <code>firingConditions</code> dialect apply. '
                . 'See the field help below.'
            );

        yield IntegerField::new('weight')
            ->setHelp('0 = inactive (never randomly selected). Higher = more frequent.');

        yield TextField::new('title');

        yield TextareaField::new('bodyTemplate')
            ->setHelp($this->bodyTemplateHelp())
            ->hideOnIndex();

        yield CodeEditorField::new('impactsJson', 'Impacts')
            ->setLanguage('js')
            ->setNumOfRows(14)
            ->setHelp($this->impactsHelp())
            ->hideOnIndex();

        yield ChoiceField::new('severity')
            ->setChoices(['Minor' => 'minor', 'Major' => 'major'])
            ->setRequired(false)
            ->setHelp(
                '<strong>Only read on the NPC interaction path.</strong> There, '
                . '<code>major</code> defers the impacts to an actionable card the manager '
                . 'must respond to, and <code>minor</code> applies them automatically as a '
                . 'read-only inbox report. On every other category severity is ignored.'
            );

        yield BooleanField::new('noInteract')
            ->setHelp('When enabled, effects apply automatically — the manager reads the event but does not respond. Suppresses choices and support/punish prompts.')
            ->renderAsSwitch(true);

        yield CodeEditorField::new('firingConditionsJson', 'Firing Conditions')
            ->setLanguage('js')
            ->setNumOfRows(10)
            ->setHelp($this->firingConditionsHelp())
            ->hideOnIndex();

        yield CodeEditorField::new('chainedEventsJson', 'Chained Events')
            ->setLanguage('js')
            ->setNumOfRows(8)
            ->setHelp($this->chainedEventsHelp())
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')->hideOnForm();
    }

    // ── Help tables ───────────────────────────────────────────────────────────

    private function bodyTemplateHelp(): string
    {
        return <<<HTML
            <p class="mb-1">Placeholders are substituted per engine, so the available tokens depend on the category. An unmatched token is shipped to the player <em>verbatim</em>.</p>
            <table class="table table-sm table-bordered mt-2" style="font-size:0.8rem">
                <thead class="table-dark">
                    <tr><th>Categories</th><th>Tokens</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Player, Facility, Staff, Finance, NPC interaction, Match</td>
                        <td><code>{player_1}</code>…<code>{player_N}</code>, <code>{player}</code>, <code>{player_name}</code>, <code>{player2}</code>, <code>{facility_1}</code>…, <code>{facility}</code>, <code>{PA_Name}</code>, <code>{consecutive_good_games}</code>, <code>{consecutive_poor_games}</code><br><span class="text-muted">Both <code>{token}</code> and <code>{{token}}</code> work here.</span></td>
                    </tr>
                    <tr>
                        <td>Player reputation / milestone / morale / form</td>
                        <td><code>{player_name}</code>, <code>{age}</code>, <code>{reputation_tier}</code>, <code>{consecutive_good_games}</code>, <code>{consecutive_poor_games}</code><br>With <code>subject: "staff"</code>: <code>{staff_name}</code>, <code>{player_name}</code>, <code>{role}</code></td>
                    </tr>
                    <tr><td>Press</td><td><code>{clubName}</code>, <code>{managerName}</code>, <code>{position}</code>, <code>{streak}</code></td></tr>
                    <tr><td>Guardian</td><td><code>{guardian_name}</code>, <code>{player_name}</code> <span class="text-muted">— first occurrence only</span></td></tr>
                </tbody>
            </table>
            <p class="mt-1 mb-0 text-muted" style="font-size:0.8rem"><code>{staff}</code> is <strong>not</strong> substituted anywhere and will render literally — use <code>{staff_name}</code> on a staff-subject morale template. <code>{amount}</code> works only in <code>fan_shirt_sales_income</code>, which the season-transition code fills in by hand.</p>
        HTML;
    }

    private function impactsHelp(): string
    {
        return <<<HTML
            <p class="mb-1">What the event does when it fires. <strong>Two shapes are accepted, and the category decides which one works.</strong></p>
            <div class="alert alert-warning py-2 mb-2" style="font-size:0.8rem">
                <strong>Player reputation / milestone / morale / form</strong> templates are read by a different engine that only understands <code>stat_changes</code>. A flat array on those categories fires the inbox message and applies <em>nothing</em>.
            </div>

            <p class="mb-1" style="font-size:0.85rem"><strong>1. Canonical — works everywhere, required for the Player&nbsp;* categories</strong></p>
            <pre class="mb-2" style="font-size:0.75rem">{"stat_changes": [{"target": "player_1", "field": "morale", "operator": "subtract", "value": 8}]}</pre>
            <table class="table table-sm table-bordered mt-2" style="font-size:0.8rem">
                <thead class="table-dark">
                    <tr><th>Key</th><th>Values</th><th>Effect</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>target</code></td><td><code>player_1</code>, <code>player_2</code>, <code>squad_wide</code>, <code>pair</code></td><td>Who it applies to. <code>squad_wide</code> hits every active player; <code>pair</code> is only valid with <code>field: "relationship"</code>.</td></tr>
                    <tr><td><code>field</code></td><td><code>morale</code>, <code>condition</code>, <code>overallRating</code>, … <br><code>personality.&lt;trait&gt;</code><br><code>relationship</code></td><td>Any numeric player field (clamped 0–100), a personality trait (clamped 1–20), or the pair bond.</td></tr>
                    <tr><td><code>operator</code></td><td><code>add</code>, <code>subtract</code>, <code>set</code></td><td>Anything else is a no-op.</td></tr>
                    <tr><td><code>value</code></td><td><code>8</code></td><td>Always positive — use <code>subtract</code> to reduce.</td></tr>
                </tbody>
            </table>

            <p class="mb-1 mt-3" style="font-size:0.85rem"><strong>2. Legacy flat array — NOT read on the Player&nbsp;* categories</strong></p>
            <pre class="mb-2" style="font-size:0.75rem">[{"target": "player_1.morale", "delta": -8}, {"target": "squad.morale", "delta": 3}]</pre>
            <table class="table table-sm table-bordered mt-2" style="font-size:0.8rem">
                <thead class="table-dark"><tr><th>Target</th><th>Effect</th></tr></thead>
                <tbody>
                    <tr><td><code>player_N.morale</code></td><td>Clamped 0–100.</td></tr>
                    <tr><td><code>player_N.personality.&lt;trait&gt;</code></td><td>Clamped 1–20.</td></tr>
                    <tr><td><code>squad.morale</code></td><td>Every active player.</td></tr>
                    <tr><td><code>club.reputation</code></td><td>Club reputation delta. <span class="text-muted">Note: <code>academy.reputation</code> is an old spelling and is ignored.</span></td></tr>
                    <tr><td><code>pair.relationship</code></td><td>Bidirectional bond between <code>player_1</code> and <code>player_2</code>.</td></tr>
                </tbody>
            </table>

            <p class="mb-1 mt-3" style="font-size:0.85rem"><strong>Optional sibling keys</strong> (alongside <code>stat_changes</code>)</p>
            <table class="table table-sm table-bordered mt-2" style="font-size:0.8rem">
                <thead class="table-dark"><tr><th>Key</th><th>Shape</th><th>Effect</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>relationships</code></td>
                        <td><code>[{"type": "friendship", "player_1_ref": "player_1", "player_2_ref": "player_2", "intensity": 10}]</code></td>
                        <td><code>friendship</code> adds the bond, <code>rivalry</code> subtracts it.</td>
                    </tr>
                    <tr>
                        <td><code>choices</code></td>
                        <td><code>[{"emoji": "👏", "label": "Back them", "stat_changes": [ … ], "manager_shift": {"temperament": 1, "discipline": 0, "ambition": 0}}]</code></td>
                        <td>Presents options to the manager. Suppressed when <em>No interact</em> is on.</td>
                    </tr>
                    <tr>
                        <td><code>selection_logic</code></td>
                        <td><code>{"target_type": "player", "count": 2, "filter": {"position": "MID", "min_age": 16, "max_age": 21, "max_level": 3}}</code></td>
                        <td>Picks who fills the <code>player_N</code> slots. <code>target_type</code>: player, facility, staff, squad_wide. <span class="text-muted"><code>filter.active_only</code> is ignored — inactive players are always excluded.</span></td>
                    </tr>
                    <tr class="table-secondary">
                        <td><code>duration_config</code></td>
                        <td colspan="2"><strong>Not implemented.</strong> The client has no code path that reads it — multi-week effects do nothing today.</td>
                    </tr>
                </tbody>
            </table>

            <p class="mt-2 mb-0" style="font-size:0.8rem"><strong>Valid personality traits</strong> (1–20): <code>determination</code>, <code>professionalism</code>, <code>ambition</code>, <code>loyalty</code>, <code>adaptability</code>, <code>pressure</code>, <code>temperament</code>, <code>consistency</code>.<br><span class="text-muted">Any other trait name is silently dropped by the client. <code>teamwork</code>, <code>leadership</code>, <code>confidence</code>, <code>ego</code>, <code>bravery</code> and <code>maturity</code> do not exist.</span></p>
            <p class="mt-1 mb-0 text-muted" style="font-size:0.8rem"><code>player.injuredWeeks</code> is also not implemented — no engine applies it.</p>
        HTML;
    }

    private function firingConditionsHelp(): string
    {
        return <<<HTML
            <p class="mb-1">Leave <strong>empty</strong> for an unconditional event that joins the weekly random roll.</p>
            <div class="alert alert-warning py-2 mb-2" style="font-size:0.8rem">
                Once this field is set, the template is <strong>removed from the random roll</strong> and only fires if a dedicated evaluator claims it. A shape that matches none of the dialects below makes the event <strong>permanently dead</strong> — it will never appear.
            </div>
            <table class="table table-sm table-bordered mt-2" style="font-size:0.8rem">
                <thead class="table-dark">
                    <tr><th>Category</th><th>Keys</th><th>Notes</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Press</td>
                        <td><code>{"type": "consecutiveLosses", "threshold": 3}</code><br><span class="text-muted"><code>type</code>: consecutiveLosses, consecutiveWins, unbeatenRun, leaguePosition, relegationZone, goalDifference</span></td>
                        <td>Both keys required.</td>
                    </tr>
                    <tr>
                        <td>Match</td>
                        <td><code>{"type": "heavyDefeat", "threshold": 3}</code><br><span class="text-muted"><code>type</code>: heavyDefeat, statementWin, cleanSheet, redCard, hatTrick, standoutRating, collapseRating</span></td>
                        <td>Category must be <strong>Match</strong>. <code>cleanSheet</code> ignores <code>threshold</code>. A Match template with no conditions stays a ticker line only.</td>
                    </tr>
                    <tr>
                        <td>Player reputation / milestone / morale / form</td>
                        <td><code>reputation_tier</code> (+ <code>reputation_direction</code>: up|down)<br><code>age_milestone</code><br><code>morale_threshold</code> (+ <code>morale_direction</code>: above|below)<br><code>consecutive_good_games</code> — rating ≥ 7.0<br><code>consecutive_poor_games</code> — rating &lt; 5.5<br><code>morale_below</code> / <code>morale_above</code></td>
                        <td><strong>Only the first key present is evaluated</strong>, in the order listed — they do not combine. <code>morale_threshold</code> without a <code>morale_direction</code> never fires.</td>
                    </tr>
                    <tr>
                        <td>Player morale, staff variant</td>
                        <td><code>{"subject": "staff", "staff_role": "coach", "morale_below": 30}</code></td>
                        <td>Requires <code>subject: "staff"</code> and category <strong>Player morale</strong>. These keys <em>are</em> combined (all must pass).</td>
                    </tr>
                    <tr>
                        <td>NPC interaction</td>
                        <td><code>minSquadMorale</code>, <code>maxSquadMorale</code>, <code>minPairRelationship</code>, <code>maxPairRelationship</code>, <code>requiresCoLocation</code>,<br><code>actorTraitRequirements</code>, <code>subjectTraitRequirements</code>:<br><code>[{"trait": "ambition", "min": 12}]</code></td>
                        <td>All combined. An NPC template with <strong>no</strong> firing conditions never fires at all. Traits use the 1–20 scale and must be one of the eight valid names.</td>
                    </tr>
                </tbody>
            </table>
        HTML;
    }

    private function chainedEventsHelp(): string
    {
        return <<<HTML
            <p class="mb-1">Boosts a follow-up event's weight for the same player pair after this one fires. Leave empty (or <code>[]</code>) for none.</p>
            <pre class="mb-2" style="font-size:0.75rem">[{"nextEventSlug": "npc_squad_banter", "boostMultiplier": 2.0, "windowWeeks": 3, "note": "admin-only"}]</pre>
            <table class="table table-sm table-bordered mt-2" style="font-size:0.8rem">
                <thead class="table-dark"><tr><th>Key</th><th>Value</th><th>Effect</th></tr></thead>
                <tbody>
                    <tr><td><code>nextEventSlug</code></td><td>slug of another template</td><td>The event to boost.</td></tr>
                    <tr><td><code>boostMultiplier</code></td><td><code>2.0</code></td><td>Multiplies that template's weight while the window is open.</td></tr>
                    <tr><td><code>windowWeeks</code></td><td><code>3</code></td><td>How long the boost lasts.</td></tr>
                    <tr><td><code>note</code></td><td>free text</td><td>Admin-only — stripped before the API sends it to the client.</td></tr>
                </tbody>
            </table>
            <p class="mt-1 mb-0 text-muted" style="font-size:0.8rem">Consumed on the <strong>NPC interaction</strong> path only. Chains on any other category are ignored.</p>
        HTML;
    }
}
