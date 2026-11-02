# Contrato de observabilidade — OS, Billing e Execução

Objetivo: os 3 serviços instrumentam **igual**, para que um trace único atravesse REST e mensageria e os logs de qualquer serviço apareçam no mesmo trace. Implementação de referência: este repositório (`oficina-execucao-service`).

## 1. Regras

| # | Regra |
|---|---|
| 1 | **Propagação:** W3C Trace Context. Toda mensagem publicada leva o `traceparent` (e `tracestate`, se existir). Toda chamada REST entre serviços também. |
| 2 | **Onde vai na mensagem:** no `header.traceparent` do envelope (contrato de eventos v1.0, Parte 2) **e** nos `application_headers` do AMQP (o `asyncapi.yaml` descreve o envelope como headers de mensagem). Quem publica escreve nos dois; quem consome lê o do envelope e, na falta dele, o do AMQP. Chave em minúsculas, valor string, copiado como vem. |
| 3 | **Publicar** abre um span `PRODUCER` chamado `<destino> publish`; o `traceparent` injetado é o desse span. |
| 4 | **Consumir** abre um span `CONSUMER` chamado `<origem> process`, filho do `traceparent` recebido. Todo span e log do caso de uso rodam dentro dele. |
| 5 | **Header ausente ou inválido** não é erro: o consumer abre um trace novo (e nunca herda o span ambiente do worker). |
| 6 | **Request HTTP** abre um span `SERVER` filho do `traceparent` recebido; sem header, abre trace novo. |
| 7 | **Falha** no caso de uso marca o span com `STATUS_ERROR`, registra a exceção e repropaga (para retry/DLQ e para a compensação da saga). |
| 8 | **Flush:** HTTP, no `terminate` do middleware. Worker, ao fim de cada mensagem (processo longo: o shutdown nunca chega). |
| 9 | **Observabilidade nunca derruba o serviço:** sem `OTEL_EXPORTER_OTLP_ENDPOINT` o provider vira no-op; com um endpoint inválido também (o motivo vai para o log do processo). Atenção: o SDK recusa hostname com `_` (ex.: `meu_coletor`); use hífen. |
| 10 | **A saga atravessa requisições.** Quando o trabalho continua numa requisição diferente da que recebeu o comando (na Execução, o mecânico puxa a OS e finaliza o reparo depois, por REST), o contexto da saga (`sagaId`, `messageId` do comando e `traceparent`) é **guardado onde o item espera** e usado como pai do span `PRODUCER` dos eventos de volta. Os eventos ficam no trace da saga, não no da requisição HTTP do mecânico. |
| 11 | **Correlação:** os eventos derivados de um comando levam o mesmo `sagaId` e `causationId` = `messageId` do comando. Span e log levam `saga.id` e `messaging.message.id`. |

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

Campos de negócio usam o prefixo `os.` (`os.id`, `os.status`) e, na saga, `saga.id` e `execucao.id`, para o mesmo filtro funcionar nos 3 serviços.

Exemplo real, gerado por este repo ao consumir uma mensagem com `traceparent` conhecido:

```json
{"message":"dentro_do_consumer","context":{"os.id":42,"service.name":"oficina-execucao-service","trace.id":"0af7651916cd43dd8448eb211c80319c","span.id":"ec2fb28c2d43a1d8"},"level":200,"level_name":"INFO","channel":"production","datetime":"2026-10-27T00:07:34.487989+00:00","extra":{}}
```

## 4. Como adotar (PHP/Laravel)

Copie estes arquivos, sem alterar, e use os helpers. O namespace `App\Infrastructure\...` / `App\Interface\...` é o mesmo da Fase 3.

**Tracing (todo serviço):**

| Arquivo | Papel |
|---|---|
| `app/Infrastructure/Observability/OpenTelemetryProvider.php` | provider OTLP/HTTP, no-op sem endpoint ou com endpoint inválido, `nomeServico()`, `flush()` |
| `app/Infrastructure/Observability/Tracing.php` | helper de spans (`iniciar` aceita `SpanKind` e pai explícito; `executar()` envolve uma operação num span; `traceIdAtual()` devolve o `trace.id` para gravar em documentos) |
| `app/Infrastructure/Observability/ContextoObservabilidadeProcessor.php` | põe `service.name`, `trace.id`, `span.id` em todo log |
| `app/Infrastructure/Messaging/TraceContextCarrier.php` | `inject()` / `extract()` do `traceparent` em array de headers |
| `app/Infrastructure/Messaging/MessageTracing.php` | `publicar()` (aceita um pai explícito, ex.: o trace da saga) e `consumir()` |
| `app/Interface/Http/Middleware/CorrelacaoRequisicaoMiddleware.php` | span SERVER filho do `traceparent`, `X-Request-Id`, logs de acesso |
| `config/logging.php` (canal `stdout_json`) | registra o processor no canal |
| `bootstrap/app.php` | `$middleware->api(prepend: [CorrelacaoRequisicaoMiddleware::class])` |
| `tests/Concerns/UsaTracerEmMemoria.php` + `tests/**/*Trace*Test.php` | testes do contrato (rodam sem rede nem coletor) |

**Mensageria RabbitMQ (serviços que publicam ou consomem):**

| Arquivo | Papel |
|---|---|
| `app/Application/Messaging/Envelope.php` | envelope `{header, body}` do contrato v1.0: `novo()`, `fromJson()` (valida e lê o `traceparent` do envelope ou do AMQP), `toJson()` |
| `app/Application/Messaging/PublicadorDeEventosInterface.php` | porta de publicação |
| `app/Infrastructure/Messaging/RabbitMq/ConexaoRabbitMq.php` | conexão e canal sob demanda (`config/rabbitmq.php`) |
| `app/Infrastructure/Messaging/RabbitMq/RabbitMqPublicador.php` | publica em `oficina.eventos` com mensagem persistente, `mandatory` e confirmação do broker; `traceparent` no envelope e nos headers do AMQP |
| `app/Infrastructure/Messaging/RabbitMq/ConsumidorDeComandos.php` | consome uma fila com ack manual; fora do contrato ou com falha → `nack` sem requeue (DLQ); cada mensagem num span `CONSUMER` |
| `app/Domain/Execucao/ValueObjects/ContextoSaga.php` | `sagaId` + `messageId` do comando + `traceparent`, guardado onde o item espera (regra 10) |
| `config/rabbitmq.php`, dependência `php-amqplib/php-amqplib` ^3.7 (exige `ext-sockets`) | |

Dependências de tracing: `open-telemetry/sdk`, `open-telemetry/exporter-otlp`, `php-http/guzzle7-adapter`.

Uso no código:

```php
// publicar (dentro do trace corrente)
MessageTracing::publicar('execucao.concluida', function (array $headers) use ($publicador, $corpo, $saga) {
    $publicador->publicar('execucao.concluida',
        Envelope::novo('execucao.concluida', $saga->sagaId, $saga->mensagemId, $corpo, $headers['traceparent'] ?? null));
}, ['os.id' => $osId]);

// publicar de volta no trace de uma saga guardada (regra 10)
$pai = TraceContextCarrier::extract(['traceparent' => $saga->traceparent]);
MessageTracing::publicar('execucao.concluida', $callback, ['saga.id' => $saga->sagaId], $pai);

// consumir
MessageTracing::consumir('execucao.enviar-para-execucao', ['traceparent' => $envelope->traceparent], function () use ($envelope) {
    $casoDeUso->executar($envelope);
}, ['saga.id' => $envelope->sagaId, 'messaging.message.id' => $envelope->messageId]);

// chamada REST para outro serviço
$http->withHeaders(TraceContextCarrier::inject())->post($url, $dados);
```

## 5. Se o serviço não for PHP

O que vale é o contrato da seção 1 e os campos da seção 3. Em qualquer SDK do OpenTelemetry: usar o propagador `tracecontext`, criar o span `PRODUCER`/`CONSUMER` com os nomes acima e injetar/extrair o `traceparent` no envelope e nos headers da mensagem.

## 6. Como verificar (checklist de PR)

1. Abrir uma OS e conferir o **mesmo `trace.id`** nos logs dos 3 serviços.
2. No New Relic (ou Jaeger), ver **um trace só** com spans dos 3 `service.name`, em ordem `publish` → `process`.
3. Provocar uma falha (ex.: pagamento recusado) e ver a compensação **no mesmo trace**, com `STATUS_ERROR` no span que falhou.
4. Os testes do serviço passam: `TraceContextCarrierTest`, `MessageTracingTest`, `PropagacaoTraceHttpTest`, `EventosDeExecucaoTest` e, com o broker no ar, `RabbitMqFluxoTest`.

Reproduzir localmente com o Jaeger (container temporário; o hostname não pode ter `_`):

```bash
docker run -d --name jaeger --network oficina-execucao-service_execucao_network --network-alias jaeger \
  -p 16686:16686 -e COLLECTOR_OTLP_ENABLED=true jaegertracing/all-in-one:1.57
OTEL_EXPORTER_OTLP_ENDPOINT=http://jaeger:4318 docker compose up -d app worker
# ... publicar um comando, puxar e finalizar a OS; abrir http://localhost:16686
```

## 7. O que está e o que não está verificado

**Verificado neste repo** (`vendor/bin/phpunit`, com RabbitMQ, Redis e MongoDB do compose):

- `extract` de um `traceparent` conhecido faz o span herdar `trace_id` e `parent_span_id`; header ausente/inválido abre trace novo;
- publicar → consumir fica no mesmo trace, com o PRODUCER como pai do CONSUMER; falha no caso de uso gera `STATUS_ERROR` e repropaga;
- request HTTP com `traceparent` continua o trace; log JSON com `service.name`, `trace.id`, `span.id`;
- **cadeia única do comando aos eventos**, contra um RabbitMQ de verdade: comando (span do orquestrador) → consumidor da Execução → publicação de `execucao.iniciada` e de `execucao.concluida`, todos no mesmo `trace_id`, com o `traceparent` também nos headers do AMQP, `sagaId` e `causationId` corretos;
- os eventos saem no trace da saga mesmo com o mecânico agindo por outra requisição HTTP, e a timeline guarda o `traceId` do trace distribuído;
- mensagem repetida (mesmo `messageId`) é tratada uma vez; mensagem fora do contrato vai para a DLQ sem travar a fila; routing key sem fila ligada e broker fora do ar viram erro, não perda silenciosa;
- um endpoint OTLP inválido não derruba o serviço.

**Exemplo real** (Jaeger, exportado por OTLP/HTTP; o lado do OS Service foi simulado com o mesmo código):

```
trace e76ed1d5d7be7c510dc23eb762dde925        serviços: oficina-os-service, oficina-execucao-service
POST /api/ordens-servico                        [oficina-os-service | server]
└─ execucao.enviar-para-execucao publish        [oficina-os-service | producer]
   └─ execucao.enviar-para-execucao process     [oficina-execucao-service | consumer]
      ├─ FilaExecucaoService::enfileirar        [oficina-execucao-service | internal]
      ├─ execucao.iniciada publish              [oficina-execucao-service | producer]
      │  └─ os.saga.eventos process             [oficina-os-service | consumer]
      └─ execucao.concluida publish             [oficina-execucao-service | producer]
         └─ os.saga.eventos process             [oficina-os-service | consumer]
```

**Ainda não verificado:**

- a exportação para o **New Relic** (só testamos OTLP/HTTP contra o Jaeger);
- o trace com o OS Service e o Billing **reais**: eles ainda não têm código de mensageria; o lado do OS Service foi simulado com as classes deste repo;
- o `execucao.falhou` e a compensação (`execucao.cancelar-execucao`), que não fazem parte do caminho feliz;
- publicação **transacional**: o evento é publicado depois de gravar a transição. Se o broker cair nesse intervalo, a transição fica gravada e o evento não sai (a API responde 503 e registra o erro). Fechar esse buraco exige um outbox.
