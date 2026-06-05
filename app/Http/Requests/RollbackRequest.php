<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RollbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'table' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/'],
        ];
    }
}
