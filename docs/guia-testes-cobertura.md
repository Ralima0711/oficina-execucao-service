# Guia de testes e cobertura — Fase 4

**Grupo 77 · meta: 80% por serviço, com quality gate no SonarQube**

> A Aula 3 de Estrutura de Microsserviços Parte II define quatro níveis de teste e a pirâmide
> que os organiza. Este guia traduz isso para os nossos três serviços, para que os 80%
> saiam distribuídos e não concentrados em teste unitário.

---

## Por que a distribuição importa

Chegar a 80% só com teste unitário é possível e é uma armadilha. O número fecha, o Sonar
passa, e nenhum teste exercita a saga — que é justamente o que a fase está avaliando.
A cobertura vira métrica e deixa de ser garantia.

A pirâmide resolve isso: muitos testes baratos na base, poucos caros no topo, e cada nível
pegando uma classe de erro que o nível de baixo não pega.

---

## Os quatro níveis, no nosso projeto

### Unitário — a base

Testa uma classe isolada, com **mock** ou **stub** nas dependências. Sem banco, sem fila,
sem rede. É o nível mais rápido e onde deve estar o grosso dos testes.

| Serviço | O que exercitar |
|---|---|
| OS Service | Máquina de estados: cada transição legal, cada transição ilegal, as guardas |
| Billing | Cálculo do orçamento, soma de itens, validade de 7 dias |
| Execução | Priorização da fila, montagem da timeline |

O que o unitário **não** pega: erro de mapeamento objeto-banco, payload que não bate com o
contrato, mensagem que não chega.

### Integração — o meio

Testa o serviço contra as suas dependências reais, subidas em container: Postgres, Mongo,
Redis, RabbitMQ. É aqui que aparece o que o mock escondeu.

| O que exercitar |
|---|
| Repositórios contra o banco de verdade — migração, constraint, lock otimista |
| Publicar numa fila e confirmar que a mensagem chegou com o envelope correto |
| Consumir a mesma mensagem duas vezes e provar que o efeito aconteceu uma só |

O `docker-compose.yml` do `oficina-infra-fase4` já sobe tudo que esse nível precisa.

### Componente — o serviço inteiro, sozinho

Testa o serviço de ponta a ponta pela sua própria API, com as **fronteiras mockadas**.
O Billing responde sem o Mercado Pago real; o OS avança a saga sem o Billing real.

É o nível que prova que o serviço cumpre o contrato dele sem depender de ninguém —
e é o que permite cada um trabalhar em paralelo.

### Ponta a ponta — o topo

Os três serviços de pé, a saga atravessando. Poucos testes, caros e lentos.

**O nosso E2E é o BDD**, em Gherkin, rodado com Behat. Mínimo exigido pelo enunciado:
um fluxo completo. O que vale a pena ter:

```gherkin
Funcionalidade: Ciclo de vida da ordem de serviço

  Cenário: Caminho feliz
    Dado que um cliente abriu uma ordem de serviço
    Quando o orçamento for gerado e aprovado
    E o pagamento for confirmado
    E a execução for concluída
    Então a ordem de serviço deve estar FINALIZADA

  Cenário: Cliente recusa o orçamento
    Dado que uma ordem de serviço está AGUARDANDO_APROVACAO
    Quando o cliente recusar o orçamento
    Então o orçamento deve ser anulado
    E a ordem de serviço deve estar CANCELADA

  Cenário: Pagamento recusado
    Dado que uma ordem de serviço está AGUARDANDO_PAGAMENTO
    Quando o pagamento for recusado
    Então o orçamento deve ser anulado
    E a ordem de serviço deve estar CANCELADA
    E nenhuma execução deve ter sido iniciada
```

O terceiro cenário é o mais valioso dos três: ele prova a **Pessimistic View** — que não se
executa serviço sem pagamento confirmado — que é a decisão registrada no ADR-0011.

---

## Distribuição sugerida

Não é regra rígida, é ponto de partida. O que importa é não ter um nível vazio.

| Nível | Fatia da cobertura | Quantidade |
|---|---|---|
| Unitário | ~60% | Muitos |
| Integração | ~25% | Vários |
| Componente | ~10% | Poucos |
| Ponta a ponta (BDD) | ~5% | 3 cenários |

---

## O quality gate

A Aula 4 destaca três métricas, e o gate do Sonar se configura exatamente nelas:

| Métrica | O que olhar |
|---|---|
| **Cobertura de testes** | ≥ 80% — o número exigido pelo enunciado |
| **Complexidade ciclomática** | Método muito ramificado é candidato a refatoração, e costuma ser onde o bug mora |
| **Débito técnico** | Custo estimado de adiar a correção |

O gate roda no pipeline e **bloqueia o merge**. É isso que faz os 80% serem reais em vez de
aspiracionais.

---

## A regra que sustenta tudo

**PR sem teste não é aprovado.** Não é burocracia: é a única forma de os 80% chegarem no
bloco 3 sem um mutirão em cima da semana da gravação.

Quando faltar tempo, o teste que se escreve primeiro é o da **transição de estado** e o do
**consumidor idempotente**. São os dois pontos onde um erro não aparece em desenvolvimento
e aparece na demonstração.

---

*Grupo 77 · FIAP SOAT Pós-Tech · Fase 4*
