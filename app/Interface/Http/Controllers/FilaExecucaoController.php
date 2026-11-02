<?php

namespace App\Interface\Http\Controllers;

use App\Application\Services\FilaExecucaoService;
use App\Domain\Execucao\Exceptions\NaoEncontradoException;
use App\Interface\Http\Requests\EnfileirarRequest;
use App\Interface\Http\Requests\MecanicoRequest;
use Illuminate\Http\JsonResponse;

/**
 * Erros de negócio (409, 404, 422) são convertidos em bootstrap/app.php.
 */
class FilaExecucaoController extends Controller
{
    public function __construct(private FilaExecucaoService $service) {}

    public function index(): JsonResponse
    {
        return response()->json($this->service->listar());
    }

    public function store(EnfileirarRequest $request): JsonResponse
    {
        return response()->json($this->service->enfileirar($request->integer('osId')), 201);
    }

    public function proxima(MecanicoRequest $request): JsonResponse
    {
        $evento = $this->service->proxima($request->integer('mecanicoId'));

        if ($evento === null) {
            throw new NaoEncontradoException('A fila de execução está vazia.');
        }

        return response()->json($evento);
    }

    public function destroy(int $osId): JsonResponse
    {
        $this->service->remover($osId);

        return response()->json(null, 204);
    }
}
