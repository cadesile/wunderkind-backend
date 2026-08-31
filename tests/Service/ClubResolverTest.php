<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\User;
use App\Exception\ClubMismatchException;
use App\Repository\ClubRepository;
use App\Service\ClubResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * A user owns one club per save slot (Club::$user is ManyToOne), so "the user's newest
 * club" is only ever a guess. Resolving by that guess is what merged two saves' progress
 * onto a single club — these tests pin the behaviour that replaced it.
 */
class ClubResolverTest extends TestCase
{
    private function user(UuidV7 $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function club(User $owner): Club
    {
        $club = $this->createMock(Club::class);
        $club->method('getUser')->willReturn($owner);

        return $club;
    }

    private function resolver(ClubRepository $repo, ?Request $request = null): ClubResolver
    {
        $stack = new RequestStack();
        if ($request !== null) {
            $stack->push($request);
        }

        return new ClubResolver($repo, $stack);
    }

    public function testFallsBackToNewestClubWhenNoIdIsGiven(): void
    {
        $user     = $this->user(Uuid::v7());
        $expected = $this->club($user);

        $repo = $this->createMock(ClubRepository::class);
        $repo->expects($this->once())->method('findByUser')->with($user)->willReturn($expected);

        // Clients released before clubId existed must keep working unchanged.
        $this->assertSame($expected, $this->resolver($repo)->resolve($user, null));
    }

    public function testTreatsBlankIdAsAbsent(): void
    {
        $user     = $this->user(Uuid::v7());
        $expected = $this->club($user);

        $repo = $this->createMock(ClubRepository::class);
        $repo->expects($this->once())->method('findByUser')->willReturn($expected);

        $this->assertSame($expected, $this->resolver($repo)->resolve($user, '  '));
    }

    public function testResolvesTheNamedClubWhenTheUserOwnsIt(): void
    {
        $userId   = Uuid::v7();
        $user     = $this->user($userId);
        $owner    = $this->user($userId);
        $clubId   = Uuid::v7();
        $expected = $this->club($owner);

        $repo = $this->createMock(ClubRepository::class);
        $repo->expects($this->never())->method('findByUser');
        $repo->expects($this->once())->method('find')->willReturn($expected);

        $this->assertSame($expected, $this->resolver($repo)->resolve($user, (string) $clubId));
    }

    public function testRejectsAClubOwnedBySomeoneElse(): void
    {
        $user       = $this->user(Uuid::v7());
        $otherOwner = $this->user(Uuid::v7());
        $clubId     = (string) Uuid::v7();

        $repo = $this->createMock(ClubRepository::class);
        $repo->method('find')->willReturn($this->club($otherOwner));

        // Never silently fall back — a wrong id must fail, not get re-attributed.
        $this->expectException(ClubMismatchException::class);
        $this->resolver($repo)->resolve($user, $clubId);
    }

    public function testRejectsAnUnknownClubId(): void
    {
        $user = $this->user(Uuid::v7());

        $repo = $this->createMock(ClubRepository::class);
        $repo->method('find')->willReturn(null);

        $this->expectException(ClubMismatchException::class);
        $this->resolver($repo)->resolve($user, (string) Uuid::v7());
    }

    public function testRejectsAMalformedClubIdWithoutQueryingTheDatabase(): void
    {
        $user = $this->user(Uuid::v7());

        $repo = $this->createMock(ClubRepository::class);
        $repo->expects($this->never())->method('find');
        $repo->expects($this->never())->method('findByUser');

        $this->expectException(ClubMismatchException::class);
        $this->resolver($repo)->resolve($user, 'not-a-uuid');
    }

    public function testMismatchCarriesA403(): void
    {
        // Symfony's kernel derives the response status from HttpExceptionInterface, so
        // this is what makes a mismatch surface as 403 rather than a 500. The client's
        // sync queue treats a 4xx as final and drops the payload rather than retrying it
        // forever, which depends on this exact status.
        $exception = new ClubMismatchException('11111111-2222-3333-4444-555555555555');

        $this->assertInstanceOf(HttpExceptionInterface::class, $exception);
        $this->assertSame(403, $exception->getStatusCode());
    }

    public function testResolvesFromTheXClubIdHeader(): void
    {
        $userId   = Uuid::v7();
        $user     = $this->user($userId);
        $expected = $this->club($this->user($userId));

        $repo = $this->createMock(ClubRepository::class);
        $repo->expects($this->once())->method('find')->willReturn($expected);
        $repo->expects($this->never())->method('findByUser');

        $request = new Request();
        $request->headers->set(ClubResolver::CLUB_ID_HEADER, (string) Uuid::v7());

        $this->assertSame($expected, $this->resolver($repo, $request)->resolveFromRequest($user));
    }

    public function testHeaderlessRequestFallsBackToNewestClub(): void
    {
        $user     = $this->user(Uuid::v7());
        $expected = $this->club($user);

        $repo = $this->createMock(ClubRepository::class);
        $repo->expects($this->once())->method('findByUser')->willReturn($expected);

        $this->assertSame($expected, $this->resolver($repo, new Request())->resolveFromRequest($user));
    }
}
