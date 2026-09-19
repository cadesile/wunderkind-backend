<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\EventCategory;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Seeds the MATCH_NARRATIVE chain-graph content, ported verbatim from
 * wunderkind-app/src/components/matchux/narrativeContent.json — see
 * MatchNarrativeGeneratorService for how these rows are walked at match-result time.
 *
 * Each JSON node becomes one numbered slug per candidate line ({NODE_TYPE}_{N}), and every
 * numbered row of a node type carries an identical `chainedEvents` array — one entry per
 * branch target *node type* (not a specific numbered row; MatchNarrativeGeneratorService
 * resolves a node type to a random numbered row at pick time). `boostMultiplier`/
 * `windowWeeks`/`note` are inert filler for this category — the NPC_INTERACTION weight-boost
 * semantics those fields normally carry don't apply here.
 */
#[AsCommand(
    name: 'app:seed-match-narrative',
    description: 'Seeds MATCH_NARRATIVE chain-graph templates (skips existing slugs; pass --update to overwrite them).',
)]
class SeedMatchNarrativeTemplatesCommand extends AbstractSeedEventTemplatesCommand
{
    protected function templateLabel(): string
    {
        return 'match narrative';
    }

    /**
     * @return array<int, array{slug: string, category: EventCategory, weight: int, title: string, bodyTemplate: string, impacts: array, chainedEvents: array}>
     */
    protected function buildTemplates(): array
    {
        $nodes = [
            'BUILDUP' => [
                'next' => [],
                'lines' => [
                    '{playerA} picks up the ball on the left wing and surges forward...',
                    '{playerA} plays a delicate through ball into the path of {playerB}...',
                    '{playerA} floats a high cross into the penalty area...',
                    '{playerA} tries a long diagonal ball out to the right flank...',
                    '{playerA} nods the ball down into space...',
                    '{playerA} wins the ball back in midfield.',
                    '{playerA} plays it short to {playerB}, probing for an opening.',
                    '{playerA} controls it on the chest and turns past his marker...',
                    '{playerA} and {playerB} exchange a quick one-two on the edge of the box.',
                    '{playerA} drives into space down the right flank.',
                    '{playerA} lofts a weighted pass over the defense for {playerB} to chase.',
                    '{playerA} cuts inside onto his favored foot...',
                    '{playerA} nicks the ball away in midfield to spark a quick counter-attack.',
                    '{playerA} spreads the play wide to {playerB} with a crisp cross-field pass.',
                    '{playerA} holds off the defender and lays it off cleanly to {playerB}.',
                ],
            ],
            'GOAL_ATTEMPT' => [
                'next' => ['GOAL_ATTEMPT_SCORE', 'GOAL_ATTEMPT_SAVED', 'GOAL_ATTEMPT_MISS', 'GOAL_ATTEMPT_BLOCKED'],
                'lines' => [
                    '{attacker} unleashes a powerful shot from outside the box!',
                    '{attacker} gets on the end of a deep cross and headers toward goal!',
                    '{attacker} cuts inside and curls one toward the far post!',
                    '{attacker} goes for goal from 30 yards out...',
                    '{attacker} unleashed a venomous volley from the edge of the area!',
                    '{attacker} tries an ambitious overhead kick!',
                    '{attacker} strikes it low and hard towards the bottom corner...',
                    '{attacker} latches onto the loose ball and hits it first time!',
                    '{attacker} bends one toward the top right corner...',
                ],
            ],
            'GOAL_ATTEMPT_SCORE' => [
                'next' => [],
                'lines' => [
                    'It flies into the top corner! What a goal!',
                    "It beats the keeper and hits the back of the net!",
                    'GOAL! The keeper had no chance with that strike.',
                    "GOAL! It's a magnificent strike!",
                ],
            ],
            'GOAL_ATTEMPT_SAVED' => [
                'next' => ['CORNER_ATTEMPT'],
                'lines' => [
                    '{goalkeeper} dives to his left and makes a brilliant save!',
                    '{goalkeeper} tips it over the crossbar with his fingertips!',
                    '{goalkeeper} smothers the ball cleanly.',
                    '{goalkeeper} dives at full stretch to push the ball wide!',
                    '{goalkeeper} makes a double save to deny the follow-up shot!',
                    '{goalkeeper} punch-clears the ball from the crowded box.',
                ],
            ],
            'GOAL_ATTEMPT_MISS' => [
                'next' => [],
                'lines' => [
                    'It sails well over the crossbar. Goal kick.',
                    'It drags harmlessly wide of the right post.',
                    'It rattles off the woodwork and bounces away!',
                    'It scuffs off the boot and rolls tamely wide.',
                ],
            ],
            'GOAL_ATTEMPT_BLOCKED' => [
                'next' => ['CORNER_ATTEMPT'],
                'lines' => [
                    '{defender} throws himself in front of it to block the shot!',
                    '{defender} makes a vital goal-line clearance!',
                    'Blocked! {defender} gets a crucial touch to deny the effort.',
                    '{defender} slides in at the last moment to smother the strike.',
                ],
            ],
            'CORNER_ATTEMPT' => [
                'next' => ['CORNER_ATTEMPT_SCORE', 'CORNER_ATTEMPT_CLEARED'],
                'lines' => [
                    '{taker} whips a dangerous ball into the 6-yard box!',
                    '{taker} plays a short corner to start the routine...',
                    '{taker} floats a high ball toward the back post!',
                ],
            ],
            'CORNER_ATTEMPT_SCORE' => [
                'next' => [],
                'lines' => [
                    '{attacker} rises highest and powers the header home!',
                    'It falls to {attacker} in the scramble, and he pokes it in!',
                    "GOAL! {attacker} meets it perfectly and it's in the net!",
                ],
            ],
            'CORNER_ATTEMPT_CLEARED' => [
                'next' => [],
                'lines' => [
                    '{defender} heads it clear of danger.',
                    'The goalkeeper comes out and punches it far upfield.',
                    '{defender} tracks back brilliantly and slides in to intercept.',
                    '{defender} manages to nick the ball away at the critical moment.',
                ],
            ],
            'DISCIPLINE_FOUL' => [
                'next' => [],
                'lines' => [
                    '{playerA} jumps into a wild, late challenge on {playerB}!',
                    '{playerA} appears to throw an elbow at {playerB}...',
                    'Tempers flaring after that challenge from {playerA}.',
                    '{playerA} pulls back {playerB} by the shirt to halt the counter.',
                    'A tactical foul by {playerA} to break up the play.',
                    '{playerA} slides in recklessly from behind on {playerB}!',
                    '{playerA} lunges in with two feet—that was dangerously high!',
                ],
            ],
            'CARD_YELLOW' => [
                'next' => [],
                'lines' => [
                    'The referee marches over and produces a yellow card for {player}.',
                    '{player} is shown a yellow card.',
                    '{player} goes into the book for that challenge.',
                    'The referee gives {player} a stern talking-to and a caution.',
                ],
            ],
            'CARD_RED' => [
                'next' => [],
                'lines' => [
                    'RED CARD! {player} is sent for an early shower!',
                    "It's a red card! {player} is given his marching orders!",
                    '{player} sees red after that reckless challenge!',
                ],
            ],
            'INJURY' => [
                'next' => [],
                'lines' => [
                    '{playerA} goes down clutching his ankle in obvious pain.',
                    '{playerA} is receiving treatment on the sidelines.',
                    '{playerA} cannot continue and is forced off with an injury.',
                    '{playerA} pulls up briefly — looks OK to continue.',
                    'The physio is watching closely after that collision involving {playerA}.',
                    '{playerA} signals to the bench after feeling a tweak in his hamstring.',
                    '{playerA} stays down holding his knee after a heavy collision.',
                    '{playerA} hobbles off the pitch accompanied by the medical team.',
                    '{playerA} shakes off the knock and walks back onto the field.',
                    'Play is stopped as {playerA} receives attention for a head injury.',
                    '{playerA} lands heavily after an aerial duel and looks severely shaken.',
                ],
            ],
            'PLAYER_FORM_HIGH' => [
                'next' => [],
                'lines' => [
                    '{playerA} is having a superb game.',
                    '{playerA} is unplayable today.',
                    '{playerA} is pulling all the strings in the middle of the park.',
                    '{playerA} looks tireless, covering every blade of grass today.',
                    '{playerA} is proving to be a constant thorn in the opposition side.',
                    '{playerA} is dictating the tempo with supreme composure.',
                ],
            ],
            'PLAYER_FORM_LOW' => [
                'next' => [],
                'lines' => [
                    '{playerA} struggling to make an impact this afternoon.',
                    '{playerA} having a difficult afternoon.',
                    '{playerA} seems to be off the pace today, losing possession repeatedly.',
                    '{playerA} is frustrated after being starved of service so far.',
                ],
            ],
        ];

        $templates = [];
        foreach ($nodes as $nodeType => $node) {
            $chainedEvents = array_map(static fn (string $next) => [
                'nextEventSlug'   => $next,
                'boostMultiplier' => 1.0,
                'windowWeeks'     => 0,
                'note'            => null,
            ], $node['next']);

            foreach ($node['lines'] as $i => $line) {
                $templates[] = [
                    'slug'          => sprintf('%s_%d', $nodeType, $i + 1),
                    'category'      => EventCategory::MATCH_NARRATIVE,
                    'weight'        => 1,
                    'title'         => ucwords(strtolower(str_replace('_', ' ', $nodeType))),
                    'bodyTemplate'  => $line,
                    'impacts'       => [],
                    'chainedEvents' => $chainedEvents,
                ];
            }
        }

        return $templates;
    }
}
