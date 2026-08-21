<?php

namespace App\Tests\Service;

use App\Entity\Player;
use App\Service\SyncService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the "signing a transfer-listed player crashes the game" bug.
 *
 * Player::$id is a uuid column, but pool rows are deleted the moment they're signed
 * (MarketPoolService::assignToClub), so the ids the client sends back in signings[],
 * transfers[] and playerUpdates[] are its own locally-generated strings — exactly what
 * PlayerCareerStat::$playerId documents as "not a FK to Player".
 *
 * Feeding one of those to EntityManager::find() made Doctrine's UuidType throw a
 * ConversionException. SyncController::sync has no try/catch, so POST /api/sync returned 500
 * and discarded the entire payload — transfers, match results and career stats alike. From
 * the client that looked like signing a player had wiped the squad's history.
 */
class SyncServicePlayerLookupTest extends TestCase
{
    /** SyncService has a wide constructor; only the EntityManager matters to this method. */
    private function service(EntityManagerInterface $em): SyncService
    {
        $service = (new \ReflectionClass(SyncService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(SyncService::class, 'em'))->setValue($service, $em);

        return $service;
    }

    private function lookup(SyncService $service, mixed $id): ?Player
    {
        return (new \ReflectionMethod(SyncService::class, 'findPlayerByClientId'))
            ->invoke($service, $id);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonUuidIdentifiers(): array
    {
        return [
            'client-generated id' => ['local-1234'],
            'numeric string'      => ['42'],
            'empty string'        => [''],
            'null'                => [null],
            'integer'             => [42],
            'array'               => [['nope']],
            'truncated uuid'      => ['0192f3b1-5f2a-7c3d-8e4f'],
        ];
    }

    #[DataProvider('nonUuidIdentifiers')]
    public function testNonUuidIdentifierReturnsNullWithoutQueryingOrThrowing(mixed $id): void
    {
        // The EntityManager must never be reached — that call is what threw ConversionException.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');

        $this->assertNull($this->lookup($this->service($em), $id));
    }

    public function testValidUuidIsLookedUpNormally(): void
    {
        $uuid   = '0192f3b1-5f2a-7c3d-8e4f-a1b2c3d4e5f6';
        $player = $this->createStub(Player::class);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willReturn($player);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getRepository')
            ->with(Player::class)
            ->willReturn($repository);

        $this->assertSame($player, $this->lookup($this->service($em), $uuid));
    }

    public function testValidUuidWithNoMatchingRowReturnsNull(): void
    {
        // The common case after signing: the pool row is gone, but the sync must still succeed.
        $uuid = '0192f3b1-5f2a-7c3d-8e4f-a1b2c3d4e5f6';

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $this->assertNull($this->lookup($this->service($em), $uuid));
    }
}
