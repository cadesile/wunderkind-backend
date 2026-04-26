<?php

declare(strict_types=1);

namespace App\Service;

class FixtureGenerationService
{
    /**
     * @param string[] $clubIds
     * @return array
     */
    public function generate(array $clubIds): array
    {
        $teams = $clubIds;
        if (count($teams) % 2 !== 0) {
            $teams[] = 'BYE';
        }

        $n = count($teams);
        $rounds = $n - 1;
        $matchesPerRound = $n / 2;

        $schedule = [];

        for ($r = 0; $r < $rounds; $r++) {
            $matchday = [];
            for ($i = 0; $i < $matchesPerRound; $i++) {
                $home = $teams[$i];
                $away = $teams[$n - 1 - $i];

                if ($home !== 'BYE' && $away !== 'BYE') {
                    // Alternate home/away for the fixed team to be more balanced
                    if ($i === 0 && $r % 2 === 1) {
                        $matchday[] = [$away, $home];
                    } else {
                        $matchday[] = [$home, $away];
                    }
                }
            }
            $schedule[] = $matchday;

            // Rotate teams (keeping the first one fixed)
            $pivot = array_shift($teams);
            $last = array_pop($teams);
            array_unshift($teams, $last);
            array_unshift($teams, $pivot);
        }

        // Double round robin: interleave home and away legs so teams alternate
        // e.g. [MD1, MD1_swapped, MD2, MD2_swapped, ...] instead of all home then all away
        $result = [];
        foreach ($schedule as $matchday) {
            $result[] = $matchday;
            $swappedMatchday = [];
            foreach ($matchday as $match) {
                $swappedMatchday[] = [$match[1], $match[0]];
            }
            $result[] = $swappedMatchday;
        }

        return $result;
    }
}
