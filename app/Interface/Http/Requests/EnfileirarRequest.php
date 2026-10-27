<?php

namespace App\Interface\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnfileirarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'osId' => ['required', 'integer', 'min:1'],
        ];
    }
}
