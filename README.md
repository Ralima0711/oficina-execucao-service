# oficina-execucao-service

Microsserviço de **Execução e Produção** do Tech Challenge SOAT Fase 4 — Grupo 77.

## Responsabilidades

| Operação | Observação |
|---|---|
| Enfileirar OS para execução | Consome comando do orquestrador |
| Iniciar diagnóstico | Publica evento |
| Registrar apontamentos do reparo | Timeline local |
| Concluir execução | Publica evento, e a Saga finaliza a OS |
| Retirar da fila | Compensação |

## Bancos

| Banco | Papel |
|---|---|
| Redis | Fila de execução com prioridade — a aula de chave-valor |
| MongoDB | Timeline de diagnóstico e reparo — documento, não tabela |

Nenhum outro serviço acessa estes bancos.

Como estão usados hoje:

- **Redis:** Sorted Set (`execucao:fila`) ordenado por prioridade e chegada, o `execucaoId` e o contexto da saga de cada OS enquanto ela espera (`execucao:os:{osId}:execucao`) e uma trava por mecânico (`execucao:mecanico:{id}:atual`). Enfileirar, puxar, devolver, remover e concluir são scripts Lua atômicos.
- **MongoDB:** coleção `timeline_os`, um documento por evento, só inserção, com o `traceId` de cada evento. Um índice único parcial garante que cada transição acontece uma vez por OS (duas requisições simultâneas não gravam o mesmo evento duas vezes). A coleção `mensagens_processadas` guarda os `messageId` já tratados (TTL de 30 dias).

## Mensageria

**Consome comandos** de `execucao.enviar-para-execucao` e `execucao.cancelar-execucao`.

**Publica eventos** no exchange `oficina.eventos`: `execucao.iniciada`,
`execucao.concluida` e `execucao.falhou`.

Toda falha publica evento. Falhar em silêncio trava a Saga — o orquestrador fica
esperando para sempre.

**Como funciona hoje (caminho feliz):**

1. O orquestrador publica `EnviarParaExecucao` em `execucao.enviar-para-execucao`; o worker (`php artisan execucao:consumir`) valida o envelope, descarta `messageId` repetido, e coloca a OS na fila de execução guardando o `sagaId`, o `messageId` do comando e o `traceparent`.
2. O mecânico puxa a OS (`POST /api/execucao/fila/proxima`) e a Execução publica `execucao.iniciada`.
3. Ao finalizar o reparo (`POST /api/execucao/os/{osId}/finalizar`) ela publica `execucao.concluida`.

Os eventos voltam no **mesmo trace** do comando, com o mesmo `sagaId` e `causationId` igual ao `messageId` do comando, mesmo com o mecânico agindo em outra requisição (ver `docs/contrato-observabilidade.md`). Mensagem fora do contrato ou que falha ao ser tratada vai para a DLQ (`dlq.execucao.enviar-para-execucao`).

> **Ainda não implementado:** `execucao.falhou`, o comando `execucao.cancelar-execucao` (compensação) e a publicação transacional (o evento sai depois de gravar a transição; se o broker cair nesse intervalo a API responde 503 e a transição fica gravada sem o evento). Uma OS enfileirada direto por `POST /api/execucao/fila` não tem saga e não publica evento.
>
> A **prioridade** é premissa: o contrato traz `prioridade` (padrão 1) sem definir o sentido; aqui, maior número é mais urgente (faixa de 1 a 5) e, na mesma prioridade, vale a ordem de chegada. Pendente de confirmação na revisão do contrato.

## Como rodar o ambiente

```bash
git clone https://github.com/Ralima0711/oficina-infra-fase4.git
cd oficina-infra-fase4 && docker compose up -d
```

Redis na **6379**, MongoDB da Execução na **27018**, RabbitMQ na **5672** (painel em 15672).

## Como rodar só este serviço

O `docker-compose.yml` deste repositório sobe a API, o worker de mensagens, o Redis, o MongoDB e o RabbitMQ da Execução:

```bash
docker compose up -d --build
```

| Serviço | Porta |
|---|---|
| API | 8080 |
| Redis | 6379 |
| MongoDB | 27018 |
| RabbitMQ (AMQP / painel) | 5672 / 15672 (guest / guest) |

Use este compose **ou** o do `oficina-infra-fase4`, não os dois ao mesmo tempo: ambos publicam o Redis na 6379, o Mongo da Execução na 27018 e o RabbitMQ na 5672. Os índices do Mongo são criados na subida. A topologia do RabbitMQ (exchanges, filas, DLQs) vem de `docker/rabbitmq/definitions.json`.

> **`docker/rabbitmq/` é uma cópia de desenvolvimento, não a fonte.** É um subconjunto do `rabbitmq/definitions.json` do `oficina-infra-fase4`, que é a fonte da verdade da topologia, e existe só para este compose subir sozinho. Ao mudar nome de fila, exchange ou binding, mude primeiro no infra e depois aqui. Fila com nome divergente não quebra teste nem deploy: o serviço sobe, declara a fila errada e consome uma fila que ninguém publica. Por isso a conferência contra o infra (`k8s/scripts/confere-filas.py`) deve rodar no CI deste serviço.

- Swagger UI: http://localhost:8080/api/documentation
- Saúde: http://localhost:8080/health (também em `/api/health`)

Na primeira vez, instale as dependências (o código é montado como volume):

```bash
docker compose run --rm --no-deps app composer install
```

## Endpoints

| Método | Rota | O que faz |
|---|---|---|
| GET | `/api/execucao/fila` | lista a fila na ordem de atendimento (prioridade, depois chegada) |
| POST | `/api/execucao/fila` | enfileira uma OS aprovada (`{"osId": "<uuid>", "prioridade": 1}`; prioridade opcional, de 1 a 5) |
| POST | `/api/execucao/fila/proxima` | mecânico puxa a próxima OS e inicia a execução (`{"mecanicoId": "<uuid>"}`) |
| DELETE | `/api/execucao/fila/{osId}` | remove a OS da fila |
| GET | `/api/execucao/os/{osId}/timeline` | histórico da OS |
| POST | `/api/execucao/os/{osId}/diagnostico` | inicia o diagnóstico |
| POST | `/api/execucao/os/{osId}/notas` | anotação do mecânico |
| POST | `/api/execucao/os/{osId}/finalizar` | encerra o reparo e libera o mecânico |
| GET | `/api/execucao/metricas/tempo-medio` | tempo médio por status |

Erros de negócio: `409` (OS já na fila, mecânico ocupado, OS de outro mecânico, transição já registrada), `404` (fila vazia, OS fora da fila ou sem eventos), `422` (transição inválida, id que não é UUID ou prioridade fora da faixa).

> O mecânico vem no corpo da requisição enquanto a autenticação entre serviços não está definida.

## Testes

```bash
# Unitários e de API (repositórios em memória) + integração com Redis, Mongo e RabbitMQ reais
docker compose exec app vendor/bin/phpunit

# Teste de contrato: valida o que a Execução consome e publica contra o docs/asyncapi.yaml
docker compose exec app vendor/bin/phpunit --testsuite Contract

# Só a integração (usa redis db 15, o banco execucao_test e filas de teste com sufixo .test)
docker compose exec app vendor/bin/phpunit --testsuite Integration
```

Fora do compose, os testes de integração são pulados quando Redis, Mongo ou RabbitMQ não estão acessíveis.

O **teste de contrato** (`tests/Contract`) usa o mesmo `docs/asyncapi.yaml` do OS Service e do Billing: se o contrato mudar, ou se a Execução deixar de cumprir algo (um campo, uma fila, um comando válido que o consumidor recusaria), ele quebra. As lacunas conhecidas (`execucao.falhou` e o consumo de `execucao.cancelar-execucao`) ficam registradas no próprio teste. O helper `tests/Support/ContratoAsyncApi.php` pode ser copiado para os outros serviços.

## Documentação

| Documento | O que é |
|---|---|
| `docs/contrato-eventos-fase4.md` | Contrato v1.0 — **em caso de divergência, ele manda** |
| `docs/adrs-fase4.md` | ADR-0007 a ADR-0012 |
| `docs/asyncapi.yaml` | Contrato AsyncAPI dos eventos |
| `docs/guia-testes-cobertura.md` | Pirâmide de testes e meta de 80% |
| `docs/contrato-observabilidade.md` | Tracing e logs: propagação do `traceparent` e formato do log |

## Regras do repositório

- `main` protegida: PR obrigatório com 1 aprovação
- **PR sem teste não é aprovado**
- Meta de cobertura: **80%**, com quality gate no SonarQube

---

*Grupo 77 · FIAP SOAT Pós-Tech · Fase 4*
