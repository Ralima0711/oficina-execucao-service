# Decomposição dos serviços e contrato de eventos — v1.1

**Grupo 77 · Fase 4 · revisado em 02/11/2026**

> **Status: fechado para implementação.** A v1.1 fecha as ambiguidades que a revisão do Johny (PR #2 do `oficina-os-service`) levantou e as premissas que o Gustavo e o David assumiram em código. Nada aqui é proposta: é decisão. Divergência nova vira v1.2, neste arquivo, antes de virar código.

---

## Parte 0 — O que mudou da v1.0 para a v1.1

A v1.0 deixou nove pontos em aberto. Cada um foi respondido três vezes, separadamente, em três repositórios — e foi isso que motivou esta revisão. O que mudou:

| # | Ambiguidade da v1.0 | Decisão da v1.1 | Quem precisa mexer |
|---|---|---|---|
| 1 | `itensDiagnosticados` obrigatório ou não | **Obrigatório, mínimo 1 item.** Orçamento vazio não é gerado | Billing (reverter) |
| 2 | `orcamento.recusado` era falha de geração *e* recusa do cliente | **Separados:** `orcamento.falhou` (geração) × `orcamento.recusado` (cliente) | Billing, OS |
| 3 | Sentido da `prioridade` | **1 a 5, maior é mais urgente, padrão 1** | ninguém — confirma o que a Execução fez |
| 4 | Quando usar `CancelarExecucao` | **Enfileirada ou iniciada,** com ordem de compensação fixada | Execução, OS |
| 5 | `CancelarOS`, `os.cancelada`, `os.finalizada`, `AprovacaoExpirada` sem payload | **Payloads definidos** na Parte 4 | OS, Billing |
| 6 | `APROVADA` é persistido? | **Sim,** antes de publicar `CobrarPagamento` | OS |
| 7 | Gatilho de `EM_EXECUCAO` | **Ao publicar `EnviarParaExecucao`.** `execucao.iniciada` só registra mecânico e `execucaoId` | OS |
| 8 | Técnica de idempotência | **Comportamento é contrato; técnica é livre por banco.** As duas formas estão documentadas | ninguém |
| 9 | Comando inválido: recusar ou aceitar | **Corrige o acessório, falha o resto com evento.** Nunca DLQ sem resposta | Execução (já fez), Billing |

**Nenhum `version` de payload sobe para 2.** Nada mudou de tipo nem de significado: `itensDiagnosticados` voltou a ser exigido do produtor, `veiculo.modelo` e `veiculo.ano` relaxaram, e o resto é evento novo. Mensagem nova não é versão nova.

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
| Verificar pagamento | **Mercado Pago** (polling — ver Parte 4) |
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

#### `header.type` — **v1.1**

Em **comando**, `type` é o nome do comando (`GerarOrcamento`, `CobrarPagamento`…).
Em **evento**, `type` é a **routing key** (`orcamento.gerado`, `execucao.concluida`…).

Não são dois esquemas: é a mesma regra — `type` nomeia a mensagem no vocabulário do canal em que ela anda.

#### `traceparent` — **v1.1**

Vai nos **dois lugares**: no `header` do envelope e nos *application headers* do AMQP.

A redundância é de propósito. O header AMQP é o que o W3C especifica e o que ferramenta de instrumentação automática lê; o envelope é o que sobrevive se a mensagem for persistida, reexportada ou inspecionada fora do broker. Divergiram? O header AMQP ganha.

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

| Routing key | Publicado por | Significa |
|---|---|---|
| `orcamento.gerado` | Billing | Orçamento criado e enviado ao cliente |
| **`orcamento.falhou`** | Billing | **v1.1** — não foi possível *gerar* o orçamento |
| `orcamento.aprovado` | Billing | O **cliente** aprovou |
| `orcamento.recusado` | Billing | O **cliente** recusou |
| `aprovacao.expirada` | Billing | Os 7 dias venceram sem decisão |
| `pagamento.confirmado` | Billing | |
| `pagamento.recusado` | Billing | |
| `execucao.iniciada` | Execução | Mecânico puxou a OS |
| `execucao.concluida` | Execução | |
| `execucao.falhou` | Execução | |
| `os.cancelada` | OS | Terminal de fracasso |
| `os.finalizada` | OS | Terminal de sucesso |

> **A separação de `orcamento.falhou` e `orcamento.recusado` é a correção mais importante da v1.1.** Na v1.0 o mesmo evento significava "o Billing não conseguiu gerar" e "o cliente não quis" — dois fatos com compensações diferentes chegando pelo mesmo canal. O orquestrador não tinha como distinguir sem ler o `motivo`, e `motivo` é texto.

### Fonte única da topologia — **v1.1**

A topologia do RabbitMQ (filas, exchanges, bindings, DLQs, TTL) vive em **um lugar só**: `rabbitmq/definitions.json` no `oficina-infra-fase4`.

Repositório de serviço pode manter uma cópia parcial em `docker/` para subir o ambiente local sozinho, com duas condições:

1. A cópia é declarada no README como **subconjunto de desenvolvimento**, não como fonte.
2. O pipeline do serviço roda a conferência contra a fonte (`scripts/confere-filas.py`, no infra).

Fila com nome divergente não quebra teste e não quebra deploy: o serviço sobe, conecta, declara a fila errada e consome uma fila que ninguém publica. A saga para no meio sem log de erro. É a falha mais cara que esta arquitetura permite e a mais barata de evitar.

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

### Gatilhos de transição — **v1.1**

A v1.0 dizia em que estado a OS fica em cada passo, mas não em que **instante** ela muda. Três pontos ficam fixados:

| Transição | Acontece quando | Não acontece quando |
|---|---|---|
| `APROVADA` | `orcamento.aprovado` é processado. **É persistido**, antes de publicar `CobrarPagamento` | — |
| `AGUARDANDO_PAGAMENTO` | `CobrarPagamento` é publicado | — |
| `EM_EXECUCAO` | **`EnviarParaExecucao` é publicado** | `execucao.iniciada` chega — esse evento só registra `mecanicoId` e `execucaoId` no read model |

`APROVADA` ser persistido parece redundante — a OS fica nele por milissegundos. Não é: se o OS Service cair entre aprovar e publicar a cobrança, é esse estado que diz ao orquestrador, na retomada, que a aprovação já valeu e o que falta é a cobrança. Sem ele, a retomada não sabe se reprocessa a aprovação ou a cobrança.

`EM_EXECUCAO` começar na publicação do comando, e não na chegada de `execucao.iniciada`, tem um custo assumido: a OS aparece como "em execução" enquanto ainda espera na fila do mecânico. A alternativa seria um oitavo estado (`AGUARDANDO_EXECUCAO`), e um estado novo custa migração, tabela de transição, testes e vídeo nos três serviços. **Decisão: fila é parte da execução.** Quem quiser a granularidade fina tem a timeline da Execução, que já registra o instante exato.

---

## Parte 3b — A Saga, passo a passo

| # | Passo | Comando | Eventos de retorno | Estado da OS enquanto espera | Compensação se falhar depois |
|---|---|---|---|---|---|
| 1 | Gerar orçamento | `GerarOrcamento` | `orcamento.gerado` / **`orcamento.falhou`** | `AGUARDANDO_ORCAMENTO` | `CancelarOS` |
| 2 | Aguardar aprovação do cliente | — (espera humana) | `orcamento.aprovado` / `orcamento.recusado` / `aprovacao.expirada` | `AGUARDANDO_APROVACAO` | `AnularOrcamento` → `CancelarOS` |
| 3 | Cobrar pagamento | `CobrarPagamento` | `pagamento.confirmado` / `pagamento.recusado` | `AGUARDANDO_PAGAMENTO` | `AnularOrcamento` → `CancelarOS` |
| 4 | Enviar para execução | `EnviarParaExecucao` | `execucao.iniciada` → `execucao.concluida` / `execucao.falhou` | `EM_EXECUCAO` | `CancelarExecucao` → `EstornarPagamento` → `AnularOrcamento` → `CancelarOS` |
| 5 | Finalizar | — | `os.finalizada` | `FINALIZADA` | — |

### Ordem da compensação — **v1.1**

A compensação desfaz **na ordem inversa** em que os passos foram feitos, e cada comando é idempotente: recebido duas vezes, o efeito é um só.

```
EM_EXECUCAO falhou
  → CancelarExecucao      (tira da fila ou encerra o reparo)
  → EstornarPagamento     (só se houve pagamento confirmado)
  → AnularOrcamento
  → CancelarOS            → CANCELADA
```

Compensação que não se aplica é **pulada, não é erro**: cancelar execução de uma OS que nunca chegou à fila responde sucesso sem fazer nada.

### `CancelarExecucao` — quando vale — **v1.1**

| Situação da OS na Execução | O que `CancelarExecucao` faz |
|---|---|
| Ainda na fila, mecânico não puxou | Remove da fila, libera o `execucaoId`, publica `execucao.falhou` com motivo `cancelada` |
| Mecânico já puxou, reparo em andamento | Encerra o reparo, libera o mecânico, registra o encerramento na timeline, publica `execucao.falhou` |
| Já concluída (`execucao.concluida` publicado) | **Não desfaz.** Responde sucesso e registra a tentativa. Reparo feito não volta atrás; o que se desfaz é dinheiro, pelo estorno |

### Decisão tomada: pagamento antes da execução

O enunciado dá o fluxo como *"abrir OS → gerar orçamento → aguardar aprovação → enviar para execução"*, sem dizer onde entra o pagamento. Mas o Billing é responsável por *"atualização do status da OS após pagamento"*, então ele entra em algum lugar.

**Decisão: cobrar antes de executar.** A justificativa vai para o ADR-0011 e é a **Pessimistic View** da Aula 3 — reordenar os passos da saga para não comprometer trabalho contra um estado que ainda pode mudar. Nenhum serviço é prestado sem pagamento confirmado.

A alternativa, cobrar depois, é mais realista como oficina, mas se o pagamento falhar o serviço já foi prestado e a compensação vira só estorno contábil — fraco justamente no ponto que o vídeo precisa demonstrar.

### Decisão tomada: timeout de aprovação em 7 dias

O passo 2 tem um humano no meio.

| Item | Decisão |
|---|---|
| Prazo de validade do orçamento | **7 dias** |
| Quem dispara | **TTL na fila do RabbitMQ** — sem job agendado no OS Service |
| Efeito ao expirar | Evento `aprovacao.expirada` → a saga compensa: `AnularOrcamento` → OS vai para **`CANCELADA`** |

Não existe estado `EXPIRADA` reabrível. Caminho único de compensação, mais simples de implementar, testar e demonstrar.

#### Como a expiração vira evento — **v1.1**

O caminho inteiro está no broker, declarado em `definitions.json`:

```
billing.aguardando-aprovacao   (x-message-ttl: 7 dias,
                                x-dead-letter-exchange: oficina.espera,
                                x-dead-letter-routing-key: aprovacao.expirada)
        │  TTL vence
        ▼
oficina.espera  →  billing.aprovacao-expirada
        │
        ▼
   Billing consome e publica o evento de domínio `aprovacao.expirada`
   em oficina.eventos
```

Ninguém consome `billing.aguardando-aprovacao` — é a sala de espera, e consumidor nela quebraria o timeout. Quem transforma a *dead-letter* em evento de domínio é o **Billing**, não o broker: o broker move a mensagem, o serviço dá significado a ela.

**A expiração é ignorada** se o `orcamentoId` não for o vigente ou se a saga já tiver avançado. Expiração atrasada não cancela OS já paga.

---

## Parte 4 — Payloads

Só os campos obrigatórios. O que não estiver aqui, não existe no contrato.

### `GerarOrcamento` (comando → Billing) — **alterado na v1.1**

```json
{
  "osId": "uuid",
  "clienteId": "uuid",
  "veiculo": { "placa": "DEM1A23", "modelo": "string (opcional)", "ano": 2020 },
  "descricaoProblema": "string",
  "itensDiagnosticados": [
    { "descricao": "string", "tipo": "peca|servico", "quantidade": 1 }
  ]
}
```

| Campo | v1.0 | v1.1 |
|---|---|---|
| `itensDiagnosticados` | ambíguo | **obrigatório, mínimo 1 item** |
| `veiculo.placa` | obrigatório | obrigatório |
| `veiculo.modelo`, `veiculo.ano` | obrigatórios | **opcionais** |

**Por que itens voltaram a ser obrigatórios.** Sem itens o orçamento sai com `valorTotal: 0`, e orçamento de valor zero não gera cobrança — o Billing responde 422. A OS ficaria presa em `AGUARDANDO_PAGAMENTO` para sempre, sem erro e sem compensação. Orçamento vazio não é um orçamento: é uma falha de geração, e agora tem evento próprio.

Diagnóstico que não encontrou nada a reparar **não** é caso de orçamento vazio — é `orcamento.falhou` com motivo `sem_itens_a_orcar`, e a OS vai para `CANCELADA`.

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

`valorTotal` é sempre maior que zero. Billing que chegaria a zero publica `orcamento.falhou`.

### `orcamento.falhou` (evento ← Billing) — **novo na v1.1**

```json
{
  "osId": "uuid",
  "motivo": "sem_itens_a_orcar|item_fora_do_catalogo|erro_interno",
  "detalhe": "string",
  "ocorridoEm": "2026-10-21T10:15:00Z"
}
```

Não tem `orcamentoId`: nenhum orçamento foi criado. É o que distingue este evento de `orcamento.recusado`.

### `orcamento.aprovado` / `orcamento.recusado` (evento ← Billing)

Decisão **do cliente**, sempre sobre um orçamento que existe.

```json
{
  "osId": "uuid",
  "orcamentoId": "uuid",
  "decidioEm": "2026-10-21T10:15:00Z",
  "motivo": "string (só na recusa)"
}
```

### `aprovacao.expirada` (evento ← Billing) — **payload definido na v1.1**

```json
{
  "osId": "uuid",
  "orcamentoId": "uuid",
  "expirouEm": "2026-10-28T10:15:00Z"
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

**Idempotência externa — v1.1.** Cobrança é idempotente por `orcamentoId`: o mesmo orçamento com cobrança pendente ou confirmada reaproveita a preferência existente, não cria outra. Isso é garantido por **restrição única no banco** (`orcamento_id` entre os pagamentos pendentes/confirmados), não só por consulta antes da escrita — duas réplicas do worker consomem a mesma fila, e `SELECT` seguido de `INSERT` não é atômico. Sem a constraint, dois comandos concorrentes cobram o cliente duas vezes.

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

**Como o Billing sabe que o pagamento saiu — v1.1.** Por *polling* no Mercado Pago, não por webhook: o sandbox precisaria de URL pública, e o cluster do lab não tem. O Billing consulta por `external_reference = orcamentoId` e publica o evento na primeira resposta terminal (`approved`, `rejected`, `cancelled`). Cadência e limite de tentativas ficam a cargo do Billing; esgotado o limite, publica `pagamento.recusado` com motivo `timeout`.

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

**`prioridade` — v1.1:** inteiro de **1 a 5**, padrão **1**, **maior é mais urgente**. Mesma prioridade, vale a ordem de chegada. Valor fora da faixa é **ajustado à faixa e registrado em log** — ver a regra 6 da Parte 5.

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

O `execucaoId` nasce quando a OS entra na fila, não quando o mecânico a puxa — assim uma falha anterior ao reparo já tem identidade. Em `execucao.falhou` antes de alguém puxar, `mecanicoId` vem nulo.

### `CancelarOS` (comando interno do OS Service) — **definido na v1.1**

```json
{
  "osId": "uuid",
  "sagaId": "uuid",
  "motivo": "orcamento_falhou|orcamento_recusado|aprovacao_expirada|pagamento_recusado|execucao_falhou"
}
```

**Não tem fila.** O orquestrador vive dentro do OS Service e cancela o próprio agregado — publicar isso num canal seria o serviço mandando mensagem para ele mesmo. O payload existe porque o passo é registrado no estado da saga e no log, e é o que aparece no vídeo quando a compensação é demonstrada.

### `os.cancelada` / `os.finalizada` (evento ← OS) — **payloads definidos na v1.1**

```json
{
  "osId": "uuid",
  "sagaId": "uuid",
  "estadoFinal": "CANCELADA|FINALIZADA",
  "motivo": "string (só em os.cancelada)",
  "ocorridoEm": "2026-10-21T16:35:00Z"
}
```

São eventos de **anúncio**: fecham a saga e alimentam o read model. Nenhum participante age sobre eles.

### Comandos de compensação

`AnularOrcamento`, `EstornarPagamento` e `CancelarExecucao` carregam o mínimo — `osId`, o id do recurso a desfazer e `motivo`:

```json
{
  "osId": "uuid",
  "orcamentoId": "uuid",
  "motivo": "orcamento_recusado|aprovacao_expirada|pagamento_recusado|execucao_falhou"
}
```

> **`cancelado_pelo_cliente` saiu na v1.1.** Estava no enum sem operação, evento ou regra de elegibilidade por trás. Cancelamento voluntário pelo cliente fica **fora do escopo da Fase 4**; entra na v2 do contrato se virar requisito.

---

## Parte 5 — Regras que valem para todo consumidor

### 1. Descarte por `messageId` já processado

Antes de qualquer efeito colateral, consulta o registro local. Repetição não gera efeito duplo. Cada serviço guarda os IDs **no próprio banco** — não existe store compartilhado, isso violaria a regra 3.

**O contrato exige o comportamento, não a técnica — v1.1.** Os serviços não têm o mesmo banco, e a implementação correta depende do que o banco garante. As duas formas em uso no grupo:

| Forma | Onde | Como | O que acontece numa queda |
|---|---|---|---|
| **Transacional** | OS Service, Billing (Postgres) | Insere o `messageId` **dentro** da transação que aplica os efeitos. Falhou, o rollback desfaz os dois | Seguro: ou tudo foi gravado, ou nada foi. O ACK só vai depois do commit |
| **Marca-antes** | Execução (Mongo) | Marca o `messageId` antes dos efeitos e **libera** na exceção | Exceção tratada é segura. Queda do processo no meio deixa a marca presa e a mensagem é descartada no retry — efeito pela metade |

A forma transacional é melhor e deve ser usada **sempre que o banco permitir**. O Mongo da Execução não dá transação multi-documento nesta configuração, então ali vale a marca-antes, com o risco acima **registrado e aceito**: a janela é pequena, o efeito é uma OS que não entra na fila, e o sintoma aparece no read model.

Isto não é inconsistência: é a mesma garantia contratual implementada com o que cada banco oferece. Vale dizer isso em voz alta no vídeo.

### 2. Propague o `traceparent`

Ao publicar qualquer mensagem derivada de outra, copie o contexto de trace, nos headers AMQP e no envelope. Sem isso o trace distribuído não costura, e isso é um dos quatro itens obrigatórios do vídeo.

### 3. Nunca consulte o banco de outro serviço

Se faltar dado, ou vem no payload ou vem por REST.

### 4. Toda falha publica evento

Falhar em silêncio quebra a Saga — o orquestrador fica esperando para sempre.

### 5. Payload é contrato

Campo novo entra com `version` incrementado; campo existente não muda de tipo nem de significado.

### 6. Comando inválido: corrige o acessório, falha o resto — **nova na v1.1**

Validação de comando numa saga não funciona como validação de API. Recusar e mandar para a DLQ deixa o orquestrador esperando uma resposta que nunca vem — o pior dos dois mundos, porque a saga trava sem erro visível.

| Tipo de problema | O que o participante faz |
|---|---|
| Campo **acessório** fora do domínio válido (ex.: `prioridade: 9`) | **Ajusta ao válido**, registra em log com o valor original, executa o comando |
| Campo **essencial** ausente, malformado ou inconsistente (ex.: `osId` que não é UUID, `valorTotal` negativo) | **Publica evento de falha** do passo, com motivo. A saga compensa |
| Erro transitório (banco fora, API externa fora) | **Retry** com backoff, até o limite. Esgotado, publica evento de falha |

Nenhum dos três caminhos é "descartar em silêncio" e nenhum é "DLQ e acabou". DLQ serve para mensagem que o serviço não conseguiu nem interpretar — e mesmo aí, só depois de publicar a falha.

### 7. Evento fora de ordem ou de outra saga — **nova na v1.1**

Idempotência por `messageId` resolve repetição, não resolve ordem. Um evento pode chegar atrasado, de uma saga antiga, ou referindo um recurso que não é mais o vigente.

Antes de aplicar qualquer efeito, o consumidor confere, nesta ordem: `sagaId` ativo · estado atual compatível com a transição · `orcamentoId` / `pagamentoId` / `execucaoId` igual ao vigente.

Falhou qualquer uma: **descarta, registra em log com o motivo, confirma a mensagem.** Não é erro, não vai para a DLQ e não força transição. `CANCELADA` e `ENTREGUE` são terminais — evento atrasado não reabre OS.

---

## Revisão — fechada na v1.1

| Quem | O que revisou | Resultado |
|---|---|---|
| **Johny** | Máquina de estados contra a Parte 3 | 10 ambiguidades apontadas · todas respondidas nesta versão |
| **Gustavo** | Payloads que o Billing consome e publica | Divergência de `itensDiagnosticados` e idempotência da cobrança · resolvidas |
| **David** | Payloads que a Execução consome e publica | Teste de contrato contra o AsyncAPI · achou o caso de prioridade fora da faixa · resolvido na regra 6 |

Divergência nova vira **v1.2**, neste arquivo, antes de virar código. Ninguém implementa contra uma versão que não está aqui.

### O que muda em código por causa desta versão

| Repositório | Mudança |
|---|---|
| `oficina-billing-service` | `itensDiagnosticados` volta a `required|array|min:1` · novo evento `orcamento.falhou` · constraint única em `orcamento_id` nos pagamentos pendentes/confirmados |
| `oficina-os-service` | Tabela de transições com os gatilhos da Parte 3 · consumir `orcamento.falhou` · payloads de `CancelarOS`, `os.cancelada`, `os.finalizada` |
| `oficina-execucao-service` | Nada obrigatório — a v1.1 confirma o que já foi implementado · declarar a cópia de `docker/rabbitmq` como subconjunto de dev e ligar a conferência no CI |

O `asyncapi.yaml` no `oficina-infra-fase4` é atualizado junto com este documento. Os dois divergindo foi a origem de três dos nove pontos acima.

---

*Grupo 77 · FIAP SOAT Pós-Tech · Fase 4 · contrato v1.1 · 02/11/2026*
