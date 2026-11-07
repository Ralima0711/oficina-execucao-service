<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Saúde na raiz (/health)
|--------------------------------------------------------------------------
| É o caminho que a readiness e a liveness dos manifestos Kubernetes chamam
| (oficina-infra-fase4/k8s). /api/health continua existindo para quem já usa.
*/

Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
    ]);
});
