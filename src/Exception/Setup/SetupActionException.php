<?php

declare(strict_types=1);

namespace App\Exception\Setup;

/**
 * A setup step could not be completed, with a reason the player can be told.
 *
 * Mirrors PlotUnavailableException deliberately — same `reason` + `context` shape, same
 * named-constructor style, so the two halves of the setup flow (buy the land, then build and
 * fund the club) surface failures identically and the client has one error contract to handle.
 *
 * EVERY REASON HERE IS A NEW CLIENT-VISIBLE STRING. docs/api/setup-flow.md's closing note makes
 * the point that an older build will not recognise new codes, so they are listed in that doc
 * rather than left for a client to discover at the till.
 */
class SetupActionException extends \RuntimeException
{
    /** The named site is not one of the four facility categories. */
    public const UNKNOWN_SITE = 'unknown_site';
    /** A slug in `levels` is not an active facility of the named site. */
    public const UNKNOWN_FACILITY = 'unknown_facility';
    /** A requested level is above the template's maxLevel, or below zero. */
    public const INVALID_LEVEL = 'invalid_level';
    /** This site has already been charged, at DIFFERENT levels than those requested. */
    public const SITE_ALREADY_BUILT = 'site_already_built';
    /** The account's ledger credit does not cover the charge. */
    public const INSUFFICIENT_CREDIT = 'insufficient_credit';
    /** The club has already been funded, with a DIFFERENT amount than the one requested. */
    public const ALREADY_FUNDED = 'already_funded';
    /** The stake is below the operator-configured minimum. Only reachable when one is set. */
    public const BELOW_FUNDING_MINIMUM = 'below_funding_minimum';
    /** The stake is zero or negative. */
    public const INVALID_AMOUNT = 'invalid_amount';
    /** The quiz has no servable questions, so there is nothing to answer. */
    public const QUIZ_UNAVAILABLE = 'quiz_unavailable';
    /** This account has already answered that question — one attempt per account, ever. */
    public const ALREADY_ANSWERED = 'already_answered';
    /** The submitted ordinal is not one of the options offered. */
    public const INVALID_ANSWER = 'invalid_answer';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /** @param list<string> $known */
    public static function unknownSite(string $site, array $known): self
    {
        return new self(
            self::UNKNOWN_SITE,
            sprintf('"%s" is not a facility site. Expected one of: %s.', $site, implode(', ', $known)),
            ['site' => $site, 'knownSites' => $known],
        );
    }

    public static function unknownFacility(string $slug, string $site): self
    {
        return new self(
            self::UNKNOWN_FACILITY,
            sprintf('"%s" is not an active facility at the %s site.', $slug, $site),
            ['slug' => $slug, 'site' => $site],
        );
    }

    public static function invalidLevel(string $slug, int $level, int $maxLevel): self
    {
        return new self(
            self::INVALID_LEVEL,
            sprintf('Level %d is out of range for %s — it goes up to %d.', $level, $slug, $maxLevel),
            ['slug' => $slug, 'level' => $level, 'maxLevel' => $maxLevel],
        );
    }

    /**
     * Carries the levels that WERE built, not just the refusal.
     *
     * A client that got this because its retry lost a race needs to know what is actually
     * standing, and a client that got it because the player edited the site after paying needs
     * to show them what they already own. Without the levels it would have to re-read the
     * setup state to say anything useful.
     *
     * @param array<string, int> $builtLevels
     */
    public static function siteAlreadyBuilt(string $site, array $builtLevels, int $chargedPence): self
    {
        return new self(
            self::SITE_ALREADY_BUILT,
            sprintf('The %s site has already been built and charged for.', $site),
            ['site' => $site, 'builtLevels' => $builtLevels, 'chargedPence' => $chargedPence],
        );
    }

    public static function insufficientCredit(int $balancePence, int $requiredPence): self
    {
        return new self(
            self::INSUFFICIENT_CREDIT,
            sprintf(
                'Not enough credit: %s available, %s needed.',
                number_format($balancePence / 100, 2),
                number_format($requiredPence / 100, 2),
            ),
            ['balancePence' => $balancePence, 'requiredPence' => $requiredPence],
        );
    }

    public static function alreadyFunded(int $stakedPence): self
    {
        return new self(
            self::ALREADY_FUNDED,
            sprintf('This club has already been funded with %s.', number_format($stakedPence / 100, 2)),
            ['stakedPence' => $stakedPence],
        );
    }

    public static function belowFundingMinimum(int $amountPence, int $minimumPence): self
    {
        return new self(
            self::BELOW_FUNDING_MINIMUM,
            sprintf(
                'A club needs at least %s to start. You offered %s.',
                number_format($minimumPence / 100, 2),
                number_format($amountPence / 100, 2),
            ),
            ['amountPence' => $amountPence, 'minimumPence' => $minimumPence],
        );
    }

    public static function invalidAmount(): self
    {
        return new self(self::INVALID_AMOUNT, 'Stake a positive amount of credit.');
    }

    public static function quizUnavailable(): self
    {
        return new self(self::QUIZ_UNAVAILABLE, 'There is no quiz to take right now.');
    }

    public static function alreadyAnswered(): self
    {
        return new self(
            self::ALREADY_ANSWERED,
            'You have already answered that question. The quiz is one attempt per account.',
        );
    }

    public static function invalidAnswer(int $chosenIndex, int $optionCount): self
    {
        return new self(
            self::INVALID_ANSWER,
            sprintf('%d is not one of the %d answers offered.', $chosenIndex, $optionCount),
            ['chosenIndex' => $chosenIndex, 'optionCount' => $optionCount],
        );
    }
}
