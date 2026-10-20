<?php

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
