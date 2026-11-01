<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * User::getRoles() guarantees ROLE_CLUB rather than reading it out of the column.
 *
 * The column has twice held something else: accounts registered before 6dc85ab stored `[]`,
 * and accounts between 6dc85ab and 2a9e6cd stored the since-deleted `ROLE_ACADEMY`. With no
 * role_hierarchy in security.yaml, either value answered 403 to every `roles: ROLE_CLUB` route
 * for an account that had just logged in successfully — which the client cannot tell apart from
 * a refused account. See docs/api/role-club-403.md.
 */
class UserRolesTest extends TestCase
{
    public function testAnAccountWithNoStoredRolesStillCarriesRoleClub(): void
    {
        $user = new User('legacy-empty@example.com');

        $this->assertContains(User::ROLE_CLUB, $user->getRoles());
    }

    public function testARetiredStoredRoleDoesNotDisplaceRoleClub(): void
    {
        $user = new User('legacy-academy@example.com');
        $user->setRoles(['ROLE_ACADEMY']);

        $roles = $user->getRoles();

        $this->assertContains(User::ROLE_CLUB, $roles);
        // Kept rather than dropped: the guarantee is additive, so nothing else needs to decide
        // what a role it does not recognise meant.
        $this->assertContains('ROLE_ACADEMY', $roles);
    }

    /**
     * The documented EasyAdmin grant in CLAUDE.md writes ROLE_ADMIN over this column by hand.
     * That must not cost the account its game access.
     */
    public function testAHandWrittenAdminRoleDoesNotRevokeGameAccess(): void
    {
        $user = new User('hand-granted-admin@example.com');
        $user->setRoles(['ROLE_ADMIN']);

        $this->assertSame(['ROLE_ADMIN', User::ROLE_CLUB], $user->getRoles());
    }

    public function testRoleClubIsNotDuplicatedWhenAlreadyStored(): void
    {
        $user = new User('registered@example.com');
        $user->setRoles([User::ROLE_CLUB]);

        // array_values(), so the list stays a JSON array for the JWT payload rather than
        // encoding as an object with a gap in its keys.
        $this->assertSame([User::ROLE_CLUB], $user->getRoles());
    }
}
