<?php

namespace App\Interface\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ações do mecânico sobre uma OS (puxar da fila, iniciar diagnóstico, finalizar).
 *
 * Enquanto a autenticação entre serviços não estiver definida, o mecânico vem
 * no corpo. Quando houver JWT, `mecanicoId` passa a vir do token.
 */
class MecanicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mecanicoId' => ['required', 'integer', 'min:1'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
