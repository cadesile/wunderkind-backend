<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The kinds of cheat a club can report in its sync's `illicitActivity` list (2026-11-27).
 *
 * The record shape is generic: a new cheat adds a case here and its `details` rules in
 * IllicitActivityValidator, without changing the payload, the table or the admin list.
 */
enum IllicitActivityKind: string
{
    /** A sham sponsorship deal the Personal Assistant pitched and the owner accepted. */
    case FAKE_SPONSOR = 'fake_sponsor';
}
