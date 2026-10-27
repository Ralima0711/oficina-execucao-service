<?php

namespace App\Interface\Http\Controllers;

use App\Application\Services\ExecucaoService;
use App\Interface\Http\Requests\MecanicoRequest;
use App\Interface\Http\Requests\NotaRequest;
use Illuminate\Http\JsonResponse;

/**
 * Erros de negócio (409, 404, 422) são convertidos em bootstrap/app.php.
 */
class ExecucaoController extends Controller
{
    public function __construct(private ExecucaoService $service) {}

    public function timeline(int $osId): JsonResponse
    {
        return response()->json($this->service->timeline($osId));
    }

    public function iniciarDiagnostico(MecanicoRequest $request, int $osId): JsonResponse
    {
        $evento = $this->service->iniciarDiagnostico(
            $osId,
            $request->integer('mecanicoId'),
            $request->input('observacao')
        );

        return response()->json($evento, 201);
    }

    public function nota(NotaRequest $request, int $osId): JsonResponse
    {
        $evento = $this->service->registrarNota(
            $osId,
            $request->integer('mecanicoId'),
            (string) $request->input('texto')
        );

        return response()->json($evento, 201);
    }

    public function finalizar(MecanicoRequest $request, int $osId): JsonResponse
    {
        $evento = $this->service->finalizar(
            $osId,
            $request->integer('mecanicoId'),
            $request->input('observacao')
        );

        return response()->json($evento);
    }

    public function tempoMedio(): JsonResponse
    {
        return response()->json($this->service->tempoMedioPorStatus());
    }
}
