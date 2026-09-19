<?php

namespace App\Dto;

use App\Enum\DevicePlatform;
use Symfony\Component\Validator\Constraints as Assert;

/** Payload for POST /api/device-tokens. */
class RegisterDeviceTokenRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 4096)]
    public string $deviceToken = '';

    #[Assert\NotNull]
    public ?DevicePlatform $platform = null;

    #[Assert\Length(max: 255)]
    public ?string $deviceId = null;
}
