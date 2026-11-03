<?php

declare(strict_types=1);

namespace App\Exception\Location;

/**
 * A plot could not be held or claimed, with a reason the player can be told.
 *
 * Typed cases rather than one message, because the API maps them to different status codes and
 * the client shows different things: "someone else is looking at this" is transient and worth
 * retrying, "you already hold a plot" is the player's own doing, and "not for sale" is permanent.
 * A single exception with a string would push that distinction into message matching.
 */
class PlotUnavailableException extends \RuntimeException
{
    public const PLOT_HELD        = 'plot_held';
    public const HOLD_LIMIT       = 'hold_limit';
    public const PLOT_CLAIMED     = 'plot_claimed';
    public const NOT_SELLABLE     = 'plot_not_sellable';
    public const HOLD_EXPIRED     = 'hold_expired';
    public const HOLD_NOT_YOURS   = 'hold_not_yours';
    public const HOLD_CONSUMED    = 'hold_consumed';
    public const INSUFFICIENT     = 'insufficient_credit';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function plotHeld(): self
    {
        return new self(
            self::PLOT_HELD,
            'Someone else is holding this plot. Holds last 15 minutes — try again shortly.',
        );
    }

    public static function holdLimit(string $heldTown): self
    {
        return new self(
            self::HOLD_LIMIT,
            sprintf('You already have a hold on %s. Release it before holding another plot.', $heldTown),
            ['heldTown' => $heldTown],
        );
    }

    public static function plotClaimed(?string $claimedBy): self
    {
        return new self(
            self::PLOT_CLAIMED,
            $claimedBy === null
                ? 'This plot has already been claimed.'
                : sprintf('This plot has already been claimed by %s.', $claimedBy),
            ['claimedBy' => $claimedBy],
        );
    }

    /** Pre-enrichment, retired, or with no price — all "not for sale right now". */
    public static function notSellable(string $why): self
    {
        return new self(self::NOT_SELLABLE, 'This plot is not available to buy: ' . $why, ['why' => $why]);
    }

    public static function holdExpired(): self
    {
        return new self(self::HOLD_EXPIRED, 'That hold has expired. Take a new one to see the current price.');
    }

    public static function holdNotYours(): self
    {
        return new self(self::HOLD_NOT_YOURS, 'That hold belongs to another account.');
    }

    public static function holdConsumed(): self
    {
        return new self(self::HOLD_CONSUMED, 'That hold has already been used to claim the plot.');
    }

    public static function insufficientCredit(int $balancePence, int $requiredPence): self
    {
        return new self(
            self::INSUFFICIENT,
            sprintf(
                'Not enough credit: %s available, %s needed.',
                number_format($balancePence / 100, 2),
                number_format($requiredPence / 100, 2),
            ),
            ['balancePence' => $balancePence, 'requiredPence' => $requiredPence],
        );
    }
}
