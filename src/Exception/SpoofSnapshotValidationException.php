<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown by CompetitionSpoofEntrantService::createSpoofEntrantFromSnapshot() when an
 * admin-pasted snapshot fails SnapshotValidator's structural checks. Carries the
 * violation list so the admin form can re-render with specific feedback instead of a
 * generic "something went wrong".
 */
class SpoofSnapshotValidationException extends \InvalidArgumentException
{
    /** @param list<string> $violations */
    public function __construct(private readonly array $violations)
    {
        parent::__construct('Snapshot failed validation: ' . implode('; ', $violations));
    }

    /** @return list<string> */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
