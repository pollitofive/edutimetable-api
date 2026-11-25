<?php

use App\Models\Business;
use App\Models\CourseLevel;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Import\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenancy = setupTenancy();
    $this->user = $tenancy->user;
    $this->business = $tenancy->business;
    Sanctum::actingAs($this->user);
    $this->level = CourseLevel::factory()->create(['name' => 'Inicial']);
});

/**
 * @param  list<list<mixed>>  $rows  header row included
 */
function xlsxUpload(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'test-import').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return new UploadedFile($path, 'alumnos.xlsx', test: true);
}

/**
 * Body of preview/commit for students with the usual columns: nombre, email, teléfono, nivel.
 *
 * @param  list<list<string>>  $rows
 */
function studentImport(array $rows, array $levelMapping, string $duplicates = 'skip'): array
{
    return [
        'rows' => $rows,
        'mapping' => ['name' => 0, 'email' => 1, 'phone' => 2, 'level' => 3],
        'level_mapping' => collect($levelMapping)->map(fn ($id, $value) => ['value' => (string) $value, 'course_level_id' => $id])->values()->all(),
        'duplicates' => $duplicates,
    ];
}

describe('parse', function () {
    it('reads a csv with ";" in Latin-1, as Excel in Spanish saves it', function () {
        $content = mb_convert_encoding("Nombre;Correo electrónico;Teléfono;Nivel\nMaría Peña;maria@example.com;11 4444-5555;Inicial\n", 'Windows-1252', 'UTF-8');

        $response = $this->post('/api/imports/students/parse', [
            'file' => UploadedFile::fake()->createWithContent('alumnos.csv', $content),
        ])->assertOk();

        expect($response->json('headers'))->toBe(['Nombre', 'Correo electrónico', 'Teléfono', 'Nivel'])
            ->and($response->json('rows'))->toBe([['María Peña', 'maria@example.com', '11 4444-5555', 'Inicial']])
            ->and($response->json('suggested_mapping'))->toBe(['name' => 0, 'email' => 1, 'phone' => 2, 'level' => 3]);
    });

    it('reads a csv in UTF-8 with BOM and ","', function () {
        $content = "\xEF\xBB\xBFnombre,email\n\nJosé,jose@example.com\n";

        $this->post('/api/imports/teachers/parse', [
            'file' => UploadedFile::fake()->createWithContent('profes.csv', $content),
        ])->assertOk()
            ->assertJsonPath('headers', ['nombre', 'email'])
            ->assertJsonPath('rows.0', ['José', 'jose@example.com'])
            ->assertJsonPath('row_numbers', [3]);
    });

    it('reads the first sheet of an xlsx, with numeric phones as text', function () {
        $file = xlsxUpload([
            ['Alumno', 'Mail', 'Celular', 'Curso'],
            ['Ana', 'ana@example.com', 1155667788, 'Inicial'],
            ['', '', '', ''],
            ['Beto', 'beto@example.com', null, 'Avanzado'],
        ]);

        $this->post('/api/imports/students/parse', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('rows', [
                ['Ana', 'ana@example.com', '1155667788', 'Inicial'],
                ['Beto', 'beto@example.com', '', 'Avanzado'],
            ])
            ->assertJsonPath('row_numbers', [2, 4])
            ->assertJsonPath('suggested_mapping', ['name' => 0, 'email' => 1, 'phone' => 2, 'level' => 3])
            ->assertJsonPath('level_values', [
                ['value' => 'Avanzado', 'count' => 1],
                ['value' => 'Inicial', 'count' => 1],
            ]);
    });

    it('suggests the mapping by header names, in any order', function () {
        $content = "Teléfono celular;Apellido y nombre;Nivel del alumno;E-mail\n1;Ana;A1;a@b.com\n";

        $this->post('/api/imports/students/parse', [
            'file' => UploadedFile::fake()->createWithContent('a.csv', $content),
        ])->assertOk()
            ->assertJsonPath('suggested_mapping', ['name' => 1, 'email' => 3, 'phone' => 0, 'level' => 2]);
    });

    it('leaves a field without a column when no header matches', function () {
        $this->post('/api/imports/teachers/parse', [
            'file' => UploadedFile::fake()->createWithContent('a.csv', "Nombre;Dirección\nAna;Calle 1\n"),
        ])->assertOk()
            ->assertJsonPath('suggested_mapping', ['name' => 0, 'email' => null, 'phone' => null]);
    });

    it('rejects a file with more rows than the limit', function () {
        $content = "nombre;email\n".str_repeat("Ana;a@b.com\n", SpreadsheetReader::MAX_ROWS + 1);

        $this->post('/api/imports/students/parse', [
            'file' => UploadedFile::fake()->createWithContent('a.csv', $content),
        ])->assertStatus(422)->assertJsonPath('code', 'too_many_rows');
    });

    it('rejects a file with only the header', function () {
        $this->post('/api/imports/students/parse', [
            'file' => UploadedFile::fake()->createWithContent('a.csv', "nombre;email\n"),
        ])->assertStatus(422)->assertJsonPath('code', 'empty');
    });

    it('rejects a file that is not a valid xlsx', function () {
        $this->post('/api/imports/students/parse', [
            'file' => UploadedFile::fake()->createWithContent('a.xlsx', 'esto no es un zip'),
        ])->assertStatus(422)->assertJsonPath('code', 'unreadable');
    });

    it('rejects other file types', function () {
        $this->postJson('/api/imports/students/parse', [
            'file' => UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    });

    it('only accepts students and teachers as type', function () {
        $this->post('/api/imports/courses/parse')->assertNotFound();
    });
});

describe('preview', function () {
    it('gives the status of each row and the totals', function () {
        Student::factory()->create(['email' => 'existe@example.com']);

        $response = $this->postJson('/api/imports/students/preview', studentImport([
            ['Ana', 'ANA@example.com', ' 11   4444 ', 'Inicial'],
            ['Existe', 'existe@example.com', '', 'Inicial'],
        ], ['Inicial' => $this->level->id]))->assertOk();

        expect($response->json('summary'))->toBe(['new' => 1, 'update' => 0, 'skip' => 1, 'error' => 0])
            ->and($response->json('rows.0'))->toMatchArray([
                'row' => 2,
                'status' => 'new',
                'data' => ['name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '11 4444', 'level' => 'Inicial', 'course_level_id' => $this->level->id],
                'errors' => [],
            ])
            ->and($response->json('rows.1.status'))->toBe('skip');
    });

    it('rejects rows with a missing or invalid email', function (string $email, string $code) {
        $this->postJson('/api/imports/students/preview', studentImport(
            [['Ana', $email, '', 'Inicial']],
            ['Inicial' => $this->level->id],
        ))->assertOk()
            ->assertJsonPath('rows.0.status', 'error')
            ->assertJsonPath('rows.0.errors.0.field', 'email')
            ->assertJsonPath('rows.0.errors.0.code', $code);
    })->with([
        'missing' => ['', 'required'],
        'invalid' => ['ana-sin-arroba', 'email'],
        'crlf' => ["ana@example.com\r\nBcc: x@example.com", 'email'],
    ]);

    it('rejects rows whose level has no level assigned', function () {
        $this->postJson('/api/imports/students/preview', studentImport(
            [['Ana', 'ana@example.com', '', 'Nivel raro']],
            ['Inicial' => $this->level->id],
        ))->assertOk()
            ->assertJsonPath('rows.0.status', 'error')
            ->assertJsonPath('rows.0.errors.0.code', 'level_unmapped');
    });

    it('rejects rows without level', function () {
        $this->postJson('/api/imports/students/preview', studentImport(
            [['Ana', 'ana@example.com', '', '']],
            [],
        ))->assertOk()
            ->assertJsonPath('rows.0.errors.0.field', 'level')
            ->assertJsonPath('rows.0.errors.0.code', 'required');
    });

    it('returns the error messages in the language of the request', function () {
        $this->postJson('/api/imports/students/preview', studentImport(
            [['Ana', '', '', 'Inicial']],
            ['Inicial' => $this->level->id],
        ), ['Accept-Language' => 'es'])
            ->assertJsonPath('rows.0.errors.0.message', 'Falta el email.');
    });

    it('marks an email repeated in the file, pointing to its first row', function () {
        $this->postJson('/api/imports/students/preview', studentImport([
            ['Ana', 'ana@example.com', '', 'Inicial'],
            ['Ana otra vez', 'Ana@Example.com', '', 'Inicial'],
        ], ['Inicial' => $this->level->id]))->assertOk()
            ->assertJsonPath('rows.0.status', 'new')
            ->assertJsonPath('rows.1.status', 'error')
            ->assertJsonPath('rows.1.errors.0.code', 'duplicate_in_file')
            ->assertJsonPath('rows.1.errors.0.message', 'The email is repeated in the file (row 2).');
    });

    it('marks existing emails as update in update mode', function () {
        $student = Student::factory()->create(['email' => 'existe@example.com']);

        $this->postJson('/api/imports/students/preview', studentImport(
            [['Nuevo nombre', 'existe@example.com', '', 'Inicial']],
            ['Inicial' => $this->level->id],
            'update',
        ))->assertOk()
            ->assertJsonPath('rows.0.status', 'update')
            ->assertJsonPath('rows.0.existing_id', $student->id);
    });

    it('does not take people of another business as duplicates', function () {
        $other = Business::factory()->create();
        $otherLevel = CourseLevel::factory()->create(['business_id' => $other->id]);
        Student::factory()->create(['email' => 'ana@example.com', 'business_id' => $other->id, 'course_level_id' => $otherLevel->id]);

        $this->postJson('/api/imports/students/preview', studentImport(
            [['Ana', 'ana@example.com', '', 'Inicial']],
            ['Inicial' => $this->level->id],
        ))->assertOk()->assertJsonPath('rows.0.status', 'new');
    });

    it('rejects a level of another business', function () {
        $otherLevel = CourseLevel::factory()->create(['business_id' => Business::factory()->create()->id]);

        $this->postJson('/api/imports/students/preview', studentImport(
            [['Ana', 'ana@example.com', '', 'Inicial']],
            ['Inicial' => $otherLevel->id],
        ))->assertOk()
            ->assertJsonPath('rows.0.status', 'error')
            ->assertJsonPath('rows.0.errors.0.field', 'level')
            ->assertJsonPath('rows.0.errors.0.code', 'invalid');
    });

    it('requires the level column for students but not for teachers', function () {
        $body = ['rows' => [['Ana', 'a@b.com']], 'mapping' => ['name' => 0, 'email' => 1], 'duplicates' => 'skip'];

        $this->postJson('/api/imports/students/preview', $body)->assertStatus(422)->assertJsonValidationErrors('mapping.level');
        $this->postJson('/api/imports/teachers/preview', $body)->assertOk()->assertJsonPath('rows.0.status', 'new');
    });

    it('reports the spreadsheet row numbers sent by parse', function () {
        $body = studentImport([
            ['Ana', 'ana@example.com', '', 'Inicial'],
            ['Ana', 'ana@example.com', '', 'Inicial'],
        ], ['Inicial' => $this->level->id]);

        $this->postJson('/api/imports/students/preview', [...$body, 'row_numbers' => [3, 7]])
            ->assertOk()
            ->assertJsonPath('rows.0.row', 3)
            ->assertJsonPath('rows.1.row', 7)
            ->assertJsonPath('rows.1.errors.0.message', 'The email is repeated in the file (row 3).');
    });

    it('requires one row number per row', function () {
        $body = studentImport([['Ana', 'ana@example.com', '', 'Inicial']], ['Inicial' => $this->level->id]);

        $this->postJson('/api/imports/students/preview', [...$body, 'row_numbers' => [2, 3]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('row_numbers');
    });

    it('rejects more rows than the limit', function () {
        $rows = array_fill(0, SpreadsheetReader::MAX_ROWS + 1, ['Ana', 'a@b.com', '', 'Inicial']);

        $this->postJson('/api/imports/students/preview', studentImport($rows, []))
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows');
    });
});

describe('commit', function () {
    it('creates the new rows and leaves out the ones with errors', function () {
        $this->postJson('/api/imports/students/commit', studentImport([
            ['Ana', 'ana@example.com', '11 4444-5555', 'Inicial'],
            ['Sin email', '', '', 'Inicial'],
        ], ['Inicial' => $this->level->id]))->assertOk()
            ->assertExactJson(['created' => 1, 'updated' => 0, 'skipped' => 0, 'rejected' => 1]);

        $ana = Student::where('email', 'ana@example.com')->first();

        expect(Student::count())->toBe(1)
            ->and($ana->name)->toBe('Ana')
            ->and($ana->phone)->toBe('11 4444-5555')
            ->and($ana->course_level_id)->toBe($this->level->id)
            ->and($ana->business_id)->toBe($this->business->id);
    });

    it('skips existing emails in skip mode', function () {
        $student = Student::factory()->create(['name' => 'Original', 'email' => 'existe@example.com']);

        $this->postJson('/api/imports/students/commit', studentImport(
            [['Cambiado', 'existe@example.com', '', 'Inicial']],
            ['Inicial' => $this->level->id],
        ))->assertOk()->assertJsonPath('skipped', 1);

        expect($student->fresh()->name)->toBe('Original');
    });

    it('updates existing emails in update mode, keeping the phone when the file has none', function () {
        $student = Student::factory()->create(['name' => 'Original', 'email' => 'Existe@example.com', 'phone' => '1234']);

        $this->postJson('/api/imports/students/commit', studentImport(
            [['Cambiado', 'existe@example.com', '', 'Inicial']],
            ['Inicial' => $this->level->id],
            'update',
        ))->assertOk()->assertJsonPath('updated', 1);

        $student->refresh();

        expect($student->name)->toBe('Cambiado')
            ->and($student->email)->toBe('Existe@example.com')
            ->and($student->phone)->toBe('1234')
            ->and($student->course_level_id)->toBe($this->level->id)
            ->and(Student::count())->toBe(1);
    });

    it('imports teachers', function () {
        Teacher::factory()->create(['email' => 'existe@example.com']);

        $this->postJson('/api/imports/teachers/commit', [
            'rows' => [['Carla', 'carla@example.com', '11 2222'], ['Existe', 'existe@example.com', '']],
            'mapping' => ['name' => 0, 'email' => 1, 'phone' => 2],
            'duplicates' => 'skip',
        ])->assertOk()
            ->assertExactJson(['created' => 1, 'updated' => 0, 'skipped' => 1, 'rejected' => 0]);

        expect(Teacher::where('email', 'carla@example.com')->value('phone'))->toBe('11 2222');
    });

    it('rolls back every row when one fails to save', function () {
        Student::creating(function (Student $student) {
            if ($student->name === 'Explota') {
                throw new RuntimeException('falla al guardar');
            }
        });

        $this->withoutExceptionHandling();

        expect(fn () => $this->postJson('/api/imports/students/commit', studentImport([
            ['Ana', 'ana@example.com', '', 'Inicial'],
            ['Explota', 'explota@example.com', '', 'Inicial'],
        ], ['Inicial' => $this->level->id])))->toThrow(RuntimeException::class);

        expect(Student::count())->toBe(0);
    });
});

it('downloads a template with the expected headers', function (string $type, array $headers) {
    $response = $this->get("/api/imports/{$type}/template", ['Accept-Language' => 'es'])->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
    copy($response->baseResponse->getFile()->getPathname(), $path);
    $sheet = app(SpreadsheetReader::class)->read($path, 'xlsx');

    expect($sheet['headers'])->toBe($headers)
        ->and($sheet['rows'])->toHaveCount(1);
})->with([
    'students' => ['students', ['Nombre', 'Email', 'Teléfono', 'Nivel']],
    'teachers' => ['teachers', ['Nombre', 'Email', 'Teléfono']],
]);
