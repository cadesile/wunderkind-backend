<?php

namespace App\Dto;

/**
 * POST /api/club/kit-identity — partial update, same convention as
 * OwnerAvatarRequest: only fields actually present in the request body are
 * applied; omitted fields are left untouched on the Club (see
 * ClubKitIdentityController). Each is the full client-supplied config,
 * stored verbatim — the app's own kit/badge builder is the source of truth,
 * no server-side per-field validation, same trust model as
 * OwnerAvatarRequest::$avatar.
 */
class ClubKitIdentityRequest
{
    /** {kit, primary, secondary, shorts, socks} */
    public ?array $homeKitConfig = null;

    /** {kit, primary, secondary, shorts, socks} */
    public ?array $awayKitConfig = null;

    /** {badgeShape, badgePattern, badgeCentre, initials, badgeFill, badgeTrim, badgeSymbol} */
    public ?array $badgeConfig = null;
}
