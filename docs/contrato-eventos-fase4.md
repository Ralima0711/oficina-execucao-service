# Decomposição dos serviços e contrato de eventos — v1.0

**Grupo 77 · Fase 4 · 17/10/2026**

> **Status: fechado para implementação.** O enum de estados foi conferido contra o código da Fase 3 (`app/Domain/OrdemServico/ValueObjects/StatusOrdem.php`), e as duas decisões em aberto foram tomadas pela Tech Lead. O documento da máquina de estados do Johny é **revisão** deste contrato, não insumo: divergência encontrada vira correção aqui, com `version` incrementado.

---

## Parte 1 — Decomposição dos serviços

Formato da Aula 1 de Estrutura de Microsserviços: **Serviço × Operação × Integração**. Esta tabela é também a *"justificativa da divisão dos microsserviços"* que o enunciado exige no PDF.

### OS Service — `oficina-os-service`

Dono do atendimento e da ordem de serviço. Hospeda o **orquestrador da Saga**.

| Operação | Integração |
|---|---|
| Abrir OS | Dispara a Saga → Billing (comando) |
| Consultar status da OS | — (read model local) |
| Consultar histórico da OS | — (read model local) |
| Cancelar OS | Compensação da Saga |
| Cadastrar / consultar cliente | — |
| Cadastrar / consultar veículo | — |
| Autenticar cliente por CPF | Lambda da Fase 3 (reuso) |

**Bancos:** PostgreSQL (escrita, estado da OS e da Saga) · MongoDB (read model de status e histórico).

### Billing Service — `oficina-billing-service`

| Operação | Integração |
|---|---|
| Gerar orçamento | Consome comando do orquestrador; publica evento |
| Enviar orçamento para aprovação | Notifica o cliente |
| Registrar aprovação ou recusa | Publica evento |
| Criar cobrança | **Mercado Pago** (API externa) |
| Verificar pagamento | **Mercado Pago** (webhook ou polling) |
| Anular orçamento | Compensação |
| Estornar pagamento | Compensação · Mercado Pago |

**Banco:** PostgreSQL.

### Execução Service — `oficina-execucao-service`

| Operação | Integração |
|---|---|
| Enfileirar OS para execução | Consome comando do orquestrador |
| Iniciar diagnóstico | Publica evento |
| Registrar apontamentos do reparo | — (timeline local) |
| Concluir execução | Publica evento |
| Retirar da fila | Compensação |

**Bancos:** Redis (fila de execução, com prioridade) · MongoDB (timeline de diagnóstico e reparo).

### Regra que vale para os três

Nenhum serviço acessa o banco de outro. Toda troca é por **API REST síncrona** (consultas pontuais) ou **mensageria assíncrona** (a Saga e os eventos de domínio).

---

## Parte 2 — Contrato de eventos

### Envelope da mensagem

A Aula 2 define a mensagem como **header + body**, com o header carregando metadados em pares chave-valor. É onde vivem a idempotência e o trace.

```json
{
  "header": {
    "messageId": "uuid-v4",
    "sagaId": "uuid-v4",
    "causationId": "messageId da mensagem que causou esta",
    "type": "GerarOrcamento",
    "occurredAt": "2026-10-20T14:32:10Z",
    "traceparent": "00-<trace-id>-<span-id>-01",
    "version": 1
  },
  "body": { }
}
```

| Campo | Para quê |
|---|---|
| `messageId` | **Chave de idempotência.** Cada consumidor guarda os IDs já processados e descarta repetição |
| `sagaId` | Correlaciona todos os passos da mesma Saga. É o que permite reconstruir o fluxo no New Relic |
| `causationId` | Diz qual mensagem causou esta. Monta a cadeia causal sem precisar de log |
| `traceparent` | Padrão W3C. Propaga o contexto do OpenTelemetry pela mensagem — em mensageria isso é código explícito, não acontece sozinho |
| `version` | Permite evoluir o payload sem quebrar consumidor antigo |

**Idempotência:** cada serviço guarda os `messageId` processados **no próprio banco** — tabela `mensagens_processadas` no Postgres, coleção equivalente no Mongo. Não existe store compartilhado; isso violaria a regra de não acessar banco de outro serviço.

### Canais

A Aula 2 ensina dois tipos de canal, e eles mapeiam direto na Saga orquestrada:

**Comandos — point-to-point.** Fila direta, um consumidor só. O orquestrador manda, o participante executa.

| Fila | Consumidor |
|---|---|
| `billing.gerar-orcamento` | Billing |
| `billing.cobrar-pagamento` | Billing |
| `billing.anular-orcamento` | Billing (compensação) |
| `billing.estornar-pagamento` | Billing (compensação) |
| `execucao.enviar-para-execucao` | Execução |
| `execucao.cancelar-execucao` | Execução (compensação) |

**Eventos — publish-subscribe.** Exchange `oficina.eventos` (tipo `topic`), com uma fila por consumidor interessado.

| Routing key | Publicado por |
|---|---|
| `orcamento.gerado` | Billing |
| `orcamento.aprovado` | Billing |
| `orcamento.recusado` | Billing |
| `aprovacao.expirada` | Billing |
| `pagamento.confirmado` | Billing |
| `pagamento.recusado` | Billing |
| `execucao.iniciada` | Execução |
| `execucao.concluida` | Execução |
| `execucao.falhou` | Execução |
| `os.cancelada` | OS |
| `os.finalizada` | OS |

---

## Parte 3 — Estados da OS

### O que já existe na Fase 3

`app/Domain/OrdemServico/ValueObjects/StatusOrdem.php` define sete estados, e o fluxo deles já é quase a saga do enunciado:

```
RECEBIDA → EM_DIAGNOSTICO → AGUARDANDO_APROVACAO → APROVADA → EM_EXECUCAO → FINALIZADA → ENTREGUE
```

### O que a Saga acrescenta

| Estado novo | Por quê |
|---|---|
| `AGUARDANDO_ORCAMENTO` | Entre o diagnóstico e a aprovação. A OS espera o Billing responder |
| `AGUARDANDO_PAGAMENTO` | Entre a aprovação e a execução. Decisão: cobrar antes de executar |
| `CANCELADA` | **Não existe hoje.** Sem ele a compensação não tem para onde levar a ordem, e compensação é requisito do enunciado |

`ENTREGUE` fica fora da saga — é passo operacional posterior à finalização.

### Fluxo completo

```
RECEBIDA
  → EM_DIAGNOSTICO
  → AGUARDANDO_ORCAMENTO
  → AGUARDANDO_APROVACAO
  → APROVADA
  → AGUARDANDO_PAGAMENTO
  → EM_EXECUCAO
  → FINALIZADA
  → ENTREGUE

qualquer passo falha → CANCELADA
```

---

## Parte 3b — A Saga, passo a passo

| # | Passo | Comando | Eventos de retorno | Estado da OS enquanto espera | Compensação se falhar depois |
|---|---|---|---|---|---|
| 1 | Gerar orçamento | `GerarOrcamento` | `orcamento.gerado` / `orcamento.recusado` | `AGUARDANDO_ORCAMENTO` | `CancelarOS` |
| 2 | Aguardar aprovação do cliente | — (espera humana) | `orcamento.aprovado` / `orcamento.recusado` / `aprovacao.expirada` | `AGUARDANDO_APROVACAO` | `AnularOrcamento` → `CancelarOS` |
| 3 | Cobrar pagamento | `CobrarPagamento` | `pagamento.confirmado` / `pagamento.recusado` | `AGUARDANDO_PAGAMENTO` | `AnularOrcamento` → `CancelarOS` |
| 4 | Enviar para execução | `EnviarParaExecucao` | `execucao.iniciada` → `execucao.concluida` / `execucao.falhou` | `EM_EXECUCAO` | `EstornarPagamento` → `AnularOrcamento` → `CancelarOS` |
| 5 | Finalizar | — | `os.finalizada` | `FINALIZADA` | — |

### Decisão tomada: pagamento antes da execução

O enunciado dá o fluxo como *"abrir OS → gerar orçamento → aguardar aprovação → enviar para execução"*, sem dizer onde entra o pagamento. Mas o Billing é responsável por *"atualização do status da OS após pagamento"*, então ele entra em algum lugar.

**Decisão: cobrar antes de executar.** A justificativa vai para o ADR-03 e é a **Pessimistic View** da Aula 3 — reordenar os passos da saga para não comprometer trabalho contra um estado que ainda pode mudar. Nenhum serviço é prestado sem pagamento confirmado.

A alternativa, cobrar depois, é mais realista como oficina, mas se o pagamento falhar o serviço já foi prestado e a compensação vira só estorno contábil — fraco justamente no ponto que o vídeo precisa demonstrar.

### Decisão tomada: timeout de aprovação em 7 dias

O passo 2 tem um humano no meio.

| Item | Decisão |
|---|---|
| Prazo de validade do orçamento | **7 dias** |
| Quem dispara | **TTL na fila do RabbitMQ** — sem job agendado no OS Service |
| Efeito ao expirar | Evento `aprovacao.expirada` → a saga compensa: `AnularOrcamento` → OS vai para **`CANCELADA`** |

Não existe estado `EXPIRADA` reabrível. Caminho único de compensação, mais simples de implementar, testar e demonstrar.

---

## Parte 4 — Payloads

Só os campos obrigatórios. O que não estiver aqui, não existe no contrato.

### `GerarOrcamento` (comando → Billing)

```json
{
  "osId": "uuid",
  "clienteId": "uuid",
  "veiculo": { "placa": "DEM1A23", "modelo": "string", "ano": 2020 },
  "descricaoProblema": "string",
  "itensDiagnosticados": [
    { "descricao": "string", "tipo": "peca|servico", "quantidade": 1 }
  ]
}
```

### `orcamento.gerado` (evento ← Billing)

```json
{
  "osId": "uuid",
  "orcamentoId": "uuid",
  "valorTotal": 1250.00,
  "moeda": "BRL",
  "validoAte": "2026-10-27T23:59:59Z",
  "itens": [
    { "descricao": "string", "tipo": "peca|servico", "quantidade": 1, "valorUnitario": 250.00 }
  ]
}
```

### `orcamento.aprovado` / `orcamento.recusado` (evento ← Billing)

```json
{
  "osId": "uuid",
  "orcamentoId": "uuid",
  "decidioEm": "2026-10-21T10:15:00Z",
  "motivo": "string (só na recusa)"
}
```

### `CobrarPagamento` (comando → Billing)

```json
{
  "osId": "uuid",
  "orcamentoId": "uuid",
  "valorTotal": 1250.00,
  "moeda": "BRL"
}
```

### `pagamento.confirmado` / `pagamento.recusado` (evento ← Billing)

```json
{
  "osId": "uuid",
  "pagamentoId": "uuid",
  "orcamentoId": "uuid",
  "valorPago": 1250.00,
  "metodo": "pix|credito|debito|boleto",
  "confirmadoEm": "2026-10-21T10:20:00Z",
  "motivo": "string (só na recusa)"
}
```

### `EnviarParaExecucao` (comando → Execução)

```json
{
  "osId": "uuid",
  "orcamentoId": "uuid",
  "prioridade": 1,
  "itens": [
    { "descricao": "string", "tipo": "peca|servico", "quantidade": 1 }
  ]
}
```

### `execucao.iniciada` / `execucao.concluida` / `execucao.falhou` (evento ← Execução)

```json
{
  "osId": "uuid",
  "execucaoId": "uuid",
  "mecanicoId": "uuid",
  "iniciadaEm": "2026-10-21T11:00:00Z",
  "concluidaEm": "2026-10-21T16:30:00Z",
  "motivo": "string (só na falha)"
}
```

### Comandos de compensação

`AnularOrcamento`, `EstornarPagamento` e `CancelarExecucao` carregam o mínimo — `osId`, o id do recurso a desfazer e `motivo`:

```json
{
  "osId": "uuid",
  "orcamentoId": "uuid",
  "motivo": "pagamento_recusado|aprovacao_expirada|execucao_falhou|cancelado_pelo_cliente"
}
```

---

## Parte 5 — Regras que valem para todo consumidor

1. **Descarte por `messageId` já processado.** Antes de qualquer efeito colateral, consulta o registro local. Repetição não gera efeito duplo.
2. **Propague o `traceparent`.** Ao publicar qualquer mensagem derivada de outra, copie o contexto de trace. Sem isso o trace distribuído não costura, e isso é um dos quatro itens obrigatórios do vídeo.
3. **Nunca consulte o banco de outro serviço.** Se faltar dado, ou vem no payload ou vem por REST.
4. **Toda falha publica evento.** Falhar em silêncio quebra a Saga — o orquestrador fica esperando para sempre.
5. **Payload é contrato.** Campo novo entra com `version` incrementado; campo existente não muda de tipo nem de significado.

---

## Revisão pendente

Este contrato está fechado para implementação. O que falta é revisão, não decisão:

- [ ] **Johny** — conferir a máquina de estados contra a Parte 3 e apontar divergência
- [ ] **Gustavo** — validar os payloads que o Billing consome e publica
- [ ] **David** — validar os payloads que a Execução consome e publica

Divergência encontrada vira correção aqui, com `version` incrementado. Ninguém implementa contra uma versão que não está neste arquivo.

---

*Grupo 77 · FIAP SOAT Pós-Tech · Fase 4*
