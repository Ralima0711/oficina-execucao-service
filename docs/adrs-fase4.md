# ADRs — Tech Challenge SOAT Fase 4 (Grupo 77)

> **ADR = Architecture Decision Record.** Registra uma decisão arquitetural **permanente**, com contexto, decisão e consequências. Enquanto o RFC discute, o ADR fixa.
> Formato por registro: Contexto · Decisão · Status · Consequências.
> Autora: Roberta (Tech Lead)
>
> **Numeração:** continua a sequência da Fase 3, que foi até ADR-0006. O sistema é o mesmo e a história das decisões é contínua — reiniciar em 0001 criaria dois ADR-0001 com conteúdos diferentes.

Índice:
- ADR-0007 — Decomposição em três microsserviços
- ADR-0008 — Saga orquestrada, com o orquestrador no OS Service
- ADR-0009 — Persistência poliglota e CQRS no OS Service
- ADR-0010 — RabbitMQ como message broker
- ADR-0011 — Contramedidas para a perda de isolamento na Saga
- ADR-0012 — Descoberta de serviços pelo Kubernetes, sem service registry dedicado

---

## ADR-0007 — Decomposição em três microsserviços

**Status:** Aceito · **Data:** 20/10/2026

### Contexto
A Fase 3 entregou uma aplicação única (`oficina-mecanica-api`) com todo o domínio da oficina. A Fase 4 exige refatorar em no mínimo três microsserviços independentes, cada um com repositório, infraestrutura e banco próprios. A Aula 1 de Estrutura de Microsserviços ensina a decompor por **capacidade de negócio**, montando uma tabela de Serviço × Operação × Integração.

### Decisão
Três serviços, recortados pelas três capacidades que o enunciado nomeia:

| Serviço | Capacidade | Repositório |
|---|---|---|
| **OS Service** | Atendimento: abertura, status e histórico da ordem de serviço. Hospeda o orquestrador da Saga | `oficina-os-service` |
| **Billing Service** | Orçamento e pagamento, com integração ao Mercado Pago | `oficina-billing-service` |
| **Execução Service** | Fila de execução, diagnóstico e apontamento de reparo | `oficina-execucao-service` |

Cliente e veículo ficam no OS Service, não em um quarto serviço: são dados do atendimento e não têm ciclo de vida próprio que justifique o custo de mais um repositório, pipeline e banco.

A infraestrutura compartilhada — RabbitMQ, manifests e observabilidade — vive em `oficina-infra-fase4`, que não é microsserviço.

### Consequências
- (+) Recorte espelha o projeto de referência da disciplina de SAGA (Pedido / Cobrança / Entrega), o que facilita a defesa.
- (+) O OS Service reaproveita o domínio já testado da Fase 3; só Billing e Execução nascem do zero.
- (−) Dados do cliente ficam acopláveis ao OS Service; se um dia houver portal do cliente, isso vira candidato a extração.
- (−) Três pipelines, três quality gates e três metas de 80% de cobertura para sustentar.

---

## ADR-0008 — Saga orquestrada, com o orquestrador no OS Service

**Status:** Aceito · **Data:** 20/10/2026

### Contexto
Com banco por serviço, não existe transação ACID atravessando os três. O enunciado exige o padrão Saga com rollback e compensação. A Aula 2 de SAGA apresenta as duas estratégias — coreografia e orquestração — sem eleger uma.

### Decisão
**Saga orquestrada**, com o objeto orquestrador implementado **dentro do OS Service**, escrito à mão.

O orquestrador envia **comandos** por filas point-to-point e recebe **eventos** por um exchange topic. A aula usa Apache Camel, que é Java; o projeto é PHP, e a própria aula afirma que *"nada impede que o orquestrador de uma SAGA seja implementado manualmente"*.

### Consequências
- (+) Dependências simplificadas: as requisições vão só do orquestrador para os participantes, sem ciclos. É o pró que a Aula 2 destaca.
- (+) A lógica da saga fica centralizada num lugar só, inspecionável — o que o vídeo precisa demonstrar.
- (+) Os participantes desconhecem a saga de que fazem parte; o acoplamento fica no orquestrador.
- (−) **Orquestrador complexo:** se acumular regra de negócio demais, vira ponto de manutenção difícil. Mitigação: o orquestrador coordena, não decide — regra de negócio fica nos participantes.
- (−) **Ponto único de falha:** o OS Service fora do ar interrompe toda a saga. Mitigação: HPA com mínimo de 2 pods, estado da saga persistido no Postgres, e retomada a partir do estado persistido.

---

## ADR-0009 — Persistência poliglota e CQRS no OS Service

**Status:** Aceito · **Data:** 20/10/2026

### Contexto
O enunciado exige pelo menos um banco relacional e um não relacional, com banco próprio por serviço. A fase dedica duas disciplinas inteiras a bancos de dados, e a Aula 5 de Estrutura de Microsserviços ensina CQRS com read model alimentado por eventos.

### Decisão

| Serviço | Banco | Papel |
|---|---|---|
| OS Service | **PostgreSQL** | Escrita, estado da OS e estado da Saga |
| OS Service | **MongoDB** | Read model de status e histórico (CQRS) |
| Billing Service | **PostgreSQL** | Orçamento e pagamento exigem integridade relacional |
| Execução Service | **Redis** | Fila de execução com prioridade |
| Execução Service | **MongoDB** | Timeline de diagnóstico e reparo |

O enunciado lista *"consulta de status e histórico"* como responsabilidade do OS Service — é exatamente o caso de uso do read model separado.

Cassandra e Neo4j ficam de fora. É escolha consciente: a disciplina de grafos não aparece no projeto, e em 33 dias um quarto banco com modelagem nova é risco sem retorno proporcional.

### Consequências
- (+) Cobre relacional, documento e chave-valor — as aulas 1, 2, 3 e 7 de Data Engineering mais a aula de CQRS.
- (+) Cada forma de dado no banco que a serve: fila é chave-valor, timeline é documento, dinheiro é relacional.
- (−) Cinco instâncias de banco no ambiente local; o `docker-compose` fica pesado.
- (−) O read model é eventualmente consistente. A Aula 5 ensina devolver o ID do command e compará-lo na query para o cliente saber que lê dado atrasado; adotamos isso.
- (−) A disciplina de grafos não é exercitada no projeto.

---

## ADR-0010 — RabbitMQ como message broker

**Status:** Aceito · **Data:** 20/10/2026

### Contexto
O enunciado pede mensageria assíncrona e cita RabbitMQ, Kafka e SQS sem eleger. O conteúdo da fase aponta para lugares diferentes: a Aula 2 de Estrutura de Microsserviços cita Kafka como exemplo teórico; a Aula 5 implementa com SQS em LocalStack; e as Aulas 4 e 5 de SAGA implementam com **RabbitMQ**.

### Decisão
**RabbitMQ**, na imagem `rabbitmq:3.12-management`, como no hands-on da disciplina de SAGA — que é o conteúdo mais próximo do coração desta entrega.

A topologia (filas, exchanges, bindings, DLQs e o TTL de aprovação) é declarada em `rabbitmq/definitions.json`, versionado no repositório e carregado no boot. Fila não se cria pelo painel.

Comandos usam filas point-to-point; eventos usam o exchange topic `oficina.eventos` — os dois tipos de canal que a Aula 2 ensina.

### Consequências
- (+) Alinhado ao conteúdo que implementa a saga, que é o que será avaliado.
- (+) Muito mais leve que Kafka no cluster; sobe num container.
- (+) O TTL de fila do RabbitMQ resolve o timeout de aprovação sem job agendado.
- (+) Topologia versionada: o ambiente de um reproduz o do outro.
- (−) Menos throughput e sem retenção longa, comparado a Kafka. Irrelevante neste volume.
- (−) Em produção real exigiria cluster e política de filas espelhadas, que não estão no escopo.

---

## ADR-0011 — Contramedidas para a perda de isolamento na Saga

**Status:** Aceito · **Data:** 20/10/2026

### Contexto
A Saga troca a transação ACID por uma sequência de transações locais. Perde-se o **I** de isolamento: entre dois passos, outra operação pode ler ou escrever o mesmo dado. A Aula 3 da disciplina de SAGA trata disso e ensina duas contramedidas nominadas.

### Decisão
Adotar as duas contramedidas da aula, cada uma onde cabe:

| Contramedida | Onde aplicamos | Anomalia evitada |
|---|---|---|
| **Pessimistic View** — reordenar os passos para o dado só ser lido depois de atualizado | O pagamento é cobrado **antes** da execução. Nenhum serviço é prestado contra um estado que ainda pode mudar | Dirty read |
| **Reread Value** — reler antes de atualizar; se mudou desde a primeira leitura, abortar | Transição de estado da OS. O agregado tem coluna de versão e a transição falha se a versão mudou | Lost update |

Complementarmente, a OS é tratada como **Aggregate** (Aula 2 de Estrutura de Microsserviços Parte II), com lock otimista na transição de estado, e todo consumidor é **idempotent event handler**, descartando `messageId` já processado.

Acrescentamos o estado `CANCELADA`, que não existia na Fase 3 — sem ele a compensação não teria estado terminal.

### Consequências
- (+) A perda de isolamento é tratada explicitamente, com os nomes que a disciplina usa.
- (+) A ordem dos passos passa a ser uma decisão registrada, não um acidente.
- (−) Cobrar antes de executar é menos realista para uma oficina de verdade; é trade-off assumido em favor da consistência e da demonstrabilidade.
- (−) O lock otimista pode gerar retentativa sob concorrência; o consumidor precisa tratar e reenfileirar.

---

## ADR-0012 — Descoberta de serviços pelo Kubernetes, sem service registry dedicado

**Status:** Aceito · **Data:** 20/10/2026

### Contexto
A Aula 2 de Estrutura de Microsserviços apresenta o padrão Descoberta de Serviços e cita Consul, Etcd e Eureka. Com três serviços escalando horizontalmente, a questão é legítima: como um serviço encontra o outro sem endereço fixo.

### Decisão
Não adotar service registry dedicado. Usar os **Services do Kubernetes**, que já fazem *server-side discovery* via DNS interno do cluster e balanceiam entre os pods. O Kong, herdado da Fase 3, complementa como ponto único de entrada.

A maior parte da comunicação entre serviços é assíncrona por RabbitMQ, onde a descoberta nem se coloca — o produtor conhece a fila, não o consumidor.

Service mesh (Istio, hands-on da Aula 5 de Parte II) também fica fora: em 33 dias, com três serviços, saga e meta de 80% de cobertura, é risco sem retorno proporcional.

### Consequências
- (+) Zero componente novo para operar; o cluster já resolve.
- (+) O padrão está atendido, só que pela plataforma em vez de por biblioteca.
- (−) Sem as funcionalidades avançadas de um registry (health checks customizados, metadados de versão).
- (−) Amarra a solução ao Kubernetes; fora dele, a decisão teria que ser revista.

---

*Grupo 77 · FIAP SOAT Pós-Tech · Fase 4*
