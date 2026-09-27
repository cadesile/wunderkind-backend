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

    /** 'Y-m-d' */
    #[Assert\Date]
    public ?string $dob = null;

    #[Assert\Choice(choices: ['male', 'female'])]
    public ?string $gender = null;

    #[Assert\Length(max: 60)]
    public ?string $nationality = null;

    /** Full Appearance-shape config, client-supplied. Stored verbatim, no per-field validation. */
    public ?array $avatar = null;
}
