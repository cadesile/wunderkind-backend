<?php

namespace App\Command;

use App\Entity\SocialPostTemplate;
use App\Enum\SocialPlatform;
use App\Enum\StatCategory;
use App\Enum\StatsPeriod;
use App\Repository\SocialPostTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-social-post-templates',
    description: 'Seeds default social post templates for each stat category x platform (idempotent).',
)]
class SeedSocialPostTemplatesCommand extends Command
{
    public function __construct(
        private readonly SocialPostTemplateRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = 0;

        foreach ($this->defaults() as [$category, $platform, $period, $body]) {
            if ($this->repository->findByCategoryAndPlatform($category, $platform, $period) !== null) {
                continue;
            }

            $this->em->persist(new SocialPostTemplate($category, $platform, $period, $body));
            $created++;
        }

        $this->em->flush();
        $io->success("Seeded {$created} new social post template(s).");

        return Command::SUCCESS;
    }

    /** @return array<int, array{0: StatCategory, 1: SocialPlatform, 2: StatsPeriod, 3: string}> */
    private function defaults(): array
    {
        return [
            // {{period}} is a self-contained phrase ("this week", "of all time", ...) —
            // no leading "this"/"over" needed in the template text around it.
            [StatCategory::MOST_TRANSFERS, SocialPlatform::FACEBOOK, StatsPeriod::WEEK,
                '🔄 {{clubName}} leads the Wunderkind community in transfer activity {{period}} with {{value}} transfers! Who\'s building the biggest squad?'],
            [StatCategory::MOST_TRANSFERS, SocialPlatform::TWITTER, StatsPeriod::WEEK,
                '🔄 {{clubName}} tops the transfer charts {{period}} — {{value}} deals done! #Wunderkind'],
            [StatCategory::MOST_DEVELOPMENT, SocialPlatform::FACEBOOK, StatsPeriod::WEEK,
                '📈 {{clubName}} has been the most improved side in the community {{period}}, gaining {{value}} development points across the squad!'],
            [StatCategory::MOST_DEVELOPMENT, SocialPlatform::TWITTER, StatsPeriod::WEEK,
                '📈 {{clubName}} gained {{value}} development points {{period}} — most improved side in the community! #Wunderkind'],
            [StatCategory::MOST_SEASONS, SocialPlatform::FACEBOOK, StatsPeriod::ALL,
                '🏟️ {{clubName}} has now completed {{value}} seasons — the longest-running club in the Wunderkind community!'],
            [StatCategory::MOST_SEASONS, SocialPlatform::TWITTER, StatsPeriod::ALL,
                '🏟️ {{clubName}} has completed {{value}} seasons — longest-running club in the community! #Wunderkind'],
            [StatCategory::MOST_TROPHIES, SocialPlatform::FACEBOOK, StatsPeriod::ALL,
                '🏆 {{clubName}} has won {{value}} league titles — the most decorated club in the Wunderkind community!'],
            [StatCategory::MOST_TROPHIES, SocialPlatform::TWITTER, StatsPeriod::ALL,
                '🏆 {{clubName}} has won {{value}} titles — most decorated club in the community! #Wunderkind'],

            [StatCategory::BEST_FORM, SocialPlatform::TWITTER, StatsPeriod::WEEK,
                "🔥 Unstoppable momentum.\n\n{{clubName}} have racked up {{statValue}} wins {{period}} (+{{secondaryValue}} GD). The gaffer has them humming, but can they sustain it when the fixture congestion bites?\n\nOwn the club. Build the empire.\n\n#BuildMyClub #FootballManager #PixelArt"],
            [StatCategory::BEST_FORM, SocialPlatform::FACEBOOK, StatsPeriod::WEEK,
                "🔥 Unstoppable momentum.\n\nWhile rival chairmen are scrambling to steady the ship, {{clubName}} are cruising. They've banked {{statValue}} wins {{period}} with a commanding +{{secondaryValue}} goal difference.\n\nA settled dressing room and clinical finishing make all the difference. Is your squad built to challenge this run, or are you one bad result away from an emergency board meeting?\n\n#BuildMyClub #FootballManager #IndieGame #RetroGaming"],

            [StatCategory::BIGGEST_ROUT, SocialPlatform::TWITTER, StatsPeriod::WEEK,
                "💥 Absolute demolition.\n\n{{clubName}} ran riot {{period}}, dismantling their opposition by an emphatic {{statValue}}-goal margin.\n\nNo mercy shown on the pitch. Total tactical dominance.\n\n#BuildMyClub #IndieGame #FootballGames"],
            [StatCategory::BIGGEST_ROUT, SocialPlatform::FACEBOOK, StatsPeriod::WEEK,
                "💥 Total demolition on matchday.\n\n{{clubName}} didn't just win {{period}}—they embarrassed the opposition with a ruthless {{statValue}}-goal rout.\n\nFrom the opening whistle to stoppage time, the frontline couldn't stop finding the net. The away fans were heading for the exits before the hour mark.\n\nWhat's the biggest thrashing your club has dished out?\n\n#BuildMyClub #FootballManager #PixelArt #RetroGaming"],

            [StatCategory::SUPER_STRIKER, SocialPlatform::TWITTER, StatsPeriod::LAST_24_HOURS,
                "🎯 Clinical. Relentless.\n\n{{playerName}} has been in devastating form for {{clubName}}, bagging {{statValue}} goals {{period}}.\n\nOpposing defenders have no answers right now. Pay the man his goal bonus.\n\n#BuildMyClub #FootballManager #PixelArt"],
            [StatCategory::SUPER_STRIKER, SocialPlatform::FACEBOOK, StatsPeriod::LAST_24_HOURS,
                "🎯 Clinical. Relentless.\n\n{{playerName}} has torched defences {{period}}, bagging {{statValue}} goals for {{clubName}}.\n\nEvery time he gets space in the penalty box, the ball hits the back of the net. The scouts were right about his composure under pressure.\n\nHave you got a reliable talisman up front, or is your front line firing blanks?\n\n#BuildMyClub #IndieGame #FootballManager #PixelArt"],

            [StatCategory::FORTRESS_DEFENCE, SocialPlatform::TWITTER, StatsPeriod::WEEK,
                "🧱 A brick wall at the back.\n\n{{clubName}} have conceded just {{statValue}} goals per game across their matches {{period}}.\n\nOrganised, disciplined, and refusing to give an inch. Attack wins games, defence wins titles.\n\n#BuildMyClub #IndieGame"],
            [StatCategory::FORTRESS_DEFENCE, SocialPlatform::FACEBOOK, StatsPeriod::WEEK,
                "🧱 The tightest backline in the pyramid.\n\n{{clubName}} are choking out games {{period}}, conceding a microscopic {{statValue}} goals per match.\n\nSolid centre-back pairings, a keeper commanding his box, and zero cheap turnovers. If you can't score against them, you can't beat them.\n\nHow many clean sheets has your back five kept lately?\n\n#BuildMyClub #FootballManager #RetroGaming #PixelArt"],

            [StatCategory::TRANSFER_SPLURGE, SocialPlatform::TWITTER, StatsPeriod::WEEK,
                "💰 Opening the war chest.\n\n{{clubName}} splashed £{{statValue}} on new signings {{period}}.\n\nThe chairman is backing the gaffer with serious cash—now the results need to match the investment.\n\n#BuildMyClub #FootballManager"],
            [StatCategory::TRANSFER_SPLURGE, SocialPlatform::FACEBOOK, StatsPeriod::WEEK,
                "💰 Opening the boardroom war chest.\n\n{{clubName}} haven't held back in the market, dropping £{{statValue}} on incoming transfers {{period}}.\n\nAmbition or reckless gambling? Spending big brings elite talent, but it also spikes the weekly wage bill and elevates board expectations immediately. There's nowhere to hide if the silverware doesn't follow.\n\nAre you stockpiling cash in the reserves or buying promotion?\n\n#BuildMyClub #FootballManager #IndieGame #PixelArt"],
        ];
    }
}
