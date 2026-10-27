<?php

use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Domain\Execucao\Exceptions\NaoEncontradoException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
    )
    ->withCommands([
        __DIR__.'/../app/Interface/Console',
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Correlação de requisições + span de tracing em todas as rotas da API.
        $middleware->api(prepend: [
            \App\Interface\Http\Middleware\CorrelacaoRequisicaoMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Erros de negócio não são falhas do serviço: não vão para o log de erro.
        $exceptions->dontReport([
            ConflitoException::class,
            NaoEncontradoException::class,
            \DomainException::class,
        ]);

        // Os mais específicos primeiro: ConflitoException e NaoEncontradoException
        // também são \DomainException.
        $exceptions->render(fn (ConflitoException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (NaoEncontradoException $e) => response()->json(['message' => $e->getMessage()], 404));
        $exceptions->render(fn (\DomainException $e) => response()->json(['message' => $e->getMessage()], 422));

        // Garante respostas JSON para todas as rotas da API, mesmo sem Accept header.
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
