<?php

namespace App\Interface\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * O mecânico informa que o reparo não pode ser concluído. O motivo é obrigatório:
 * ele vai no execucao.falhou e é o que o orquestrador registra ao compensar.
 */
class FalhaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mecanicoId' => ['required', 'uuid'],
            'motivo'     => ['required', 'string', 'max:1000'],
        ];
    }
}
