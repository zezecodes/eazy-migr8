<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddColumnsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'table' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/', 'not_in:users,migrations,password_resets,password_reset_tokens,sessions,personal_access_tokens'],
            'columns' => 'required|array|min:1',
            'columns.*.name' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/'],
            'columns.*.type' => 'required|string|in:string,text,integer,boolean,date,datetime,float,double,foreignId,enum',
            'columns.*.modifiers' => 'sometimes|array',
            'columns.*.modifiers.nullable' => 'boolean',
            'columns.*.modifiers.unique' => 'boolean',
            'columns.*.modifiers.unsigned' => 'boolean',
            'columns.*.modifiers.default' => 'nullable',
            'columns.*.modifiers.comment' => 'nullable|string|max:255',
            'columns.*.modifiers.index' => 'boolean',
            'columns.*.modifiers.primary' => 'boolean',
            'columns.*.modifiers.length' => 'nullable|integer|min:1|max:16383',
            'columns.*.modifiers.values' => 'nullable|array|min:1',
            'columns.*.modifiers.values.*' => 'required|string|max:255',
            'columns.*.modifiers.constrained' => 'nullable',
            'columns.*.modifiers.onDelete' => 'nullable|string|in:cascade,restrict,set null,no action',
            'columns.*.modifiers.onUpdate' => 'nullable|string|in:cascade,restrict,set null,no action',
        ];
    }

    public function messages(): array
    {
        return [
            'table.not_in' => 'This table name is protected and cannot be used.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $columns = $this->input('columns', []);
            $names = array_column($columns, 'name');
            if (count($names) !== count(array_unique($names))) {
                $validator->errors()->add('columns', 'Duplicate column names are not allowed.');
            }
        });
    }
}
