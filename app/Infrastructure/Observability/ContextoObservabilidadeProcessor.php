<?php

namespace App\Infrastructure\Observability;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;

class ContextoObservabilidadeProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $contexto = $record->context;

        $contexto['service.name'] ??= OpenTelemetryProvider::nomeServico();

        $span = Span::getCurrent()->getContext();
        if ($span->isValid()) {
            $contexto['trace.id'] ??= $span->getTraceId();
            $contexto['span.id'] ??= $span->getSpanId();
        }

        return $record->with(context: $contexto);
    }
}
