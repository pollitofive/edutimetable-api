<?php

namespace App\Services\Import;

use DateTimeInterface;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads the first sheet of an xlsx or csv into a header row plus data rows, all as
 * trimmed strings. Stops as soon as the file goes over a limit, so a big (or zipped
 * up) file is never read entirely.
 */
class SpreadsheetReader
{
    public const MAX_ROWS = 2000;

    public const MAX_COLUMNS = 50;

    public const MAX_CELL_LENGTH = 500;

    /**
     * row_numbers: the spreadsheet row of each data row (empty rows are left out of rows).
     *
     * @return array{headers: list<string>, rows: list<list<string>>, row_numbers: list<int>}
     */
    public function read(string $path, string $extension): array
    {
        // Empty rows are kept by the reader (and skipped here) to know the real row numbers
        $reader = strtolower($extension) === 'csv'
            ? $this->csvReader($path)
            : new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true));

        try {
            $reader->open($path);

            return $this->firstSheet($reader);
        } catch (OpenSpoutException) {
            throw new ImportFileException('unreadable');
        } finally {
            $reader->close();
        }
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>>, row_numbers: list<int>}
     */
    private function firstSheet(ReaderInterface $reader): array
    {
        $headers = null;
        $rows = [];
        $rowNumbers = [];
        $rowNumber = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rowNumber++;
                $cells = array_map($this->cellToString(...), array_slice($row->toArray(), 0, self::MAX_COLUMNS));

                if (implode('', $cells) === '') {
                    continue;
                }

                if ($headers === null) {
                    $headers = $this->headers($cells);

                    continue;
                }

                if (count($rows) === self::MAX_ROWS) {
                    throw new ImportFileException('too_many_rows', ['max' => self::MAX_ROWS]);
                }

                // Same width as the header: missing cells are empty, extra ones are dropped
                $rows[] = array_slice(array_pad($cells, count($headers), ''), 0, count($headers));
                $rowNumbers[] = $rowNumber;
            }

            break;
        }

        if ($headers === null || $rows === []) {
            throw new ImportFileException('empty');
        }

        return ['headers' => $headers, 'rows' => $rows, 'row_numbers' => $rowNumbers];
    }

    /**
     * Empty header cells get a generic name, so every column can still be mapped.
     *
     * @param  list<string>  $cells
     * @return list<string>
     */
    private function headers(array $cells): array
    {
        // Trailing empty headers are just the end of the row
        while ($cells !== [] && end($cells) === '') {
            array_pop($cells);
        }

        return array_map(
            fn (string $cell, int $i) => $cell !== '' ? $cell : 'Columna '.($i + 1),
            $cells,
            array_keys($cells),
        );
    }

    private function cellToString(mixed $value): string
    {
        $string = match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? '1' : '0',
            // Phones typed as numbers come as floats: 1155667788.0 → "1155667788"
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (string) (int) $value,
            is_scalar($value) => (string) $value,
            default => '',
        };

        return mb_substr(trim($string), 0, self::MAX_CELL_LENGTH);
    }

    /**
     * Excel in Spanish saves csv with ";" and in Windows-1252 (Latin-1), so both the
     * separator and the encoding are detected instead of assumed.
     */
    private function csvReader(string $path): CsvReader
    {
        // The upload is capped at 2 MB, so the whole file is checked: a partial sample
        // could cut a UTF-8 character in half and look like Latin-1.
        $content = (string) file_get_contents($path);
        $firstLine = strtok(preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? '', "\r\n") ?: '';

        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $delimiter = $counts[array_key_first($counts)] > 0 ? array_key_first($counts) : ',';

        $encoding = mb_check_encoding($content, 'UTF-8') ? 'UTF-8' : 'Windows-1252';

        return new CsvReader(new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true, FIELD_DELIMITER: $delimiter, ENCODING: $encoding));
    }
}
