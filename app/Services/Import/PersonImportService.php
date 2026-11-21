<?php

namespace App\Services\Import;

use App\Models\Student;
use App\Models\Teacher;
use App\Validation\PersonRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Import of students or teachers from the rows of a spreadsheet. Stateless: the client
 * sends back the parsed rows with the column mapping, the level mapping (students) and
 * what to do with emails that already exist, both to preview and to commit.
 */
class PersonImportService
{
    public const TYPES = ['students', 'teachers'];

    public const DUPLICATES_SKIP = 'skip';

    public const DUPLICATES_UPDATE = 'update';

    public const STATUS_NEW = 'new';

    public const STATUS_UPDATE = 'update';

    public const STATUS_SKIP = 'skip';

    public const STATUS_ERROR = 'error';

    /** Header names (lowercase, without accents) recognized for each field. */
    private const SYNONYMS = [
        'name' => ['nombre', 'nombre y apellido', 'apellido y nombre', 'nombre completo', 'alumno', 'alumna', 'profesor', 'profesora', 'docente', 'name', 'full name'],
        'email' => ['email', 'e-mail', 'mail', 'correo', 'correo electronico', 'email address'],
        'phone' => ['telefono', 'tel', 'celular', 'cel', 'movil', 'whatsapp', 'phone', 'mobile'],
        'level' => ['nivel', 'level', 'curso', 'course'],
    ];

    /** Validator rule (as Validator::failed() names it) → error code of lang import.errors. */
    private const RULE_CODES = [
        'Required' => 'required',
        'Email' => 'email',
        'Max' => 'max',
        'Unique' => 'taken',
    ];

    /**
     * @return list<string>
     */
    public function fields(string $type): array
    {
        return $type === 'students' ? ['name', 'email', 'phone', 'level'] : ['name', 'email', 'phone'];
    }

    /**
     * Column suggested for each field, by header name. A column is used for one field at most.
     *
     * @param  list<string>  $headers
     * @return array<string, int|null>
     */
    public function suggestMapping(string $type, array $headers): array
    {
        $normalized = array_map($this->normalizeHeader(...), $headers);
        $mapping = [];
        $used = [];

        foreach ($this->fields($type) as $field) {
            $mapping[$field] = $this->findColumn($normalized, self::SYNONYMS[$field], $used);

            if ($mapping[$field] !== null) {
                $used[] = $mapping[$field];
            }
        }

        return $mapping;
    }

    /**
     * Distinct values of a column, with how many rows have each, in natural order.
     *
     * @param  list<list<string>>  $rows
     * @return list<array{value: string, count: int}>
     */
    public function distinctValues(array $rows, ?int $column): array
    {
        if ($column === null) {
            return [];
        }

        $counts = collect($rows)
            ->map(fn (array $row) => $this->cell($row, $column))
            ->filter(fn (string $value) => $value !== '')
            ->countBy()
            ->all();

        uksort($counts, 'strnatcasecmp');

        return array_map(
            fn ($value, int $count) => ['value' => (string) $value, 'count' => $count],
            array_keys($counts),
            $counts,
        );
    }

    /**
     * What would happen with each row.
     *
     * @param  list<list<string>>  $rows
     * @param  array<string, int|null>  $mapping
     * @param  array<string, int|null>  $levelMapping  level value of the file → course_level_id
     * @param  list<int>  $rowNumbers  spreadsheet row of each row, as parse returns them
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function preview(string $type, array $rows, array $mapping, array $levelMapping, string $duplicates, array $rowNumbers = []): array
    {
        $analyzed = $this->analyze($type, $rows, $mapping, $levelMapping, $duplicates, $rowNumbers);

        return [
            'summary' => $this->summary($analyzed),
            'rows' => array_map(fn (array $row) => array_diff_key($row, ['model' => true]), $analyzed),
        ];
    }

    /**
     * Creates and updates the valid rows in one transaction. Rows with errors are left out.
     *
     * @param  list<list<string>>  $rows
     * @param  array<string, int|null>  $mapping
     * @param  array<string, int|null>  $levelMapping
     * @param  list<int>  $rowNumbers
     * @return array{created: int, updated: int, skipped: int, rejected: int}
     */
    public function commit(string $type, array $rows, array $mapping, array $levelMapping, string $duplicates, array $rowNumbers = []): array
    {
        $analyzed = $this->analyze($type, $rows, $mapping, $levelMapping, $duplicates, $rowNumbers);

        DB::transaction(function () use ($type, $analyzed) {
            foreach ($analyzed as $row) {
                match ($row['status']) {
                    self::STATUS_NEW => $this->save($type, $this->newModel($type), $row['data']),
                    self::STATUS_UPDATE => $this->save($type, $row['model'], $row['data']),
                    default => null,
                };
            }
        });

        $summary = $this->summary($analyzed);

        return [
            'created' => $summary[self::STATUS_NEW],
            'updated' => $summary[self::STATUS_UPDATE],
            'skipped' => $summary[self::STATUS_SKIP],
            'rejected' => $summary[self::STATUS_ERROR],
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int|null>  $mapping
     * @param  array<string, int|null>  $levelMapping
     * @param  list<int>  $rowNumbers
     * @return list<array<string, mixed>>
     */
    private function analyze(string $type, array $rows, array $mapping, array $levelMapping, string $duplicates, array $rowNumbers): array
    {
        $records = array_map(fn (array $row) => $this->record($type, $row, $mapping, $levelMapping), $rows);
        $existing = $this->existingByEmail($type, array_column($records, 'email'));
        $firstRowByEmail = [];
        $result = [];

        foreach ($records as $i => $record) {
            // Row number as the user sees it in the spreadsheet. Without row_numbers from
            // parse, the rows are taken as consecutive under a header in row 1.
            $rowNumber = $rowNumbers[$i] ?? $i + 2;
            $email = $record['email'];
            $model = $existing[$email] ?? null;

            if ($email !== '' && isset($firstRowByEmail[$email])) {
                $errors = [$this->error('email', 'duplicate_in_file', ['row' => $firstRowByEmail[$email]])];
            } elseif ($model && $duplicates === self::DUPLICATES_SKIP) {
                $errors = null;
            } else {
                $errors = $this->validate($type, $record, $model);
            }

            if ($email !== '') {
                $firstRowByEmail[$email] ??= $rowNumber;
            }

            $status = match (true) {
                $errors === null => self::STATUS_SKIP,
                $errors !== [] => self::STATUS_ERROR,
                $model !== null => self::STATUS_UPDATE,
                default => self::STATUS_NEW,
            };

            $result[] = [
                'row' => $rowNumber,
                'status' => $status,
                'data' => $record,
                'errors' => $errors ?? [],
                'existing_id' => $model?->id,
                'model' => $model,
            ];
        }

        return $result;
    }

    /**
     * Values of one row for the mapped fields, normalized.
     *
     * @param  list<string>  $row
     * @param  array<string, int|null>  $mapping
     * @param  array<string, int|null>  $levelMapping
     * @return array<string, mixed>
     */
    private function record(string $type, array $row, array $mapping, array $levelMapping): array
    {
        $record = [
            'name' => $this->collapseSpaces($this->cell($row, $mapping['name'] ?? null)),
            'email' => mb_strtolower($this->cell($row, $mapping['email'] ?? null)),
            'phone' => $this->collapseSpaces($this->cell($row, $mapping['phone'] ?? null)),
        ];

        if ($type === 'students') {
            $level = $this->cell($row, $mapping['level'] ?? null);
            $record['level'] = $level;
            $record['course_level_id'] = $level !== '' ? ($levelMapping[$level] ?? null) : null;
        }

        return $record;
    }

    /**
     * Same rules as creating or editing the person by hand (PersonRules).
     *
     * @param  array<string, mixed>  $record
     * @return list<array{field: string, code: string, message: string}>
     */
    private function validate(string $type, array $record, ?Model $existing): array
    {
        if ($type === 'students' && $record['level'] !== '' && $record['course_level_id'] === null) {
            $levelError = $this->error('level', 'level_unmapped', ['value' => $record['level']]);
        }

        $data = array_diff_key($record, ['level' => true]);
        $data['phone'] = $data['phone'] !== '' ? $data['phone'] : null;

        $rules = $type === 'students'
            ? PersonRules::student($existing?->id)
            : PersonRules::teacher($existing?->id);

        if (isset($levelError)) {
            unset($rules['course_level_id']);
        }

        $validator = Validator::make($data, $rules);
        $validator->passes(); // failed() only reports, it doesn't run the validation
        $errors = [];

        foreach ($validator->failed() as $field => $failedRules) {
            $rule = array_key_first($failedRules);
            $field = $field === 'course_level_id' ? 'level' : $field;
            $code = self::RULE_CODES[$rule] ?? 'invalid';

            $errors[] = $this->error($field, $code, $rule === 'Max' ? ['max' => $failedRules[$rule][0]] : []);
        }

        return isset($levelError) ? [...$errors, $levelError] : $errors;
    }

    /**
     * People of the business with one of the emails, by lowercase email.
     *
     * @param  list<string>  $emails
     * @return array<string, Model>
     */
    private function existingByEmail(string $type, array $emails): array
    {
        $emails = array_values(array_unique(array_filter($emails)));

        if ($emails === []) {
            return [];
        }

        $model = $type === 'students' ? Student::class : Teacher::class;

        return $model::query()
            ->whereIn(DB::raw('lower(email)'), $emails)
            ->get()
            ->keyBy(fn (Model $person) => mb_strtolower($person->email))
            ->all();
    }

    /**
     * An update keeps the phone when the file has none, instead of erasing it.
     *
     * @param  array<string, mixed>  $data
     */
    private function save(string $type, Model $model, array $data): void
    {
        $model->name = $data['name'];

        if (! $model->exists) {
            $model->email = $data['email'];
        }

        if ($data['phone'] !== '') {
            $model->phone = $data['phone'];
        }

        if ($type === 'students') {
            $model->course_level_id = $data['course_level_id'];
        }

        $model->save();
    }

    private function newModel(string $type): Model
    {
        return $type === 'students' ? new Student : new Teacher;
    }

    /**
     * @param  list<array<string, mixed>>  $analyzed
     * @return array<string, int>
     */
    private function summary(array $analyzed): array
    {
        $counts = array_count_values(array_column($analyzed, 'status'));

        return [
            self::STATUS_NEW => $counts[self::STATUS_NEW] ?? 0,
            self::STATUS_UPDATE => $counts[self::STATUS_UPDATE] ?? 0,
            self::STATUS_SKIP => $counts[self::STATUS_SKIP] ?? 0,
            self::STATUS_ERROR => $counts[self::STATUS_ERROR] ?? 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $replace
     * @return array{field: string, code: string, message: string}
     */
    private function error(string $field, string $code, array $replace = []): array
    {
        $message = Lang::has("import.errors.{$code}")
            ? __("import.errors.{$code}", $replace)
            : __(PersonRules::message($code, $field), $replace);

        return ['field' => $field, 'code' => $code, 'message' => $message];
    }

    /**
     * @param  list<string>  $normalizedHeaders
     * @param  list<string>  $synonyms
     * @param  list<int>  $used
     */
    private function findColumn(array $normalizedHeaders, array $synonyms, array $used): ?int
    {
        // Exact name first ("Email"), then a header that contains it as a word ("Email del alumno")
        foreach ([true, false] as $exact) {
            foreach ($normalizedHeaders as $i => $header) {
                if (in_array($i, $used, true)) {
                    continue;
                }

                foreach ($synonyms as $synonym) {
                    $matches = $exact
                        ? $header === $synonym
                        : preg_match('/\b'.preg_quote($synonym, '/').'\b/', $header) === 1;

                    if ($matches) {
                        return $i;
                    }
                }
            }
        }

        return null;
    }

    private function normalizeHeader(string $header): string
    {
        return $this->collapseSpaces(Str::lower(Str::ascii($header)));
    }

    /**
     * @param  list<string>  $row
     */
    private function cell(array $row, ?int $column): string
    {
        if ($column === null) {
            return '';
        }

        $value = $row[$column] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function collapseSpaces(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
