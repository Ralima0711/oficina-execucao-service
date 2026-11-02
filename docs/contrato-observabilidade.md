# Contrato de observabilidade — OS, Billing e Execução

Objetivo: os 3 serviços instrumentam **igual**, para que um trace único atravesse REST e mensageria e os logs de qualquer serviço apareçam no mesmo trace. Implementação de referência: este repositório (`oficina-execucao-service`).

## 1. Regras

| # | Regra |
|---|---|
| 1 | **Propagação:** W3C Trace Context. Toda mensagem publicada leva o header `traceparent` (e `tracestate`, se existir). Toda chamada REST entre serviços também. |
| 2 | **Header de mensagem:** chave em minúsculas, valor string. RabbitMQ: `application_headers`. SQS: message attributes (tipo `String`). Kafka: headers. O valor é copiado como vem, sem reescrever. |
| 3 | **Publicar** abre um span `PRODUCER` chamado `<destino> publish`; o `traceparent` injetado é o desse span. |
| 4 | **Consumir** abre um span `CONSUMER` chamado `<origem> process`, filho do `traceparent` recebido. Todo span e log do caso de uso rodam dentro dele. |
| 5 | **Header ausente ou inválido** não é erro: o consumer abre um trace novo (e nunca herda o span ambiente do worker). |
| 6 | **Request HTTP** abre um span `SERVER` filho do `traceparent` recebido; sem header, abre trace novo. |
| 7 | **Falha** no caso de uso marca o span com `STATUS_ERROR`, registra a exceção e repropaga (para retry/DLQ e para a compensação da saga). |
| 8 | **Flush:** HTTP, no `terminate` do middleware. Worker, ao fim de cada mensagem (processo longo: o shutdown nunca chega). |
| 9 | **Sem endpoint, sem problema:** se `OTEL_EXPORTER_OTLP_ENDPOINT` estiver vazio o provider vira no-op. |

## 2. Variáveis de ambiente

| Variável | Valor |
|---|---|
| `OTEL_SERVICE_NAME` | `oficina-os-service`, `oficina-billing-service` ou `oficina-execucao-service` (um por serviço) |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | ex.: `https://otlp.nr-data.net:4318` |
| `OTEL_EXPORTER_OTLP_HEADERS` | ex.: `api-key=<LICENSE_KEY>`. Vem de Secret, nunca do ConfigMap |
| `LOG_CHANNEL` | `stdout_json` |

Mesma chave do New Relic nos 3 serviços; o que separa um serviço do outro é o `OTEL_SERVICE_NAME`.

## 3. Logs JSON

Canal `stdout_json` (Monolog `JsonFormatter` no stdout). Campos obrigatórios em **todo** log, dentro de `context`, mesmo formato da Fase 3:

| Campo | Origem |
|---|---|
| `service.name` | `OTEL_SERVICE_NAME` |
| `trace.id` | span ativo (ou o span de request, via middleware) |
| `span.id` | idem |
| `request.id` | `X-Request-Id` (só em logs de request HTTP) |

Campos de negócio usam o prefixo `os.` (`os.id`, `os.status`) para o mesmo filtro funcionar nos 3 serviços.

Exemplo real, gerado por este repo ao consumir uma mensagem com `traceparent` conhecido:

```json
{"message":"dentro_do_consumer","context":{"os.id":42,"service.name":"oficina-execucao-service","trace.id":"0af7651916cd43dd8448eb211c80319c","span.id":"ec2fb28c2d43a1d8"},"level":200,"level_name":"INFO","channel":"production","datetime":"2026-10-27T00:07:34.487989+00:00","extra":{}}
```

## 4. Como adotar (PHP/Laravel)

Copie estes arquivos, sem alterar, e use os helpers. O namespace `App\Infrastructure\...` / `App\Interface\...` é o mesmo da Fase 3.

| Arquivo | Papel |
|---|---|
| `app/Infrastructure/Observability/OpenTelemetryProvider.php` | provider OTLP/HTTP, no-op sem endpoint, `nomeServico()`, `flush()` |
| `app/Infrastructure/Observability/Tracing.php` | helper de spans (`iniciar` aceita `SpanKind` e pai explícito; `executar()` envolve uma operação num span; `traceIdAtual()` devolve o `trace.id` para gravar em documentos) |
| `app/Infrastructure/Observability/ContextoObservabilidadeProcessor.php` | põe `service.name`, `trace.id`, `span.id` em todo log |
| `app/Infrastructure/Messaging/TraceContextCarrier.php` | `inject()` / `extract()` do `traceparent` em array de headers |
| `app/Infrastructure/Messaging/MessageTracing.php` | `publicar()` e `consumir()` (PRODUCER / CONSUMER) |
| `app/Interface/Http/Middleware/CorrelacaoRequisicaoMiddleware.php` | span SERVER filho do `traceparent`, `X-Request-Id`, logs de acesso |
| `config/logging.php` (canal `stdout_json`) | registra o processor no canal |
| `bootstrap/app.php` | `$middleware->api(prepend: [CorrelacaoRequisicaoMiddleware::class])` |
| `tests/Concerns/UsaTracerEmMemoria.php` + `tests/**/*Trace*Test.php` | testes do contrato (rodam sem rede nem coletor) |

Dependências: `open-telemetry/sdk`, `open-telemetry/exporter-otlp`, `php-http/guzzle7-adapter`.

Uso no código:

```php
// publicar
MessageTracing::publicar('execucao.finalizada', function (array $headers) use ($payload) {
    $broker->publish($payload, $headers);   // $headers já tem o traceparent
}, ['os.id' => $osId]);

// consumir
MessageTracing::consumir('execucao.enfileirar', $headers, function () use ($payload) {
    $casoDeUso->executar($payload);
}, ['os.id' => $payload['osId']]);

// chamada REST para outro serviço
$http->withHeaders(TraceContextCarrier::inject())->post($url, $dados);
```

## 5. Se o serviço não for PHP

O que vale é o contrato da seção 1 e os campos da seção 3. Em qualquer SDK do OpenTelemetry: usar o propagador `tracecontext`, criar o span `PRODUCER`/`CONSUMER` com os nomes acima e injetar/extrair os headers da mensagem.

## 6. Como verificar (checklist de PR)

1. Abrir uma OS e conferir o **mesmo `trace.id`** nos logs dos 3 serviços.
2. No New Relic (ou Jaeger), ver **um trace só** com spans dos 3 `service.name`, em ordem `publish` → `process`.
3. Provocar uma falha (ex.: pagamento recusado) e ver a compensação **no mesmo trace**, com `STATUS_ERROR` no span que falhou.
4. Os testes de propagação do serviço passam: `TraceContextCarrierTest`, `MessageTracingTest`, `PropagacaoTraceHttpTest`.

## 7. O que está e o que não está verificado

Verificado neste repo (18 testes, `php artisan test` / `vendor/bin/phpunit`):

- `extract` de um `traceparent` conhecido faz o span herdar `trace_id` e `parent_span_id`;
- publicar → consumir fica no mesmo trace, com o span PRODUCER como pai do CONSUMER;
- span de caso de uso dentro do consumer é filho do CONSUMER;
- header ausente/inválido abre trace novo e não derruba a requisição ou a mensagem;
- falha no caso de uso gera `STATUS_ERROR` e repropaga;
- request HTTP com `traceparent` continua o trace;
- log JSON com `service.name`, `trace.id`, `span.id`.

Ainda **não** verificado, depende de infra do líder: exportação real para o New Relic/coletor e mensagens passando por um broker de verdade. Os testes usam `InMemoryExporter` e arrays de headers.
