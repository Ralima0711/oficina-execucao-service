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

- **Redis:** Sorted Set (`execucao:fila`) para a ordem e uma trava por mecânico (`execucao:mecanico:{id}:atual`). Enfileirar, puxar, devolver e concluir são scripts Lua atômicos.
- **MongoDB:** coleção `timeline_os`, um documento por evento, só inserção, com o `traceId` de cada evento.

## Mensageria

**Consome comandos** de `execucao.enviar-para-execucao` e `execucao.cancelar-execucao`.

**Publica eventos** no exchange `oficina.eventos`: `execucao.iniciada`,
`execucao.concluida` e `execucao.falhou`.

Toda falha publica evento. Falhar em silêncio trava a Saga — o orquestrador fica
esperando para sempre.

> **Estado atual:** a mensageria ainda não está implementada neste serviço; a fila é alimentada por `POST /api/execucao/fila`. A fila hoje é FIFO: a **prioridade** do contrato ainda não foi implementada. Os identificadores (`osId`, `mecanicoId`) são inteiros, e o contrato prevê UUID.

## Como rodar o ambiente

```bash
git clone https://github.com/Ralima0711/oficina-infra-fase4.git
cd oficina-infra-fase4 && docker compose up -d
```

Redis na **6379**, MongoDB da Execução na **27018**.

## Como rodar só este serviço

O `docker-compose.yml` deste repositório sobe a API junto com o Redis e o MongoDB da Execução:

```bash
docker compose up -d --build
```

| Serviço | Porta |
|---|---|
| API | 8080 |
| Redis | 6379 |
| MongoDB | 27018 |

Use este compose **ou** o do `oficina-infra-fase4`, não os dois ao mesmo tempo: ambos publicam o Redis na 6379 e o Mongo da Execução na 27018. Os índices do Mongo são criados na subida.

- Swagger UI: http://localhost:8080/api/documentation
- Saúde: http://localhost:8080/api/health

Na primeira vez, instale as dependências (o código é montado como volume):

```bash
docker compose run --rm --no-deps app composer install
```

## Endpoints

| Método | Rota | O que faz |
|---|---|---|
| GET | `/api/execucao/fila` | lista a fila em ordem de chegada |
| POST | `/api/execucao/fila` | enfileira uma OS aprovada (`{"osId": 123}`) |
| POST | `/api/execucao/fila/proxima` | mecânico puxa a próxima OS e inicia a execução (`{"mecanicoId": 7}`) |
| DELETE | `/api/execucao/fila/{osId}` | remove a OS da fila |
| GET | `/api/execucao/os/{osId}/timeline` | histórico da OS |
| POST | `/api/execucao/os/{osId}/diagnostico` | inicia o diagnóstico |
| POST | `/api/execucao/os/{osId}/notas` | anotação do mecânico |
| POST | `/api/execucao/os/{osId}/finalizar` | encerra o reparo e libera o mecânico |
| GET | `/api/execucao/metricas/tempo-medio` | tempo médio por status |

Erros de negócio: `409` (OS já na fila, mecânico ocupado, OS de outro mecânico), `404` (fila vazia, OS fora da fila ou sem eventos), `422` (transição inválida ou validação).

> O mecânico vem no corpo da requisição enquanto a autenticação entre serviços não está definida.

## Testes

```bash
# Unitários e de API (repositórios em memória) + integração com Redis e Mongo reais
docker compose exec app vendor/bin/phpunit

# Só a integração (usa redis db 15 e o banco execucao_test)
docker compose exec app vendor/bin/phpunit --testsuite Integration
```

Fora do compose, os testes de integração são pulados quando Redis/Mongo não estão acessíveis.

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
