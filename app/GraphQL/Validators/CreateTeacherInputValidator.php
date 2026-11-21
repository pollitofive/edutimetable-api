<?php

namespace App\GraphQL\Validators;

use App\Validation\PersonRules;
use Nuwave\Lighthouse\Validation\Validator;

class CreateTeacherInputValidator extends Validator
{
    public function rules(): array
    {
        return PersonRules::teacher();
    }

    public function messages(): array
    {
        return PersonRules::messages();
    }
}
