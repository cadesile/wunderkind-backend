<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/owner-avatar — partial update. Only fields actually present in
 * the request body are applied; omitted fields are left untouched on the
 * User. See OwnerAvatarController for the avatar-resolution rules.
 */
class OwnerAvatarRequest
{
    #[Assert\Length(max: 100)]
    public ?string $name = null;

    /**
     * 'Y-m-d', or any ISO-8601 datetime starting with one — `2008-05-12T00:00:00.000Z`
     * included, which is what `Date.toISOString()` produces and what the onboarding flow
     * was sending. `Assert\Date` alone rejects that, and the rejection surfaced as the
     * non-JSON 422 in Sentry `155644075`: a player could not finish new-game setup at all.
     * Only the date part is ever read (see OwnerAvatarController::parseDob) — a DOB has no
     * time of day.
     *
     * An empty string is accepted and means "clear it", the same as an explicit null. It
     * used to reach `new \DateTimeImmutable('')`, which is *today* — so a blank field set
     * the owner's birthday to the day they signed up.
     */
    #[Assert\Regex(
        pattern: '/^$|^\d{4}-\d{2}-\d{2}([T ].*)?$/',
        message: 'This value should be a date as Y-m-d, optionally followed by a time.',
    )]
    public ?string $dob = null;

    #[Assert\Choice(choices: ['male', 'female'])]
    public ?string $gender = null;

    #[Assert\Length(max: 60)]
    public ?string $nationality = null;

    /** Full Appearance-shape config, client-supplied. Stored verbatim, no per-field validation. */
    public ?array $avatar = null;
}
