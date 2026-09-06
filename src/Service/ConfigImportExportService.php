<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GameConfig;
use App\Entity\PoolConfig;
use App\Entity\StarterConfig;
use App\Repository\GameConfigRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\StarterConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Exports and imports the three singleton config entities as JSON.
 *
 * The field list is **derived from the entities by reflection**, not hand-maintained: every
 * `#[ORM\Column]` property is covered automatically, so adding a config field to an entity
 * cannot silently fall out of a backup. The only decision left to make when adding a field
 * is whether it belongs on DENIED_PROPERTIES below.
 *
 * `tests/Service/ConfigImportExportCoverageTest.php` enforces both halves of that contract.
 */
class ConfigImportExportService
{
    private const EXPORT_VERSION = 1;

    /**
     * Properties deliberately excluded from export/import. `#[ORM\Id]` columns are skipped
     * automatically and do not need listing here.
     *
     * Every entry must name a property that still exists — the coverage test fails on stale
     * entries, so a rename cannot quietly re-expose a secret.
     *
     * @var array<class-string, string[]>
     */
    public const DENIED_PROPERTIES = [
        GameConfig::class => [
            // Credentials. The export file is documented to admins as safe to commit to
            // version control, so it must never carry secrets.
            'recaptchaSiteKey',
            'recaptchaSecretKey',
            // Runtime state written by app:post-community-stat, not configuration —
            // restoring it would rewind the round-robin cursor.
            'lastPostedStatCategory',
        ],
        StarterConfig::class => [],
        PoolConfig::class    => [],
    ];

    public function __construct(
        private readonly GameConfigRepository    $gameConfigRepository,
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly PoolConfigRepository    $poolConfigRepository,
        private readonly EntityManagerInterface  $em,
    ) {}

    // ── Export ────────────────────────────────────────────────────────────────

    public function export(): array
    {
        return [
            'version'       => self::EXPORT_VERSION,
            'exportedAt'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'gameConfig'    => self::exportEntity($this->gameConfigRepository->getConfig()),
            'starterConfig' => self::exportEntity($this->starterConfigRepository->getConfig()),
            'poolConfig'    => self::exportEntity($this->poolConfigRepository->getConfig()),
        ];
    }

    // ── Import ────────────────────────────────────────────────────────────────

    /**
     * Applies every field it understands and reports the rest, rather than aborting on the
     * first bad value — a single malformed enum in a hand-edited file should not discard the
     * other two hundred settings.
     *
     * @return array{applied: bool, errors: string[]}
     */
    public function import(array $data): array
    {
        $result = ['applied' => false, 'errors' => []];

        if (($data['version'] ?? null) !== self::EXPORT_VERSION) {
            $result['errors'][] = 'Unsupported export version — expected version ' . self::EXPORT_VERSION;
            return $result;
        }

        $sections = [
            'gameConfig'    => fn () => $this->gameConfigRepository->getConfig(),
            'starterConfig' => fn () => $this->starterConfigRepository->getConfig(),
            'poolConfig'    => fn () => $this->poolConfigRepository->getConfig(),
        ];

        try {
            foreach ($sections as $key => $fetch) {
                if (!is_array($data[$key] ?? null)) {
                    continue;
                }
                foreach (self::applyEntity($fetch(), $data[$key]) as $error) {
                    $result['errors'][] = $key . '.' . $error;
                }
            }

            $this->em->flush();
            $result['applied'] = true;
        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    // ── Reflection plumbing ───────────────────────────────────────────────────

    /**
     * Mapped column properties of an entity, minus the identifier and anything denied.
     *
     * @return string[]
     */
    public static function exportableProperties(string $class): array
    {
        $denied = self::DENIED_PROPERTIES[$class] ?? [];
        $names  = [];

        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            if ($property->getAttributes(ORM\Column::class) === []) {
                continue;
            }
            if ($property->getAttributes(ORM\Id::class) !== []) {
                continue;
            }
            if (in_array($property->getName(), $denied, true)) {
                continue;
            }
            $names[] = $property->getName();
        }

        sort($names);

        return $names;
    }

    /** Serializes every exportable property, keyed by property name. */
    public static function exportEntity(object $entity): array
    {
        $row = [];

        foreach (self::exportableProperties($entity::class) as $name) {
            $getter = self::resolveGetter($entity, $name);
            if ($getter === null) {
                // No accessor — the coverage test turns this into a build failure rather
                // than a silently absent key.
                continue;
            }

            $value = $entity->$getter();
            $row[$name] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $row;
    }

    /**
     * Applies every recognised key in $row to $entity.
     *
     * Uses array_key_exists rather than isset so `false` and explicit `null` are applied —
     * with isset, a boolean could only ever be imported as true.
     *
     * @return string[] per-field error messages, prefixed with the property name
     */
    public static function applyEntity(object $entity, array $row): array
    {
        $errors = [];

        foreach (self::exportableProperties($entity::class) as $name) {
            if (!array_key_exists($name, $row)) {
                continue;
            }

            $setter = 'set' . ucfirst($name);
            if (!method_exists($entity, $setter)) {
                continue;
            }

            try {
                $entity->$setter(self::coerce($entity, $setter, $row[$name]));
            } catch (\Throwable $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }

        return $errors;
    }

    private static function resolveGetter(object $entity, string $name): ?string
    {
        foreach (['get' . ucfirst($name), 'is' . ucfirst($name), $name] as $candidate) {
            if (method_exists($entity, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Casts a raw JSON value to whatever the setter's own signature declares. */
    private static function coerce(object $entity, string $setter, mixed $value): mixed
    {
        $parameters = (new \ReflectionMethod($entity, $setter))->getParameters();
        $type       = $parameters[0]->getType() ?? null;

        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        if ($value === null) {
            if (!$type->allowsNull()) {
                throw new \InvalidArgumentException('null is not allowed.');
            }
            return null;
        }

        $typeName = $type->getName();

        if (is_subclass_of($typeName, \BackedEnum::class)) {
            $case = (is_string($value) || is_int($value)) ? $typeName::tryFrom($value) : null;
            if ($case === null) {
                throw new \InvalidArgumentException(sprintf(
                    'invalid value "%s" — expected one of: %s',
                    is_scalar($value) ? (string) $value : gettype($value),
                    implode(', ', array_column($typeName::cases(), 'value')),
                ));
            }
            return $case;
        }

        return match ($typeName) {
            'int'    => (int) $value,
            'float'  => (float) $value,
            'bool'   => (bool) $value,
            'string' => (string) $value,
            'array'  => (array) $value,
            default  => $value,
        };
    }
}
