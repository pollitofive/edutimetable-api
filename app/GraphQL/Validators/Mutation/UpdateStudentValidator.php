<?php

namespace App\GraphQL\Validators\Mutation;

use App\Validation\PersonRules;
use Nuwave\Lighthouse\Validation\Validator;

/**
 * On the field and not on UpdateStudentInput, because the rules need the id of the
 * student being updated (so its own email is not taken as a duplicate).
 */
class UpdateStudentValidator extends Validator
{
    public function rules(): array
    {
        return collect(PersonRules::student((int) $this->arg('id'), partial: true))
            ->mapWithKeys(fn (array $rules, string $field) => ["input.{$field}" => $rules])
            ->all();
    }

    public function messages(): array
    {
        return collect(PersonRules::messages())
            ->mapWithKeys(fn (string $message, string $key) => ["input.{$key}" => $message])
            ->all();
    }
}
