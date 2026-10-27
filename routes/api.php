<?php

use App\Interface\Http\Controllers\ExecucaoController;
use App\Interface\Http\Controllers\FilaExecucaoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Execução e Produção
|--------------------------------------------------------------------------
*/

// Checagem de saude (pública)
Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
    ]);
});

Route::prefix('execucao')->group(function () {

    // Fila de execução (Redis)
    Route::get('fila', [FilaExecucaoController::class, 'index']);
    Route::post('fila', [FilaExecucaoController::class, 'store']);
    Route::post('fila/proxima', [FilaExecucaoController::class, 'proxima']);
    Route::delete('fila/{osId}', [FilaExecucaoController::class, 'destroy'])->whereNumber('osId');

    // Timeline de diagnóstico e reparo (MongoDB)
    Route::get('os/{osId}/timeline', [ExecucaoController::class, 'timeline'])->whereNumber('osId');
    Route::post('os/{osId}/diagnostico', [ExecucaoController::class, 'iniciarDiagnostico'])->whereNumber('osId');
    Route::post('os/{osId}/notas', [ExecucaoController::class, 'nota'])->whereNumber('osId');
    Route::post('os/{osId}/finalizar', [ExecucaoController::class, 'finalizar'])->whereNumber('osId');

    Route::get('metricas/tempo-medio', [ExecucaoController::class, 'tempoMedio']);
});
