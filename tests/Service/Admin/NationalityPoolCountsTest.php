<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Enum\Country;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use App\Service\Admin\NationalityPoolCounts;

/**
 * The rows behind the pool screens' nationality picker.
 *
 * What is worth pinning: every nationality appears whether or not anyone is in the pool
 * (the picker exists to generate for the empty ones), staff and scouts are summed into one
 * list, and the per-role keys are the ones generate-chunk actually takes.
 */
class NationalityPoolCountsTest extends KernelTestCase
{
    private const MARKER = 'Natcount';

    private EntityManagerInterface $em;
    private NationalityPoolCounts $counts;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->counts = self::getContainer()->get(NationalityPoolCounts::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        $c = self::getContainer()->get(Connection::class);
        $c->executeStatement('DELETE FROM player WHERE last_name = :n', ['n' => self::MARKER]);
        $c->executeStatement('DELETE FROM staff  WHERE last_name = :n', ['n' => self::MARKER]);
        // scout carries one `name` column, not a first/last pair.
        $c->executeStatement('DELETE FROM scout  WHERE name = :n', ['n' => self::MARKER]);
        $this->em->clear();
    }

    /** @return array<string, int> nationality => count, flattened out of the grouping */
    private static function flatten(array $grouped): array
    {
        $out = [];

        foreach ($grouped as $rows) {
            foreach ($rows as $row) {
                $out[$row['nationality']] = $row['count'];
            }
        }

        return $out;
    }

    public function testEveryNationalityIsOfferedEvenWithAnEmptyPool(): void
    {
        $rows = self::flatten($this->counts->forPlayers());

        self::assertCount(\count(Country::nationalityMap()), $rows, 'one row per nationality');
        self::assertArrayHasKey('English', $rows);
        self::assertArrayHasKey('Brazilian', $rows);
    }

    public function testGroupsComeBackInTheOrderThePickerRendersThem(): void
    {
        $headings = array_keys($this->counts->forPlayers());

        self::assertSame('UK & Ireland', $headings[0]);
        self::assertContains('Nordic', $headings);
        self::assertNotContains('Other', $headings, 'nothing should fall through the fold');
    }

    public function testPlayerCountsAreScopedToTheYouthIntakePool(): void
    {
        // Matches what the figure printed above the picker counts. A player recruited any
        // other way is in the table but not in "the pool" as this screen means it.
        $this->em->persist($this->player('English', RecruitmentSource::YOUTH_INTAKE));
        $this->em->persist($this->player('English', RecruitmentSource::YOUTH_INTAKE));
        $this->em->persist($this->player('Brazilian', RecruitmentSource::SCOUTING_NETWORK));
        $this->em->flush();

        $rows = self::flatten($this->counts->forPlayers());

        self::assertSame(2, $rows['English']);
        self::assertSame(0, $rows['Brazilian'], 'not youth intake, so not in this pool');
    }

    public function testStaffAndScoutsAreSummedIntoOneList(): void
    {
        // The picker is one list because a job is role × nationality and the operator is
        // picking the nationality half.
        $this->em->persist($this->staff('French', StaffRole::COACH));
        $this->em->persist($this->staff('French', StaffRole::MANAGER));
        $this->em->persist($this->scout('French'));
        $this->em->flush();

        self::assertSame(3, self::flatten($this->counts->forStaff())['French']);
    }

    public function testPerRoleCountsUseTheKeysGenerateChunkTakes(): void
    {
        $this->em->persist($this->staff('German', StaffRole::DIRECTOR_OF_FOOTBALL));
        $this->em->persist($this->scout('German'));
        $this->em->flush();

        $byRole = $this->counts->staffByRole();

        // 'directors', not 'director_of_football' — the endpoint's vocabulary, not the
        // column's. Each template used to carry its own map to bridge the two.
        self::assertSame(1, $byRole['directors']['German'] ?? 0);
        self::assertSame(1, $byRole['scouts']['German'] ?? 0);
    }

    public function testRowsCarryTheFlagCodeAndTheNamePoolFlag(): void
    {
        $english = null;

        foreach ($this->counts->forPlayers()['UK & Ireland'] as $row) {
            if ($row['nationality'] === 'English') {
                $english = $row;
            }
        }

        self::assertNotNull($english);
        // `value`, not isoAlpha2() — public/assets/flags/en.svg exists so England flies its
        // own flag rather than GB's.
        self::assertSame('EN', $english['code']);
        self::assertTrue($english['hasNamePool']);
    }

    private function player(string $nationality, RecruitmentSource $source): Player
    {
        return new Player('Nat', self::MARKER, new \DateTimeImmutable('2000-01-01'), $nationality, PlayerPosition::MIDFIELDER, $source);
    }

    /** Staff setters return void, so no chaining. */
    private function staff(string $nationality, StaffRole $role): Staff
    {
        $s = new Staff();
        $s->setFirstName('Nat');
        $s->setLastName(self::MARKER);
        $s->setNationality($nationality);
        $s->setRole($role);

        return $s;
    }

    private function scout(string $nationality): Scout
    {
        $s = new Scout(self::MARKER);
        $s->setNationality($nationality);

        return $s;
    }
}
