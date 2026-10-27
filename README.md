# oficina-execucao-service

Microsserviço de Execução e Produção, com fila de execução e timeline de reparo — Tech Challenge SOAT Fase 4.

| O quê | Onde | Como |
|---|---|---|
| Fila de execução | **Redis** | Sorted Set (FIFO) + trava por mecânico (`SET`), operações atômicas via Lua |
| Timeline de diagnóstico e reparo | **MongoDB** | um documento por evento, só inserção (`timeline_os`) |
| API | Laravel 12 / PHP 8.2 | REST + Swagger em `/api/documentation` |
| Tracing e logs | OpenTelemetry | `traceparent` em REST e mensagens; contrato em [docs/contrato-observabilidade.md](docs/contrato-observabilidade.md) |

## Como rodar

```bash
docker compose up -d --build
```

Sobe `app` (porta 8080), `mongo` e `redis`. Este serviço não usa SQL. Os índices do Mongo são criados na subida.

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
