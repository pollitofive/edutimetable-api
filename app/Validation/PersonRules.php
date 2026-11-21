<?php

namespace App\Validation;

use App\Services\CurrentBusiness;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation rules for students and teachers, shared by the GraphQL create/update
 * inputs and the spreadsheet import, so both ways of loading people validate the same.
 */
final class PersonRules
{
    public const NAME_MAX = 255;

    public const EMAIL_MAX = 255;

    public const PHONE_MAX = 50;

    /**
     * @param  int|null  $ignoreId  Student being updated, so its own email is not a duplicate.
     * @param  bool  $partial  Updates: only the fields present are validated.
     * @return array<string, array<int, mixed>>
     */
    public static function student(?int $ignoreId = null, bool $partial = false): array
    {
        return self::person('students', $ignoreId, $partial) + [
            'course_level_id' => self::field($partial, ['integer', 'exists_in_business:course_levels']),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function teacher(?int $ignoreId = null, bool $partial = false): array
    {
        return self::person('teachers', $ignoreId, $partial);
    }

    /**
     * Messages in the request's language (lang/<locale>/person.php), keyed as the rules.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.required' => self::message('required', 'name'),
            'name.max' => self::message('max', 'name'),
            'email.required' => self::message('required', 'email'),
            'email.email' => self::message('email', 'email'),
            'email.max' => self::message('max', 'email'),
            'email.unique' => self::message('taken', 'email'),
            'phone.max' => self::message('max', 'phone'),
            'course_level_id.required' => self::message('required', 'level'),
            'course_level_id.integer' => self::message('invalid', 'level'),
            'course_level_id.exists_in_business' => self::message('invalid', 'level'),
        ];
    }

    /**
     * :max is left for the validator to fill in.
     */
    public static function message(string $code, string $field): string
    {
        return __("person.errors.{$code}", ['field' => __("person.fields.{$field}")]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private static function person(string $table, ?int $ignoreId, bool $partial): array
    {
        return [
            'name' => self::field($partial, ['string', 'max:'.self::NAME_MAX]),
            'email' => self::field($partial, ['string', 'email', 'max:'.self::EMAIL_MAX, self::uniqueEmail($table, $ignoreId)]),
            'phone' => ['nullable', 'string', 'max:'.self::PHONE_MAX],
        ];
    }

    /**
     * Same as the (business_id, email) unique index, but as a validation error instead of
     * a database exception.
     */
    private static function uniqueEmail(string $table, ?int $ignoreId): Unique
    {
        return Rule::unique($table, 'email')
            ->where('business_id', app(CurrentBusiness::class)->id())
            ->ignore($ignoreId);
    }

    /**
     * @param  array<int, mixed>  $rules
     * @return array<int, mixed>
     */
    private static function field(bool $partial, array $rules): array
    {
        return [$partial ? 'sometimes' : 'required', ...$rules];
    }
}
