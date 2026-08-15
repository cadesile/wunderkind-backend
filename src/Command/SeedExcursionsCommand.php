<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Excursion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-excursions',
    description: 'Seeds the excursion catalogue (upserts by slug - safe to re-run; preserves uploaded images).',
)]
class SeedExcursionsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $repo = $this->em->getRepository(Excursion::class);
        $created = 0;
        $updated = 0;

        foreach ($this->buildExcursions() as $data) {
            // Upsert rather than truncate: an admin may have uploaded artwork
            // against these slugs, and a delete-then-insert would orphan it.
            $excursion = $repo->findOneBy(['slug' => $data['slug']]);
            if ($excursion === null) {
                $excursion = new Excursion($data['slug'], $data['title'], $data['body']);
                $this->em->persist($excursion);
                $created++;
            } else {
                $excursion->setTitle($data['title']);
                $excursion->setBody($data['body']);
                $updated++;
            }

            $excursion->setCostPerPersonPence($data['costPerPersonPence']);
            $excursion->setEffectValue($data['effectValue']);
            $excursion->setNegativeFrequency($data['negativeFrequency']);
            $excursion->setTargetAudience($data['targetAudience']);
            $excursion->setPostSeasonOnly($data['postSeasonOnly']);
            $excursion->setCooldownWeeks($data['cooldownWeeks']);
            $excursion->setActive(true);
        }

        $this->em->flush();
        $io->success(sprintf('Excursions seeded — %d created, %d updated.', $created, $updated));

        return Command::SUCCESS;
    }

    /**
     * The catalogue. Mirrors DEFAULT_EXCURSIONS in the app
     * (src/constants/excursions.ts) - keep the two in step, since that array is
     * the offline fallback shown whenever this API is unreachable.
     *
     * Costs are PER ATTENDEE in pence and are multiplied by headcount in-app.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildExcursions(): array
    {
        return [
            [
                'slug'               => 'community-litter-pick',
                'title'              => 'Community Litter Pick',
                'body'               => 'Equip the squad with high-vis vests and grabbers to tidy up the local park. Builds civic pride and humility, unless the star striker refuses to touch a discarded kebab.',
                'costPerPersonPence' => 350,
                'effectValue'        => 18,
                'negativeFrequency'  => 2,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 3,
            ],
            [
                'slug'               => 'chippy-tea-run',
                'title'              => 'Chippy Tea Run',
                'body'               => 'Pick up twenty portions of cod, large chips, and mushy peas on the journey back from training. Morale spikes instantly, though the physio will look utterly miserable.',
                'costPerPersonPence' => 550,
                'effectValue'        => 25,
                'negativeFrequency'  => 2,
                'targetAudience'     => 'players',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 2,
            ],
            [
                'slug'               => 'pub-quiz-night',
                'title'              => 'Local Pub Quiz Night',
                'body'               => 'Pile into the snug at The Nag\'s Head for general knowledge, a round of bitter, and pork scratchings. Tensions run surprisingly high over disputed 1990s pop trivia.',
                'costPerPersonPence' => 800,
                'effectValue'        => 35,
                'negativeFrequency'  => 4,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 4,
            ],
            [
                'slug'               => 'coaching-staff-darts',
                'title'              => 'Staff Darts and Pints',
                'body'               => 'The backroom staff gather around a board in the clubhouse backroom to settle tactical differences with tungsten. A few poorly aimed treble twenties can leave bruised egos.',
                'costPerPersonPence' => 950,
                'effectValue'        => 28,
                'negativeFrequency'  => 3,
                'targetAudience'     => 'staff',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 3,
            ],
            [
                'slug'               => 'dog-racing-evening',
                'title'              => 'Greyhound Stadium Night',
                'body'               => 'An evening under the floodlights cheering on six dogs around a sand track over cheap lager. Bonding is guaranteed unless somebody loses their weekly petrol money on trap four.',
                'costPerPersonPence' => 1200,
                'effectValue'        => 40,
                'negativeFrequency'  => 5,
                'targetAudience'     => 'players',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 4,
            ],
            [
                'slug'               => 'local-bowling-alley',
                'title'              => 'Ten-Pin Bowling Night',
                'body'               => 'Rent three lanes and a row of questionable two-tone shoes down at the local lanes. Harmless fun, though competitive defenders take gutter balls remarkably poorly.',
                'costPerPersonPence' => 1400,
                'effectValue'        => 38,
                'negativeFrequency'  => 3,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 4,
            ],
            [
                'slug'               => 'sunday-roast-carvery',
                'title'              => 'Sunday Carvery Gathering',
                'body'               => 'Unlimited roast potatoes, giant Yorkshire puddings, and thick gravy for everyone. It is practically impossible to stay angry with your teammates with a plate piled this high.',
                'costPerPersonPence' => 1550,
                'effectValue'        => 42,
                'negativeFrequency'  => 2,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 4,
            ],
            [
                'slug'               => 'tactical-curry-night',
                'title'              => 'Staff Balti and Tactics Night',
                'body'               => 'Coaches and scouts gather at the local Indian restaurant to dissect league form over papadums and sizzling tandoori. Heat from the vindaloo occasionally spills into tactical debate.',
                'costPerPersonPence' => 1750,
                'effectValue'        => 45,
                'negativeFrequency'  => 3,
                'targetAudience'     => 'staff',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 4,
            ],
            [
                'slug'               => 'bingo-hall-session',
                'title'              => 'Midweek Bingo Hall Trip',
                'body'               => 'Take the squad down to the local leisure hall armed with felt dabbers and quiet concentration. Uniquely calming, low-intensity, and almost entirely risk-free.',
                'costPerPersonPence' => 1850,
                'effectValue'        => 32,
                'negativeFrequency'  => 1,
                'targetAudience'     => 'players',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 3,
            ],
            [
                'slug'               => 'mini-golf-championship',
                'title'              => 'Pirate Cove Mini Golf',
                'body'               => 'Eighteen holes of artificial turf, fiberglass shipwrecks, and spinning windmill obstacles. Frustrating missed putts will test the temperament of your key playmakers.',
                'costPerPersonPence' => 1950,
                'effectValue'        => 44,
                'negativeFrequency'  => 4,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 4,
            ],
            [
                'slug'               => 'escape-room-challenge',
                'title'              => 'Submarine Escape Room',
                'body'               => 'Lock small groups of players in a cramped replica Cold War submarine with sixty minutes to solve cryptic puzzles. Natural leaders emerge, but stubborn egos clash fast.',
                'costPerPersonPence' => 3200,
                'effectValue'        => 52,
                'negativeFrequency'  => 5,
                'targetAudience'     => 'players',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 6,
            ],
            [
                'slug'               => 'go-karting-grand-prix',
                'title'              => 'Indoor Go-Karting Grand Prix',
                'body'               => 'Strap everyone into 200cc petrol karts on a slippery indoor tarmac circuit. Great for building competitive spirit, but hairpins bring out blatant disregard for braking zones.',
                'costPerPersonPence' => 4500,
                'effectValue'        => 65,
                'negativeFrequency'  => 6,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 6,
            ],
            [
                'slug'               => 'scouting-tactics-retreat',
                'title'              => 'Rural Coaching Strategy Retreat',
                'body'               => 'A weekend workshop in a converted barn to align the scouting department and coaching staff. Productive for long-term vision, provided nobody questions the senior coach\'s philosophy.',
                'costPerPersonPence' => 5200,
                'effectValue'        => 58,
                'negativeFrequency'  => 4,
                'targetAudience'     => 'staff',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 8,
            ],
            [
                'slug'               => 'mud-run-assault-course',
                'title'              => 'Military Mud Assault Course',
                'body'               => 'Drag the squad through freezing mud trenches, cargo nets, and ice-water plunges supervised by an ex-drill sergeant. Builds undeniable grit, though fragile egos may break.',
                'costPerPersonPence' => 5800,
                'effectValue'        => 62,
                'negativeFrequency'  => 5,
                'targetAudience'     => 'players',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 8,
            ],
            [
                'slug'               => 'paintballing-skirmish',
                'title'              => 'Woodland Paintball Skirmish',
                'body'               => 'Arm the entire club with semi-automatic paint markers in the dense woods. Highly cathartic for releasing dressing-room frustration, but someone always shoots a coach at point-blank range.',
                'costPerPersonPence' => 6500,
                'effectValue'        => 70,
                'negativeFrequency'  => 7,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 8,
            ],
            [
                'slug'               => 'high-ropes-treetop-trek',
                'title'              => 'Treetop High-Ropes Course',
                'body'               => 'Suspend the squad forty feet above the forest floor across wobbly log bridges and zip wires. Conquering fear unifies the dressing room, provided nobody freezes on the zip wire platform.',
                'costPerPersonPence' => 7200,
                'effectValue'        => 66,
                'negativeFrequency'  => 4,
                'targetAudience'     => 'players',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 8,
            ],
            [
                'slug'               => 'comedy-club-table',
                'title'              => 'VIP Comedy Club Table',
                'body'               => 'Reserve front-row tables at a reputable city-centre stand-up club with a round of drinks. A shared laugh dissolves dressing-room cliques, unless the comic ruthlessly roasts the manager.',
                'costPerPersonPence' => 8500,
                'effectValue'        => 68,
                'negativeFrequency'  => 3,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 6,
            ],
            [
                'slug'               => 'executive-spa-summit',
                'title'              => 'Staff Country Spa Day',
                'body'               => 'Treat the coaching and medical staff to saunas, thermal hydrotherapy pools, and deep tissue massages. Recharges weary staff members and clears backroom exhaustion completely.',
                'costPerPersonPence' => 9500,
                'effectValue'        => 72,
                'negativeFrequency'  => 2,
                'targetAudience'     => 'staff',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 10,
            ],
            [
                'slug'               => 'lock-in-at-the-anchor',
                'title'              => 'Closed-Doors Tavern Lock-In',
                'body'               => 'Pay the landlord to bolt the doors after midnight for an unrestricted, all-hands squad lock-in. Can forge unbreakable team spirit or spark a catastrophic pub brawl.',
                'costPerPersonPence' => 9800,
                'effectValue'        => 88,
                'negativeFrequency'  => 8,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 12,
            ],
            [
                'slug'               => 'clay-pigeon-shooting',
                'title'              => 'Country Estate Clay Shooting',
                'body'               => 'Tweed jackets, 12-gauge over-and-under shotguns, and flying fluorescent clay discs on a private estate. A classy afternoon that builds calm focus, provided safety briefings are strictly respected.',
                'costPerPersonPence' => 11000,
                'effectValue'        => 74,
                'negativeFrequency'  => 5,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 10,
            ],
            [
                'slug'               => 'white-water-rafting',
                'title'              => 'White-Water Rapids Expedition',
                'body'               => 'Punt six-man inflatable rafts down grade-four river rapids. Demands synchronised paddling and instant communication, but throwing teammates overboard is a genuine hazard.',
                'costPerPersonPence' => 12500,
                'effectValue'        => 78,
                'negativeFrequency'  => 6,
                'targetAudience'     => 'players',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 12,
            ],
            [
                'slug'               => 'devon-coastal-bootcamp',
                'title'              => 'Devon Coastal Conditioning Camp',
                'body'               => 'A week of cliffside hill sprints, sand-dune endurance runs, and sea swims along the rugged Devon coastline. Exhausting, character-building, and unifies the squad for the coming year.',
                'costPerPersonPence' => 26000,
                'effectValue'        => 82,
                'negativeFrequency'  => 4,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => true,
                'cooldownWeeks'      => 40,
            ],
            [
                'slug'               => 'majorca-sun-and-sangria-trip',
                'title'              => 'Majorca Post-Season Fiesta',
                'body'               => 'Fly the entire club out to a Mediterranean resort for four days of open-bar beach clubs and sunshine. Can create lifelong loyalty or degenerate into total chaos and tabloid headlines.',
                'costPerPersonPence' => 38000,
                'effectValue'        => 92,
                'negativeFrequency'  => 9,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => true,
                'cooldownWeeks'      => 48,
            ],
            [
                'slug'               => 'algarve-luxury-golf-retreat',
                'title'              => 'Algarve Five-Star Golf Retreat',
                'body'               => 'First-class flights, five-star villas, and thirty-six championship golf holes in the Portuguese sun. The pinnacle of club rewards that cements absolute harmony across players and staff alike.',
                'costPerPersonPence' => 48000,
                'effectValue'        => 79,
                'negativeFrequency'  => 2,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => true,
                'cooldownWeeks'      => 50,
            ],
        ];
    }
}
