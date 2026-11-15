<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\StarterConfig;
use App\Enum\StaffRole;
use App\Service\StarterPackDiagnosticService;
use PHPUnit\Framework\TestCase;

/**
 * `app:pool:warm` must warm every staff role the installation is CONFIGURED to use.
 *
 * ── THE DEFECT THIS PINS ──────────────────────────────────────────────────────────────────
 *
 * The command held a hardcoded `WORLDPACK_STAFF_ROLES = [MANAGER, COACH, CHAIRMAN]`, commented
 * "roles needed by WorldInitializationService when building NPC league packs" — wrong in both
 * directions. The worldpack builds its own staff and draws nothing from the pool; the STARTER
 * PACK draws from the pool and asks for five roles.
 *
 * So no native DIRECTOR_OF_FOOTBALL or FACILITY_MANAGER was ever warmed. Measured on dev:
 * **0 English against 97 in pool** for exactly those two roles, against 50+ for every other.
 * The only other path that creates them (`GenerateMarketDataCommand::generateStaff()`) sets no
 * nationality at all.
 *
 * ⚠️ **AND IT SHOWED GREEN.** The starter-pack check falls back to foreign staff when natives
 * run out, so every English club quietly got a foreign director of football and facility
 * manager and nothing said so. That is why this test asserts against CONFIGURATION rather than
 * against a list: a hardcoded list cannot be wrong loudly, only short.
 */
class WarmPoolConfiguredRolesTest extends TestCase
{
    public function testEveryRoleTheStarterPackAsksForIsRequested(): void
    {
        $config = new StarterConfig();
        $config->setStarterManagerCount(1);
        $config->setStarterCoachCount(8);
        $config->setStarterDirectorOfFootballCount(1);
        $config->setStarterFacilityManagerCount(1);
        $config->setStarterChairmanCount(1);

        $roles = array_keys(array_filter(StarterPackDiagnosticService::staffRequirementsFor($config)));

        // The two that were missing are named explicitly: a count assertion would have passed
        // on any five roles, and these two are the ones that were absent for months.
        self::assertContains(StaffRole::DIRECTOR_OF_FOOTBALL->value, $roles);
        self::assertContains(StaffRole::FACILITY_MANAGER->value, $roles);
        self::assertContains(StaffRole::MANAGER->value, $roles);
        self::assertContains(StaffRole::COACH->value, $roles);
        self::assertContains(StaffRole::CHAIRMAN->value, $roles);
    }

    public function testTheRequirementsCoverEveryStaffRoleTheEnumHas(): void
    {
        // THE DRIFT GUARD. Adding a `StaffRole` without giving it a starter count would
        // reintroduce the original defect silently — the new role would simply never be
        // warmed, and the check screen would fall back to foreign and show green.
        $requirements = StarterPackDiagnosticService::staffRequirementsFor(new StarterConfig());

        foreach (StaffRole::cases() as $role) {
            self::assertArrayHasKey(
                $role->value,
                $requirements,
                sprintf(
                    'StaffRole::%s has no starter-pack requirement, so `app:pool:warm` will '
                    . 'never create a native one and the check will quietly serve a foreign '
                    . 'one instead. Add it to StarterPackDiagnosticService::requirements().',
                    $role->name,
                ),
            );
        }
    }

    public function testARoleConfiguredAtZeroIsStillReportedSoZeroCanBeToldFromMissing(): void
    {
        // "The operator does not use this role" and "the list forgot this role" must not look
        // the same to a caller. Filtering is the caller's decision.
        $config = new StarterConfig();
        $config->setStarterDirectorOfFootballCount(0);

        $requirements = StarterPackDiagnosticService::staffRequirementsFor($config);

        self::assertArrayHasKey(StaffRole::DIRECTOR_OF_FOOTBALL->value, $requirements);
        self::assertSame(0, $requirements[StaffRole::DIRECTOR_OF_FOOTBALL->value]);
    }

    public function testTheCommandNoLongerCarriesAHardcodedRoleList(): void
    {
        // Read from the source, because the regression is a CONSTANT reappearing — a
        // behavioural test cannot see someone reintroducing a literal list beside the derived
        // one and looping the wrong thing.
        $source = (string) file_get_contents(
            (new \ReflectionClass(\App\Command\WarmPoolCommand::class))->getFileName(),
        );

        // Targets the DECLARATION and the USE, not the word. The docblock explaining why the
        // constant was removed names it, and a test that banned the string outright would
        // force the next person to delete the explanation in order to go green — which is the
        // opposite of what this file is for.
        self::assertDoesNotMatchRegularExpression(
            '/const\s+WORLDPACK_STAFF_ROLES/',
            $source,
            'The hardcoded role list is back. The roles must come from configuration — see '
            . 'WarmPoolCommand::configuredStaffRoles().',
        );
        self::assertDoesNotMatchRegularExpression(
            '/self::WORLDPACK_STAFF_ROLES/',
            $source,
            'Something is looping the hardcoded list again instead of configuredStaffRoles().',
        );
        self::assertStringContainsString('configuredStaffRoles()', $source);
    }
}
