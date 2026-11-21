<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Import\ImportFileException;
use App\Services\Import\PersonImportService;
use App\Services\Import\SpreadsheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Spreadsheet import of students and teachers ({type} = students|teachers):
 * parse the file → preview what would happen with each row → commit.
 */
class ImportController extends Controller
{
    /** Upload limit, in kilobytes (validation "max" of files). */
    private const MAX_FILE_KB = 2048;

    public function __construct(
        private readonly PersonImportService $service,
        private readonly SpreadsheetReader $reader,
    ) {}

    public function parse(Request $request, string $type): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,csv', 'max:'.self::MAX_FILE_KB],
        ]);

        $file = $request->file('file');

        try {
            $sheet = $this->reader->read($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (ImportFileException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->key], 422);
        }

        $mapping = $this->service->suggestMapping($type, $sheet['headers']);

        return response()->json([
            ...$sheet,
            'fields' => $this->service->fields($type),
            'suggested_mapping' => $mapping,
            'level_values' => $this->service->distinctValues($sheet['rows'], $mapping['level'] ?? null),
        ]);
    }

    public function preview(Request $request, string $type): JsonResponse
    {
        return response()->json($this->service->preview($type, ...$this->importInput($request, $type)));
    }

    public function commit(Request $request, string $type): JsonResponse
    {
        return response()->json($this->service->commit($type, ...$this->importInput($request, $type)));
    }

    public function template(string $type): BinaryFileResponse
    {
        $fields = $this->service->fields($type);
        $path = tempnam(sys_get_temp_dir(), 'import-template');

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_map(fn ($f) => __("import.template.headers.{$f}"), $fields)));
        $writer->addRow(Row::fromValues(array_map(fn ($f) => __("import.template.example.{$f}"), $fields)));
        $writer->close();

        return response()
            ->download($path, __("import.template.filename_{$type}"), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Validated preview/commit body, as the service's arguments.
     *
     * @return array{rows: list<list<string>>, mapping: array<string, int|null>, levelMapping: array<string, int|null>, duplicates: string, rowNumbers: list<int>}
     */
    private function importInput(Request $request, string $type): array
    {
        $validated = $request->validate([
            'rows' => ['required', 'array', 'max:'.SpreadsheetReader::MAX_ROWS],
            'rows.*' => ['array', 'max:'.SpreadsheetReader::MAX_COLUMNS],
            'row_numbers' => ['nullable', 'array', 'size:'.count((array) $request->input('rows', []))],
            'row_numbers.*' => ['integer', 'min:1'],
            'mapping' => ['required', 'array'],
            'mapping.name' => ['required', 'integer', 'min:0'],
            'mapping.email' => ['required', 'integer', 'min:0'],
            'mapping.phone' => ['nullable', 'integer', 'min:0'],
            'mapping.level' => [Rule::requiredIf($type === 'students'), 'nullable', 'integer', 'min:0'],
            'level_mapping' => ['nullable', 'array'],
            'level_mapping.*.value' => ['required', 'string'],
            'level_mapping.*.course_level_id' => ['nullable', 'integer'],
            'duplicates' => ['required', Rule::in([PersonImportService::DUPLICATES_SKIP, PersonImportService::DUPLICATES_UPDATE])],
        ]);

        $levelMapping = collect($validated['level_mapping'] ?? [])
            ->mapWithKeys(fn (array $item) => [$item['value'] => $item['course_level_id'] ?? null])
            ->all();

        return [
            'rows' => array_values($validated['rows']),
            'mapping' => array_intersect_key($validated['mapping'], array_flip($this->service->fields($type))),
            'levelMapping' => $levelMapping,
            'duplicates' => $validated['duplicates'],
            'rowNumbers' => array_map('intval', array_values($validated['row_numbers'] ?? [])),
        ];
    }
}
