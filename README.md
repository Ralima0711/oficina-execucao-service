# oficina-execucao-service

Microsserviço de **Execução e Produção** do Tech Challenge SOAT Fase 4 — Grupo 77.

## Responsabilidades

| Operação | Observação |
|---|---|
| Enfileirar OS para execução | Consome comando do orquestrador |
| Iniciar diagnóstico | Publica evento |
| Registrar apontamentos do reparo | Timeline local |
| Concluir execução | Publica evento, e a Saga finaliza a OS |
| Informar falha do reparo | Publica `execucao.falhou`, e a Saga compensa |
| Cancelar execução | Compensação: consome `execucao.cancelar-execucao` |

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

1. O orquestrador publica `EnviarParaExecucao` em `execucao.enviar-para-execucao`; o worker (`php artisan execucao:consumir`, que consome as duas filas de comando) valida o envelope, descarta `messageId` repetido, e coloca a OS na fila de execução guardando o `sagaId`, o `messageId` do comando e o `traceparent`.
2. O mecânico puxa a OS (`POST /api/execucao/fila/proxima`) e a Execução publica `execucao.iniciada`.
3. Ao finalizar o reparo (`POST /api/execucao/os/{osId}/finalizar`) ela publica `execucao.concluida`.

**Compensação** (comando `execucao.cancelar-execucao`, `CancelarExecucao` no contrato v1.1). O que ele faz depende de onde a OS está:

| Situação da OS | O que acontece |
|---|---|
| Na fila, ninguém puxou | Sai da fila (atômico), o `execucaoId` é liberado e a timeline ganha `EXECUCAO_FALHOU`. Publica `execucao.falhou` com motivo `cancelada` e sem `mecanicoId` |
| Mecânico já puxou | Encerra o reparo, libera o mecânico, grava `EXECUCAO_FALHOU` na timeline e publica `execucao.falhou` (motivo `cancelada`) |
| Já concluída | **Não desfaz.** Responde sucesso, não publica evento e registra a tentativa como nota na timeline (o que se desfaz é o dinheiro, pelo estorno) |
| Já falhou, ou nunca chegou à fila | Pulada, não é erro: nenhum efeito, nenhum evento |

O comando é idempotente: o mesmo `messageId` é descartado e um novo `CancelarExecucao` da mesma OS tem o efeito uma vez só. O evento sai no trace do cancelamento, com o `sagaId` dele e `causationId` igual ao `messageId` do `CancelarExecucao`. `FALHOU` é terminal: um `EnviarParaExecucao` atrasado não recoloca a OS na fila.

**Falha do reparo.** O mecânico informa `POST /api/execucao/os/{osId}/falhar` (`mecanicoId` e `motivo`): a OS vai de `EM_EXECUCAO` para `FALHOU`, o mecânico é liberado e a Execução publica `execucao.falhou` com o motivo. É isto que dá início à compensação da saga; o orquestrador então manda `CancelarExecucao`, que encontra a OS já encerrada e é pulado.

Os eventos voltam no **mesmo trace** do comando, com o mesmo `sagaId` e `causationId` igual ao `messageId` do comando, mesmo com o mecânico agindo em outra requisição (ver o contrato de observabilidade no `oficina-infra-fase4`). Mensagem fora do contrato ou que falha ao ser tratada vai para a DLQ da sua fila (`dlq.execucao.enviar-para-execucao`, `dlq.execucao.cancelar-execucao`).

> **Limites conhecidos:** a publicação não é transacional (o evento sai depois de gravar a transição; se o broker cair nesse intervalo a API responde 503 e a transição fica gravada sem o evento). Finalizar e cancelar a mesma OS exatamente ao mesmo tempo pode gravar os dois fins (o índice único é por tipo de evento, e a janela é de milissegundos). Uma OS enfileirada direto por `POST /api/execucao/fila` não tem saga e não publica evento. Comando fora do contrato (corpo inválido) vai para a DLQ da fila dele sem evento de falha, embora a regra 6 da Parte 5 do contrato peça o evento para campo essencial inválido: fica como pendência a alinhar com o orquestrador, porque o payload de `execucao.falhou` exige um `osId` válido.
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

> **`docker/rabbitmq/` é uma cópia de desenvolvimento, não a fonte.** É um subconjunto do `rabbitmq/definitions.json` do `oficina-infra-fase4`, que é a fonte da verdade da topologia, e existe só para este compose subir sozinho. Ao mudar nome de fila, exchange ou binding, mude primeiro no infra e depois aqui. Fila com nome divergente não quebra teste nem deploy: o serviço sobe, declara a fila errada e consome uma fila que ninguém publica. Por isso o CI deste serviço (`.github/workflows/confere-topologia.yml`) baixa o `oficina-infra-fase4` e roda `scripts/confere-topologia.py`, que confere duas coisas: que a cópia é subconjunto exato da fonte (exchanges, filas com TTL e dead-letter, bindings) e que as filas e o exchange que o serviço usa (`config/rabbitmq.php`) existem na fonte. A fonte pode ter mais do que a cópia (as filas do Billing e do OS Service); o contrário é divergência. Para rodar na máquina:

```bash
python3 scripts/confere-topologia.py --fonte ../oficina-infra-fase4/rabbitmq/definitions.json
python3 -m unittest discover -s scripts # testes do próprio script
```

> O `k8s/scripts/confere-filas.py` do infra confere outra coisa: os ConfigMaps dos manifestos contra o `definitions.json` do próprio infra. Ele roda no CI do infra, não aqui.

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
| POST | `/api/execucao/os/{osId}/falhar` | informa que o reparo falhou (`{"mecanicoId": "<uuid>", "motivo": "..."}`), libera o mecânico e publica `execucao.falhou` |
| GET | `/api/execucao/metricas/tempo-medio` | tempo médio por status |

Erros de negócio: `409` (OS já na fila, mecânico ocupado, OS de outro mecânico, transição já registrada), `404` (fila vazia, OS fora da fila ou sem eventos), `422` (transição inválida, id que não é UUID ou prioridade fora da faixa), `503` (a transição foi gravada, mas o evento não saiu para o broker).

> O mecânico vem no corpo da requisição enquanto a autenticação entre serviços não está definida.

## Testes

```bash
# Unitários e de API (repositórios em memória) + integração com Redis, Mongo e RabbitMQ reais
docker compose exec app vendor/bin/phpunit

# Teste de contrato: valida o que a Execução consome e publica contra o docs/asyncapi.yaml
docker compose exec app vendor/bin/phpunit --testsuite Contract

# Só a integração (usa redis db 15, o banco execucao_test e filas de teste com sufixo .test)
docker compose exec app vendor/bin/phpunit --testsuite Integration

# BDD (topo da pirâmide): a Execução de ponta a ponta, com Gherkin em português
docker compose exec app composer bdd
```

Fora do compose, os testes de integração são pulados quando Redis, Mongo ou RabbitMQ não estão acessíveis.

O **BDD** (`features/execucao.feature`, rodado com Behat) descreve o que o orquestrador espera da Execução, em quatro cenários: o caminho feliz (`execucao.iniciada` e `execucao.concluida` na mesma saga e no mesmo trace do comando), a falha do reparo (`execucao.falhou` com o motivo, mecânico liberado), a compensação de uma OS ainda na fila e a compensação que não desfaz uma execução concluída. O comando entra pelo RabbitMQ e passa pelo consumidor de verdade, o mecânico age pela API e os eventos são lidos da fila que faz o papel do orquestrador. Usa filas `.bdd`, o Redis db 14 e o banco `execucao_bdd`, e não roda fora do compose. Os passos ficam em `tests/Bdd/ExecucaoContext.php`, e a configuração em `behat.php`.

O **teste de contrato** (`tests/Contract`) usa o mesmo `docs/asyncapi.yaml` do OS Service e do Billing: se o contrato mudar, ou se a Execução deixar de cumprir algo (um campo, uma fila, um comando válido que o consumidor recusaria), ele quebra. Ele também falha se o contrato passar a atribuir à Execução uma mensagem que ela não trata. O helper `tests/Support/ContratoAsyncApi.php` pode ser copiado para os outros serviços.

## Documentação

| Documento | O que é |
|---|---|
| `docs/contrato-eventos-fase4.md` | Contrato v1.1 — **em caso de divergência, ele manda** |
| `docs/adrs-fase4.md` | ADR-0007 a ADR-0014 |
| `docs/asyncapi.yaml` | Contrato AsyncAPI dos eventos |
| `docs/guia-testes-cobertura.md` | Pirâmide de testes e meta de 80% |
| `oficina-infra-fase4` → `docs/contrato-observabilidade.md` | Tracing e logs: propagação do `traceparent` e formato do log. Vale para os três serviços; este repo é a implementação de referência |

## Regras do repositório

- `main` protegida: PR obrigatório com 1 aprovação
- **PR sem teste não é aprovado**
- Meta de cobertura: **80%**, com quality gate no SonarQube

---

*Grupo 77 · FIAP SOAT Pós-Tech · Fase 4*
