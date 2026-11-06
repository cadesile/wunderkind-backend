<?php

declare(strict_types=1);

namespace App\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a selection of entities out as CSV.
 *
 * Columns are the entity's own scalar fields rather than the handful the list
 * page shows: the point of an export is to get the data out, and anything
 * hidden on screen for width reasons is exactly what someone is likely to want
 * in a spreadsheet. Associations are reduced to their identifier, and anything
 * that is not scalar is JSON-encoded rather than dropped.
 *
 * StreamedResponse, because a filtered selection can be the whole table and
 * building the string in memory first would be the one thing that makes a
 * large export fail.
 */
final class CsvExporter
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @param iterable<int, array<object>> $chunks as produced by BatchSelection
     */
    public function stream(string $entityFqcn, iterable $chunks, string $filename): StreamedResponse
    {
        $metadata = $this->em->getClassMetadata($entityFqcn);
        $columns  = array_merge($metadata->getFieldNames(), $metadata->getAssociationNames());

        $response = new StreamedResponse(function () use ($chunks, $columns, $metadata): void {
            $out = fopen('php://output', 'wb');

            // Excel reads a bare UTF-8 CSV as the system codepage and mangles
            // every accented club name; the BOM is what stops that.
            fwrite($out, "\u{FEFF}");
            fputcsv($out, $columns);

            foreach ($chunks as $rows) {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(
                        fn (string $column): string => $this->cell($metadata, $row, $column),
                        $columns,
                    ));
                }
                flush();
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        ));
        // The response is built row by row, so it must not be buffered or
        // cached anywhere between here and the browser.
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public static function filename(string $noun): string
    {
        return \sprintf('%s-%s.csv', $noun, (new \DateTimeImmutable())->format('Y-m-d'));
    }

    private function cell(\Doctrine\ORM\Mapping\ClassMetadata $metadata, object $row, string $column): string
    {
        try {
            $value = $metadata->getFieldValue($row, $column);
        } catch (\Throwable) {
            return '';
        }

        return match (true) {
            null === $value                  => '',
            \is_bool($value)                 => $value ? 'true' : 'false',
            \is_scalar($value)               => (string) $value,
            $value instanceof \BackedEnum     => (string) $value->value,
            $value instanceof \UnitEnum       => $value->name,
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            // A to-many association is a collection of rows, not a value; its
            // size is the only thing that fits in a cell.
            $value instanceof \Doctrine\Common\Collections\Collection => (string) $value->count(),
            \is_object($value)               => $this->objectCell($value),
            default                          => json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '',
        };
    }

    private function objectCell(object $value): string
    {
        if (method_exists($value, 'getId')) {
            return (string) $value->getId();
        }

        return $value instanceof \Stringable ? (string) $value : $value::class;
    }
}
