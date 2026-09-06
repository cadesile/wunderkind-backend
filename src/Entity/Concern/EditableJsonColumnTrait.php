<?php

declare(strict_types=1);

namespace App\Entity\Concern;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Support for editing a Doctrine `json` column as raw JSON text in the admin.
 *
 * The naive form of this — `$this->col = json_decode($v, true) ?? []` — destroys the stored
 * value the moment someone submits a stray comma, with no error shown. That was survivable
 * when the admin UI was a structured form; it is not, now that the JSON textarea is the only
 * way to edit these fields.
 *
 * So a decode failure is *remembered* rather than applied: the column keeps its current
 * value, the getter hands the invalid text back so the form redisplays exactly what was
 * typed, and validateJsonColumns() reports it against the right field.
 */
trait EditableJsonColumnTrait
{
    /**
     * Raw admin input that failed to decode, keyed by virtual property name.
     * Never persisted — it exists only for the lifetime of the failing request.
     *
     * @var array<string, string>
     */
    private array $invalidJsonInput = [];

    /**
     * Decodes submitted JSON, or records it as invalid and returns null so the caller can
     * leave its column untouched.
     */
    private function decodeJsonInput(string $property, string $json): ?array
    {
        unset($this->invalidJsonInput[$property]);

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            $this->invalidJsonInput[$property] = $json;
            return null;
        }

        return $decoded;
    }

    /** The invalid text last submitted for this property, so the form can redisplay it. */
    private function invalidJsonInputFor(string $property): ?string
    {
        return $this->invalidJsonInput[$property] ?? null;
    }

    #[Assert\Callback]
    public function validateJsonColumns(ExecutionContextInterface $context): void
    {
        foreach ($this->invalidJsonInput as $property => $raw) {
            // json_last_error_msg() reflects the most recent decode anywhere in the process,
            // so re-decode this value to get a message that actually describes it.
            json_decode($raw, true);
            $reason = json_last_error() !== JSON_ERROR_NONE
                ? json_last_error_msg()
                : 'the value must be a JSON object or array, not a single scalar';

            $context->buildViolation('Invalid JSON — {{ reason }}. The stored value has been left unchanged.')
                ->setParameter('{{ reason }}', lcfirst($reason))
                ->atPath($property)
                ->addViolation();
        }
    }
}
