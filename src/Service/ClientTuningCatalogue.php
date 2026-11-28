<?php

namespace App\Service;

/**
 * Engine tuning keys that the app used to hold only in its own code (`DEFAULT_GAME_CONFIG`).
 *
 * Owner, 2026-11-25: move every setting the app had but the backend did not serve into the
 * backend game config, so the bundled offline defaults (generated from `GET /api/game-config`
 * at build time) and the live config carry them, and an admin can tune them without an app
 * release.
 *
 * ## Why a catalogue + one JSON column, not 109 columns
 *
 * Every key here is a client-side dial the server never reads. A column per key would mean
 * 109 properties, getters, setters, migration lines, save-action lines and template rows kept
 * in step by hand. Instead each key is declared once below with its type and default, stored
 * in `game_config.client_tuning` (JSON), and the admin page, the save action and the API all
 * loop over this list. An unset key resolves to its default, so the stored JSON only holds
 * what an admin has actually changed, and adding a key is one line here.
 *
 * ⚠️ **Every default must equal the app's `DEFAULT_GAME_CONFIG`.** They were generated from it
 * on 2026-11-25, so serving them changes nothing in game until an admin moves a dial.
 *
 * Types: `int`, `float`, `bool`, `json` (an array or object, edited as JSON in the admin).
 */
final class ClientTuningCatalogue
{
    /** Admin page sections, in display order. */
    public const GROUPS = [
        'relationships'       => 'Relationships & Promises',
        'injuries-cards'      => 'Injuries & Cards',
        'scouting-dof'        => 'Scouting & Director of Football',
        'transfers-contracts' => 'Transfers, Values & Contracts',
        'manager-mandate'     => 'Manager & Mandate',
        'economy'             => 'Economy Shares',
        'player-reputation'   => 'Player Reputation',
        'youth-development'   => 'Youth & Development',
        'npc-clubs'           => 'NPC Clubs',
    ];

    public const KEYS = [
        'bondPlayerOrganicBaseChance' => ['group' => 'relationships', 'type' => 'float', 'default' => 0.06, 'label' => 'BOND PLAYER ORGANIC BASE CHANCE', 'help' => 'Base weekly chance (0–1) that a pair of team-mates in the matchday squad grow closer, before the compatibility, shared-XI, shared-position and shared-clique multipliers.'],
        'bondPlayerOrganicDelta' => ['group' => 'relationships', 'type' => 'int', 'default' => 2, 'label' => 'BOND PLAYER ORGANIC DELTA', 'help' => 'Bond points added when that roll passes.'],
        'bondPlayerFrictionBaseChance' => ['group' => 'relationships', 'type' => 'float', 'default' => 0.02, 'label' => 'BOND PLAYER FRICTION BASE CHANCE', 'help' => 'Base weekly chance (0–1) that a pair sharing no pitch time falls out, scaled by how volatile the two temperaments are.'],
        'bondPlayerFrictionDelta' => ['group' => 'relationships', 'type' => 'int', 'default' => 2, 'label' => 'BOND PLAYER FRICTION DELTA', 'help' => 'Bond points removed when that roll passes.'],
        'injuryProbabilityFloorFactor' => ['group' => 'injuries-cards', 'type' => 'float', 'default' => 0.25, 'label' => 'INJURY PROBABILITY FLOOR FACTOR', 'help' => 'The smallest fraction of baseInjuryProbability that facilities may leave standing, so a medical build-out can never make injuries IMPOSSIBLE — only rare.'],
        'injuryDurationFloorFactor' => ['group' => 'injuries-cards', 'type' => 'float', 'default' => 0.35, 'label' => 'INJURY DURATION FLOOR FACTOR', 'help' => 'The smallest fraction of an injury\'s rolled duration facilities may leave standing.'],
        'formalShortlistLimit' => ['group' => 'scouting-dof', 'type' => 'int', 'default' => 30, 'label' => 'FORMAL SHORTLIST LIMIT', 'help' => 'Size of the Formal Shortlist — the candidate pool processDofAutoSign walks.'],
        'intelPoolLimit' => ['group' => 'scouting-dof', 'type' => 'int', 'default' => 50, 'label' => 'INTEL POOL LIMIT', 'help' => 'Size of the Intel Pool — unpromoted scout finds, plus candidates parked after a pass.'],
        'dofCandidatePassCooldownWeeks' => ['group' => 'scouting-dof', 'type' => 'int', 'default' => 8, 'label' => 'DOF CANDIDATE PASS COOLDOWN WEEKS', 'help' => 'Weeks before the DOF will reconsider a candidate it passed on, or whose proposal lapsed unanswered.'],
        'dofSigningWillingnessBonus' => ['group' => 'scouting-dof', 'type' => 'int', 'default' => 10, 'label' => 'DOF SIGNING WILLINGNESS BONUS', 'help' => 'Flat bonus added to every DofBrain candidate score — the one honest dial for "how willing is the DOF to put a name forward".'],
        'dofWageBillBreachPenalty' => ['group' => 'scouting-dof', 'type' => 'int', 'default' => -10, 'label' => 'DOF WAGE BILL BREACH PENALTY', 'help' => 'Score penalty when a signing would breach the club\'s wage-bill ceiling.'],
        'dofQualityFloorRatio' => ['group' => 'scouting-dof', 'type' => 'float', 'default' => 0.75, 'label' => 'DOF QUALITY FLOOR RATIO', 'help' => 'Fraction of the tier-peer average OVR that counts as the quality floor.'],
        'dofPositionSurplusTolerance' => ['group' => 'scouting-dof', 'type' => 'int', 'default' => 3, 'label' => 'DOF POSITION SURPLUS TOLERANCE', 'help' => 'How many players above a position\'s formation minimum the DOF tolerates before refusing to buy there at all.'],
        'dofNegotiationAbilityThreshold' => ['group' => 'scouting-dof', 'type' => 'int', 'default' => 70, 'label' => 'DOF NEGOTIATION ABILITY THRESHOLD', 'help' => 'DOF coaching ability above which the director can negotiate a candidate up to their minimum acceptable wage, rather than walking away.'],
        'dofRequireManagerConsent' => ['group' => 'scouting-dof', 'type' => 'bool', 'default' => false, 'label' => 'DOF REQUIRE MANAGER CONSENT', 'help' => 'Whether the manager\'s opinion is a VETO on a DOF signing proposal, or just an opinion shown on the card.'],
        'dofCurationEmergencyFallbackAll' => ['group' => 'scouting-dof', 'type' => 'bool', 'default' => true, 'label' => 'DOF CURATION EMERGENCY FALLBACK ALL', 'help' => 'Whether curation may fall back to ALL directive positions when the ones the squad is actually short of yield no promotable candidate.'],
        'maxSeasonsUnsignedBeforeForcedRetirement' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => 3, 'label' => 'MAX SEASONS UNSIGNED BEFORE FORCED RETIREMENT', 'help' => 'Seasons a player can sit unsigned on the free transfer list before they are forcibly retired — a duration axis independent of retirementMinAge/retirementMaxAge.'],
        'unsignedRetirementMinAge' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => 25, 'label' => 'UNSIGNED RETIREMENT MIN AGE', 'help' => 'Age floor for the forced-retirement-while-unsigned rule above: a free agent younger than this is never retired on duration, however long they sit.'],
        'freeAgentWageDecayPerSeasonPct' => ['group' => 'transfers-contracts', 'type' => 'float', 'default' => 0.12, 'label' => 'FREE AGENT WAGE DECAY PER SEASON %', 'help' => 'Fraction a free agent\'s wage/contract expectation decays per season spent unsigned (e.g.'],
        'freeAgentWageDecayFloorPct' => ['group' => 'transfers-contracts', 'type' => 'float', 'default' => 0.4, 'label' => 'FREE AGENT WAGE DECAY FLOOR %', 'help' => 'Floor on the decayed wage expectation, as a fraction of the original (undecayed) expectation — decay never pushes it below this.'],
        'askingPriceJitterPct' => ['group' => 'transfers-contracts', 'type' => 'float', 'default' => 0.15, 'label' => 'ASKING PRICE JITTER %', 'help' => 'Random jitter applied to a club\'s displayed asking price for a player, so the same OVR/age/potential/reputation formula doesn\'t produce an identical number every time.'],
        'npcManagerSackWinRateThreshold' => ['group' => 'npc-clubs', 'type' => 'float', 'default' => 0.4, 'label' => 'NPC MANAGER SACK WIN RATE THRESHOLD', 'help' => 'Win rate below which season-end sacking is considered.'],
        'npcManagerSackRelegationProbability' => ['group' => 'npc-clubs', 'type' => 'float', 'default' => 0.7, 'label' => 'NPC MANAGER SACK RELEGATION PROBABILITY', 'help' => 'Probability of sacking after relegation.'],
        'npcManagerSackPoorSeasonProbability' => ['group' => 'npc-clubs', 'type' => 'float', 'default' => 0.4, 'label' => 'NPC MANAGER SACK POOR SEASON PROBABILITY', 'help' => 'Probability of sacking after poor season (bottom half + low win rate).'],
        'npcManagerSackUnderperformProbability' => ['group' => 'npc-clubs', 'type' => 'float', 'default' => 0.15, 'label' => 'NPC MANAGER SACK UNDERPERFORM PROBABILITY', 'help' => 'Probability of sacking after underperforming vs expectations.'],
        'npcTransferBatchSize' => ['group' => 'npc-clubs', 'type' => 'int', 'default' => 120, 'label' => 'NPC TRANSFER BATCH SIZE', 'help' => 'Transfer needs a single window week may action across the whole pyramid.'],
        'npcSearchCachePerBucket' => ['group' => 'npc-clubs', 'type' => 'int', 'default' => 50, 'label' => 'NPC SEARCH CACHE PER BUCKET', 'help' => 'Candidates cached per (position × ability band).'],
        'npcSearchCacheRefreshWeeks' => ['group' => 'npc-clubs', 'type' => 'int', 'default' => 2, 'label' => 'NPC SEARCH CACHE REFRESH WEEKS', 'help' => 'Game weeks between cache refreshes while the window is open.'],
        'npcResignCooldownWeeks' => ['group' => 'npc-clubs', 'type' => 'int', 'default' => 52, 'label' => 'NPC RESIGN COOLDOWN WEEKS', 'help' => 'Weeks a player is invisible to NPC clubs after being signed.'],
        'npcEmergencyCooldownWeeks' => ['group' => 'npc-clubs', 'type' => 'int', 'default' => 1, 'label' => 'NPC EMERGENCY COOLDOWN WEEKS', 'help' => 'Weeks a club must wait between emergency recruit attempts while the transfer window is open.'],
        'npcCriticalMaxSignings' => ['group' => 'npc-clubs', 'type' => 'int', 'default' => 3, 'label' => 'NPC CRITICAL MAX SIGNINGS', 'help' => 'Signings a club that cannot name an XI may make in one week outside the transfer window.'],
        'npcMaxDealsPerClubPerWeek' => ['group' => 'npc-clubs', 'type' => 'int', 'default' => 3, 'label' => 'NPC MAX DEALS PER CLUB PER WEEK', 'help' => 'Transfers one club may complete in a single window week.'],
        'npcManagerCrisisGamesWithoutWin' => ['group' => 'npc-clubs', 'type' => 'int', 'default' => 5, 'label' => 'NPC MANAGER CRISIS GAMES WITHOUT WIN', 'help' => 'Consecutive games without a win (from last N) to trigger mid-season crisis.'],
        'npcManagerCrisisSackProbability' => ['group' => 'npc-clubs', 'type' => 'float', 'default' => 0.3, 'label' => 'NPC MANAGER CRISIS SACK PROBABILITY', 'help' => 'Probability of mid-season sacking during crisis.'],
        'npcManagerHoneymoonSeasons' => ['group' => 'npc-clubs', 'type' => 'int', 'default' => 1, 'label' => 'NPC MANAGER HONEYMOON SEASONS', 'help' => 'Seasons in charge required before manager loses honeymoon protection.'],
        'managerAbilityWeight' => ['group' => 'manager-mandate', 'type' => 'float', 'default' => 0.3, 'label' => 'MANAGER ABILITY WEIGHT', 'help' => 'Weight applied to coachingAbility when biasing decision scores.'],
        'managerAbilityNoiseScale' => ['group' => 'manager-mandate', 'type' => 'float', 'default' => 1, 'label' => 'MANAGER ABILITY NOISE SCALE', 'help' => 'Scale factor applied to decision noise based on ability.'],
        'staffSignOnFeePercentMin' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => 2, 'label' => 'STAFF SIGN ON FEE PERCENT MIN', 'help' => 'Minimum % of total contract value charged as sign-on fee.'],
        'staffSignOnFeePercentMax' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => 8, 'label' => 'STAFF SIGN ON FEE PERCENT MAX', 'help' => 'Maximum % of total contract value charged as sign-on fee.'],
        'playerSignOnFeePercentMin' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => 5, 'label' => 'PLAYER SIGN ON FEE PERCENT MIN', 'help' => 'Minimum % of total contract value charged as player sign-on fee.'],
        'playerSignOnFeePercentMax' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => 15, 'label' => 'PLAYER SIGN ON FEE PERCENT MAX', 'help' => 'Maximum % of total contract value charged as player sign-on fee.'],
        'staffSeverancePercent' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => 50, 'label' => 'STAFF SEVERANCE PERCENT', 'help' => '% of remaining contract value paid as severance on early release.'],
        'staffBuyoutCompensationPercent' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => 100, 'label' => 'STAFF BUYOUT COMPENSATION PERCENT', 'help' => '% of remaining contract value paid to an NPC club as compensation when their staff is poached (Office > Hire).'],
        'npcStaffDefaultContractWeeks' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => 104, 'label' => 'NPC STAFF DEFAULT CONTRACT WEEKS', 'help' => 'Default contract length (weeks) synthesized for NPC pyramid staff, since the backend worldpack doesn\'t send salary/contract data for them yet.'],
        'pyramidTaxPercent' => ['group' => 'economy', 'type' => 'float', 'default' => 20, 'label' => 'PYRAMID TAX PERCENT', 'help' => 'Percentage of season profit taken from every club (AMP + NPC) that ended the season in profit, pooled and redistributed evenly across every club in the pyramid at season end.'],
        'investorPremiumSmallMin' => ['group' => 'economy', 'type' => 'float', 'default' => 1.0, 'label' => 'INVESTOR PREMIUM SMALL MIN', 'help' => 'Lowest multiplier a small investor pays over the value of the stake they take. An offer = stake % x club value x a multiplier drawn between the small min and max, spread over the 3-season contract (e.g. 10% of a 10M club is 1M; at 1.0-1.2 a small investor offers 1M-1.2M). Size also scales the yearly performance bonus.'],
        'investorPremiumSmallMax' => ['group' => 'economy', 'type' => 'float', 'default' => 1.2, 'label' => 'INVESTOR PREMIUM SMALL MAX', 'help' => 'Highest multiplier a small investor pays over the value of the stake they take. An offer = stake % x club value x a multiplier drawn between the small min and max, spread over the 3-season contract (e.g. 10% of a 10M club is 1M; at 1.0-1.2 a small investor offers 1M-1.2M). Size also scales the yearly performance bonus.'],
        'investorPremiumMediumMin' => ['group' => 'economy', 'type' => 'float', 'default' => 1.2, 'label' => 'INVESTOR PREMIUM MEDIUM MIN', 'help' => 'Lowest multiplier a medium investor pays over the value of the stake they take. An offer = stake % x club value x a multiplier drawn between the medium min and max, spread over the 3-season contract (e.g. 10% of a 10M club is 1M; at 1.2-1.5 a medium investor offers 1.2M-1.5M). Size also scales the yearly performance bonus.'],
        'investorPremiumMediumMax' => ['group' => 'economy', 'type' => 'float', 'default' => 1.5, 'label' => 'INVESTOR PREMIUM MEDIUM MAX', 'help' => 'Highest multiplier a medium investor pays over the value of the stake they take. An offer = stake % x club value x a multiplier drawn between the medium min and max, spread over the 3-season contract (e.g. 10% of a 10M club is 1M; at 1.2-1.5 a medium investor offers 1.2M-1.5M). Size also scales the yearly performance bonus.'],
        'investorPremiumLargeMin' => ['group' => 'economy', 'type' => 'float', 'default' => 1.5, 'label' => 'INVESTOR PREMIUM LARGE MIN', 'help' => 'Lowest multiplier a large investor pays over the value of the stake they take. An offer = stake % x club value x a multiplier drawn between the large min and max, spread over the 3-season contract (e.g. 10% of a 10M club is 1M; at 1.5-2.0 a large investor offers 1.5M-2M). Size also scales the yearly performance bonus.'],
        'investorPremiumLargeMax' => ['group' => 'economy', 'type' => 'float', 'default' => 2.0, 'label' => 'INVESTOR PREMIUM LARGE MAX', 'help' => 'Highest multiplier a large investor pays over the value of the stake they take. An offer = stake % x club value x a multiplier drawn between the large min and max, spread over the 3-season contract (e.g. 10% of a 10M club is 1M; at 1.5-2.0 a large investor offers 1.5M-2M). Size also scales the yearly performance bonus.'],
        'npcStartingBalanceByTier' => ['group' => 'npc-clubs', 'type' => 'json', 'default' => ['1' => 500000000, '2' => 200000000, '3' => 80000000, '4' => 30000000, '5' => 15000000, '6' => 10000000, '7' => 5000000, '8' => 2000000], 'label' => 'NPC STARTING BALANCE BY TIER', 'help' => 'Seed balance for NPC clubs by league tier (pence).'],
        'npcWeeklyIncomeTierMultiplier' => ['group' => 'npc-clubs', 'type' => 'float', 'default' => 2, 'label' => 'NPC WEEKLY INCOME TIER MULTIPLIER', 'help' => 'Divisor per tier step down.'],
        'npcWeeklyIncomeReputationMultiplier' => ['group' => 'npc-clubs', 'type' => 'float', 'default' => 0.05, 'label' => 'NPC WEEKLY INCOME REPUTATION MULTIPLIER', 'help' => 'Extra income fraction per 10 rep points above 50.'],
        'npcSponsorChancePerWeek' => ['group' => 'npc-clubs', 'type' => 'float', 'default' => 0.02, 'label' => 'NPC SPONSOR CHANCE PER WEEK', 'help' => 'Per-week probability of an NPC club receiving a sponsor windfall.'],
        'npcSponsorBoostAmount' => ['group' => 'npc-clubs', 'type' => 'int', 'default' => 10000000, 'label' => 'NPC SPONSOR BOOST AMOUNT', 'help' => 'One-off sponsor windfall amount in pence.'],
        'npcBaseTicketPrice' => ['group' => 'npc-clubs', 'type' => 'int', 'default' => 1500, 'label' => 'NPC BASE TICKET PRICE', 'help' => 'Base ticket price in pence for NPC matchday income.'],
        'npcAttendanceBase' => ['group' => 'npc-clubs', 'type' => 'int', 'default' => 500, 'label' => 'NPC ATTENDANCE BASE', 'help' => 'Base attendance per home match for the lowest-reputation NPC club.'],
        'npcAttendancePerReputation' => ['group' => 'npc-clubs', 'type' => 'int', 'default' => 20, 'label' => 'NPC ATTENDANCE PER REPUTATION', 'help' => 'Additional attendance per reputation point.'],
        'motivationBaseDecay' => ['group' => 'relationships', 'type' => 'float', 'default' => 0.3, 'label' => 'MOTIVATION BASE DECAY', 'help' => 'Legacy flat weekly decay.'],
        'managerLowUrgencyWeeks' => ['group' => 'manager-mandate', 'type' => 'int', 'default' => 6, 'label' => 'MANAGER LOW URGENCY WEEKS', 'help' => 'Length of the post-IGNORE low-urgency window, in weeks.'],
        'mandateNegotiationDeltaThreshold' => ['group' => 'manager-mandate', 'type' => 'int', 'default' => 2, 'label' => 'MANDATE NEGOTIATION DELTA THRESHOLD', 'help' => 'Position delta (predicted − target) at or above which the manager negotiates.'],
        'mandateDemandedBudgetPerDeltaPence' => ['group' => 'manager-mandate', 'type' => 'int', 'default' => 10000000, 'label' => 'MANDATE DEMANDED BUDGET PER DELTA PENCE', 'help' => 'Budget the Autocrat demands per position of delta, in pence.'],
        'mandateMercenaryDemandMultiplier' => ['group' => 'manager-mandate', 'type' => 'float', 'default' => 1.5, 'label' => 'MANDATE MERCENARY DEMAND MULTIPLIER', 'help' => 'Multiplier applied to the Mercenary\'s budget demand.'],
        'mandateDeterminationSurgeThreshold' => ['group' => 'manager-mandate', 'type' => 'int', 'default' => 14, 'label' => 'MANDATE DETERMINATION SURGE THRESHOLD', 'help' => 'Trait floor for the "point to prove" motivation surge after criticism.'],
        'mandateBreakThreshold' => ['group' => 'manager-mandate', 'type' => 'int', 'default' => 8, 'label' => 'MANDATE BREAK THRESHOLD', 'help' => 'Trait ceiling below which a criticised manager breaks.'],
        'mandateLoyalistBreakThreshold' => ['group' => 'manager-mandate', 'type' => 'int', 'default' => 10, 'label' => 'MANDATE LOYALIST BREAK THRESHOLD', 'help' => 'Club Loyalist\'s higher break ceiling — they crumble more easily.'],
        'mandateSeveranceMaxWeeks' => ['group' => 'manager-mandate', 'type' => 'int', 'default' => 26, 'label' => 'MANDATE SEVERANCE MAX WEEKS', 'help' => 'Severance is capped at this many weeks of salary.'],
        'mandateSackJustifiedMargin' => ['group' => 'manager-mandate', 'type' => 'int', 'default' => 4, 'label' => 'MANDATE SACK JUSTIFIED MARGIN', 'help' => 'Missing the target by more than this many places justifies the sacking.'],
        'mandateAtRiskNagFrequencyWeeks' => ['group' => 'manager-mandate', 'type' => 'int', 'default' => 4, 'label' => 'MANDATE AT RISK NAG FREQUENCY WEEKS', 'help' => 'Weeks between at-risk mandate nags during the season.'],
        'playerReputationDecayRate' => ['group' => 'player-reputation', 'type' => 'float', 'default' => 0.3, 'label' => 'PLAYER REPUTATION DECAY RATE', 'help' => 'Weekly reputation decay rate for all players.'],
        'playerReputationMatchGainHigh' => ['group' => 'player-reputation', 'type' => 'float', 'default' => 1.5, 'label' => 'PLAYER REPUTATION MATCH GAIN HIGH', 'help' => 'Reputation gained per match for high-rated performance (rating >= 7.5).'],
        'playerReputationMatchGainMid' => ['group' => 'player-reputation', 'type' => 'float', 'default' => 0.5, 'label' => 'PLAYER REPUTATION MATCH GAIN MID', 'help' => 'Reputation gained per match for average performance (rating 6.0-7.4).'],
        'playerReputationMatchGainLow' => ['group' => 'player-reputation', 'type' => 'float', 'default' => -0.3, 'label' => 'PLAYER REPUTATION MATCH GAIN LOW', 'help' => 'Reputation lost per match for poor performance (rating < 6.0).'],
        'playerReputationTitleBoost' => ['group' => 'player-reputation', 'type' => 'int', 'default' => 15, 'label' => 'PLAYER REPUTATION TITLE BOOST', 'help' => 'Reputation boost for winning a league title.'],
        'playerReputationPromotionBoost' => ['group' => 'player-reputation', 'type' => 'int', 'default' => 8, 'label' => 'PLAYER REPUTATION PROMOTION BOOST', 'help' => 'Reputation boost for promotion.'],
        'playerReputationTopScorerBoost' => ['group' => 'player-reputation', 'type' => 'int', 'default' => 10, 'label' => 'PLAYER REPUTATION TOP SCORER BOOST', 'help' => 'Reputation boost for top scorer award.'],
        'playerReputationTopAssistBoost' => ['group' => 'player-reputation', 'type' => 'int', 'default' => 8, 'label' => 'PLAYER REPUTATION TOP ASSIST BOOST', 'help' => 'Reputation boost for top assists award.'],
        'playerReputationBestRatingBoost' => ['group' => 'player-reputation', 'type' => 'int', 'default' => 8, 'label' => 'PLAYER REPUTATION BEST RATING BOOST', 'help' => 'Reputation boost for best average rating award.'],
        'playerStatAwardMinAppearances' => ['group' => 'player-reputation', 'type' => 'int', 'default' => 5, 'label' => 'PLAYER STAT AWARD MIN APPEARANCES', 'help' => 'Minimum appearances required to qualify for a season stat award.'],
        'playerReputationTierMultiplier' => ['group' => 'player-reputation', 'type' => 'float', 'default' => 0.15, 'label' => 'PLAYER REPUTATION TIER MULTIPLIER', 'help' => 'Reputation multiplier for higher tier leagues (tier 1 = full, lower tiers earn less).'],
        'playerReputationAgeDecayThreshold' => ['group' => 'player-reputation', 'type' => 'int', 'default' => 32, 'label' => 'PLAYER REPUTATION AGE DECAY THRESHOLD', 'help' => 'Age above which reputation decays faster.'],
        'playerReputationAgeDecayRate' => ['group' => 'player-reputation', 'type' => 'float', 'default' => 0.1, 'label' => 'PLAYER REPUTATION AGE DECAY RATE', 'help' => 'Additional decay per year above threshold.'],
        'transferValueTopTenPctThreshold' => ['group' => 'transfers-contracts', 'type' => 'float', 'default' => 0.1, 'label' => 'TRANSFER VALUE TOP TEN % THRESHOLD', 'help' => 'Top 10% percentile threshold for fee premium.'],
        'transferValueTopTenPctMultiplier' => ['group' => 'transfers-contracts', 'type' => 'float', 'default' => 1.5, 'label' => 'TRANSFER VALUE TOP TEN % MULTIPLIER', 'help' => 'Fee multiplier for players in the top 10% by OVR.'],
        'transferValueTopFivePctThreshold' => ['group' => 'transfers-contracts', 'type' => 'float', 'default' => 0.05, 'label' => 'TRANSFER VALUE TOP FIVE % THRESHOLD', 'help' => 'Top 5% percentile threshold for fee premium.'],
        'transferValueTopFivePctMultiplier' => ['group' => 'transfers-contracts', 'type' => 'float', 'default' => 2, 'label' => 'TRANSFER VALUE TOP FIVE % MULTIPLIER', 'help' => 'Fee multiplier for players in the top 5% by OVR.'],
        'transferValueTopOnePctMultiplier' => ['group' => 'transfers-contracts', 'type' => 'float', 'default' => 3.5, 'label' => 'TRANSFER VALUE TOP ONE % MULTIPLIER', 'help' => 'Fee multiplier for players in the top 1% by OVR.'],
        'transferValueAgePeakStart' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => 24, 'label' => 'TRANSFER VALUE AGE PEAK START', 'help' => 'Start of prime age window — no discount below this age.'],
        'transferValueAgePeakEnd' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => 29, 'label' => 'TRANSFER VALUE AGE PEAK END', 'help' => 'End of prime age window — no discount up to this age.'],
        'transferValueYouthDiscount' => ['group' => 'transfers-contracts', 'type' => 'float', 'default' => 0.15, 'label' => 'TRANSFER VALUE YOUTH DISCOUNT', 'help' => 'Fractional discount applied to pre-peak youth (age < peakStart).'],
        'transferValueVeteranDiscount' => ['group' => 'transfers-contracts', 'type' => 'float', 'default' => 0.2, 'label' => 'TRANSFER VALUE VETERAN DISCOUNT', 'help' => 'Fractional discount applied to veterans (age > peakEnd).'],
        'transferValueReputationWeight' => ['group' => 'transfers-contracts', 'type' => 'float', 'default' => 0.3, 'label' => 'TRANSFER VALUE REPUTATION WEIGHT', 'help' => 'How much a player\'s reputation (0–100) boosts their fair value.'],
        'transferValueShortContractThreshold' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => 26, 'label' => 'TRANSFER VALUE SHORT CONTRACT THRESHOLD', 'help' => 'Contract weeks remaining below which a leverage discount applies.'],
        'transferValueShortContractDiscount' => ['group' => 'transfers-contracts', 'type' => 'float', 'default' => 0.15, 'label' => 'TRANSFER VALUE SHORT CONTRACT DISCOUNT', 'help' => 'Fractional discount when contract is below threshold.'],
        'playerContractOfferCooldownWeeks' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => 2, 'label' => 'CONTRACT OFFER COOLDOWN (WEEKS)', 'help' => 'Weeks after a player turns down a contract offer before he will hear another. Separate from the renewal cooldown, which follows a signed renewal. A counter-offer does not start it. Default 2.'],
        'playerContractOfferBelowPromiseMoraleDelta' => ['group' => 'transfers-contracts', 'type' => 'int', 'default' => -10, 'label' => 'OFFER BELOW PROMISED WAGE MORALE', 'help' => 'Morale change when the club offers a player less than the wage it promised him. The offer is always refused. Default -10.'],
        'youthIntakeByFacilityLevel' => ['group' => 'youth-development', 'type' => 'json', 'default' => [2, 3, 4, 6, 8, 10, 11, 13, 14, 15], 'label' => 'YOUTH INTAKE BY FACILITY LEVEL', 'help' => 'Intake count by facility level.'],
        'youthMinAge' => ['group' => 'youth-development', 'type' => 'int', 'default' => 12, 'label' => 'YOUTH MIN AGE', 'help' => 'Minimum age for youth intake players.'],
        'youthMaxAge' => ['group' => 'youth-development', 'type' => 'int', 'default' => 15, 'label' => 'YOUTH MAX AGE', 'help' => 'Maximum age for youth intake players.'],
        'youthFirstTeamEligibilityAge' => ['group' => 'youth-development', 'type' => 'int', 'default' => 16, 'label' => 'YOUTH FIRST TEAM ELIGIBILITY AGE', 'help' => 'Age at which youth players are eligible for first team.'],
        'youthPromotionLoyaltyBoost' => ['group' => 'youth-development', 'type' => 'int', 'default' => 2, 'label' => 'YOUTH PROMOTION LOYALTY BOOST', 'help' => 'Loyalty boost when youth player is promoted to first team.'],
        'youthEnrollmentWeeks' => ['group' => 'youth-development', 'type' => 'int', 'default' => 156, 'label' => 'YOUTH ENROLLMENT WEEKS', 'help' => 'Enrollment weeks for newly promoted youth players.'],
        'youthPromotionPotentialWeight' => ['group' => 'youth-development', 'type' => 'float', 'default' => 0.9, 'label' => 'YOUTH PROMOTION POTENTIAL WEIGHT', 'help' => 'How heavily a prospect\'s potential drives ManagerBrain.assessYouthPlayer\'s promote/release score.'],
        'youthPromotionWillingnessBonus' => ['group' => 'youth-development', 'type' => 'int', 'default' => 10, 'label' => 'YOUTH PROMOTION WILLINGNESS BONUS', 'help' => 'Flat bias added to the youth promote/release score before noise — the manager\'s standing willingness to back a graduate.'],
        'youthPromotionManagerBondMin' => ['group' => 'youth-development', 'type' => 'int', 'default' => 50, 'label' => 'YOUTH PROMOTION MANAGER BOND MIN', 'help' => 'Manager↔player bond (0–100 band of the signed −100..100 scale) seeded when the MANAGER themselves promotes a youth to the first team under Coach.managerSignsEligibleYouth.'],
        'youthPromotionManagerBondMax' => ['group' => 'youth-development', 'type' => 'int', 'default' => 60, 'label' => 'YOUTH PROMOTION MANAGER BOND MAX', 'help' => 'Default as shipped in the app.'],
        'developmentSnapshotFrequencyWeeks' => ['group' => 'youth-development', 'type' => 'int', 'default' => 4, 'label' => 'DEVELOPMENT SNAPSHOT FREQUENCY WEEKS', 'help' => 'How often (in game weeks) development snapshots are recorded for the progress chart.'],
        'gametimePromiseBrokenRelationshipDelta' => ['group' => 'relationships', 'type' => 'int', 'default' => -25, 'label' => 'GAMETIME PROMISE BROKEN RELATIONSHIP DELTA', 'help' => 'Relationship delta with manager when promise broken.'],
        'gametimePromiseKeptRelationshipDelta' => ['group' => 'relationships', 'type' => 'int', 'default' => 15, 'label' => 'GAMETIME PROMISE KEPT RELATIONSHIP DELTA', 'help' => 'Bond delta with manager when promise is kept.'],
        'gametimePromiseGrantMotivationDelta' => ['group' => 'relationships', 'type' => 'int', 'default' => 10, 'label' => 'GAMETIME PROMISE GRANT MOTIVATION DELTA', 'help' => 'Superseded by playerPromiseGrantMotivationMin/Max, which apply to every promise made to a player.'],
        'staffOverrideMoralePenalty' => ['group' => 'relationships', 'type' => 'int', 'default' => -10, 'label' => 'STAFF OVERRIDE MORALE PENALTY', 'help' => 'Morale applied to every staff member when the manager renews a contract the DOF advised against.'],
    ];

    /** @return array<string, mixed> every key at its default */
    public static function defaults(): array
    {
        return array_map(static fn (array $d) => $d['default'], self::KEYS);
    }

    /**
     * Defaults with the stored overrides on top. A stored value whose type no longer fits its
     * key (or a key no longer in the catalogue) is dropped, so a bad row can never reach the app
     * with the wrong type — the app's build step would reject the whole payload.
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public static function resolve(array $stored): array
    {
        $out = self::defaults();
        foreach ($stored as $key => $value) {
            if (isset(self::KEYS[$key]) && self::fits(self::KEYS[$key]['type'], $value)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    public static function fits(string $type, mixed $value): bool
    {
        return match ($type) {
            'int'   => is_int($value),
            'float' => is_int($value) || is_float($value),
            'bool'  => is_bool($value),
            'json'  => is_array($value),
            default => false,
        };
    }

    /**
     * Parse one admin form value for a key. Returns [ok, value]; ok=false leaves the stored
     * value as it was (and the caller reports the key).
     *
     * @return array{0: bool, 1: mixed}
     */
    public static function parse(string $key, mixed $raw): array
    {
        $type = self::KEYS[$key]['type'] ?? null;
        if ($type === null) {
            return [false, null];
        }
        if ($type === 'bool') {
            return [true, in_array($raw, ['1', 1, true, 'true', 'on'], true)];
        }
        $raw = is_string($raw) ? trim($raw) : $raw;
        if ($type === 'int') {
            return filter_var($raw, FILTER_VALIDATE_INT) !== false ? [true, (int) $raw] : [false, null];
        }
        if ($type === 'float') {
            if (!is_numeric($raw)) {
                return [false, null];
            }
            $f = (float) $raw;
            // Keep whole numbers as ints so the JSON matches what the app shipped with.
            return [true, floor($f) === $f && abs($f) < PHP_INT_MAX ? (int) $f : $f];
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? [true, $decoded] : [false, null];
    }
}
