<?php

namespace App\Interface\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mecanicoId' => ['required', 'integer', 'min:1'],
            'texto'      => ['required', 'string', 'max:2000'],
        ];
    }
}
