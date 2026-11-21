<?php

namespace App\GraphQL\Validators;

use App\Validation\PersonRules;
use Nuwave\Lighthouse\Validation\Validator;

class CreateStudentInputValidator extends Validator
{
    public function rules(): array
    {
        return PersonRules::student();
    }

    public function messages(): array
    {
        return PersonRules::messages();
    }
}
