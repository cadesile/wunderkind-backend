<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whether an archetype describes a desirable or an undesirable personality.
 *
 * The catalogue is curated at 10 of each. The client resolves a dual report per
 * player — one best-match POSITIVE and one best-match NEGATIVE — so both lists
 * must stay populated.
 */
enum ArchetypePolarity: string
{
    case POSITIVE = 'positive';
    case NEGATIVE = 'negative';
}
