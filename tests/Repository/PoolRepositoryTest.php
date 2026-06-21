<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\PlayerRepository;
use App\Repository\StaffRepository;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that repository query-builder strings no longer contain 'club IS NULL'.
 * Reads the source file directly — a lightweight smoke-test that no club filter snuck back in.
 */
class PoolRepositoryTest extends TestCase
{
    public function testPlayerRepositoryHasNoClubIsNullFilter(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Repository/PlayerRepository.php');
        $this->assertStringNotContainsString('club IS NULL', $src);
        $this->assertStringNotContainsString('p.club IS NULL', $src);
        $this->assertStringNotContainsString("'club'", $src, 'findBy([\'club\' ...]) reference found');
    }

    public function testStaffRepositoryHasNoClubIsNullFilter(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Repository/StaffRepository.php');
        $this->assertStringNotContainsString('club IS NULL', $src);
        $this->assertStringNotContainsString('s.club IS NULL', $src);
        $this->assertStringNotContainsString("'club'", $src, 'findBy([\'club\' ...]) reference found');
    }
}
