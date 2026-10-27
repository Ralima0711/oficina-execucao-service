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

## Mensageria

**Consome comandos** de `execucao.enviar-para-execucao` e `execucao.cancelar-execucao`.

**Publica eventos** no exchange `oficina.eventos`: `execucao.iniciada`,
`execucao.concluida` e `execucao.falhou`.

Toda falha publica evento. Falhar em silêncio trava a Saga — o orquestrador fica
esperando para sempre.

## Como rodar o ambiente

```bash
git clone https://github.com/Ralima0711/oficina-infra-fase4.git
cd oficina-infra-fase4 && docker compose up -d
```

Redis na **6379**, MongoDB da Execução na **27018**.

## Documentação

| Documento | O que é |
|---|---|
| `docs/contrato-eventos-fase4.md` | Contrato v1.0 — **em caso de divergência, ele manda** |
| `docs/adrs-fase4.md` | ADR-0007 a ADR-0012 |
| `docs/asyncapi.yaml` | Contrato AsyncAPI dos eventos |
| `docs/guia-testes-cobertura.md` | Pirâmide de testes e meta de 80% |

## Regras do repositório

- `main` protegida: PR obrigatório com 1 aprovação
- **PR sem teste não é aprovado**
- Meta de cobertura: **80%**, com quality gate no SonarQube

---

*Grupo 77 · FIAP SOAT Pós-Tech · Fase 4*
