#!/usr/bin/env python3
"""Confere a topologia do RabbitMQ deste servico contra a fonte, o oficina-infra-fase4.

O contrato de eventos (v1.1, "Fonte unica da topologia") manda a topologia viver em um
lugar so: rabbitmq/definitions.json no oficina-infra-fase4. Este repositorio mantem uma
copia parcial em docker/rabbitmq/definitions.json so para o compose subir sozinho, e o
pipeline confere essa copia contra a fonte.

O erro que isto pega nao aparece em teste nem em deploy: o servico sobe, conecta, declara
uma fila com nome errado e consome uma fila que ninguem publica. A saga para no meio sem
log de erro.

Duas conferencias:

  1. a copia local e um SUBCONJUNTO exato da fonte: todo exchange, fila (com argumentos de
     TTL e dead-letter) e binding da copia existe igual na fonte;
  2. as filas e o exchange que o servico realmente usa (os padroes de config/rabbitmq.php)
     existem na fonte.

A fonte pode ter mais coisa que a copia (as filas do Billing e do OS Service); o inverso
e divergencia.

uso:
    python3 scripts/confere-topologia.py --fonte ../oficina-infra-fase4/rabbitmq/definitions.json
"""
import argparse
import json
import pathlib
import re
import sys

RAIZ = pathlib.Path(__file__).resolve().parent.parent
COPIA = RAIZ / "docker" / "rabbitmq" / "definitions.json"
CONFIG = RAIZ / "config" / "rabbitmq.php"

# 'fila_enviar' => env('RABBITMQ_FILA_ENVIAR', 'execucao.enviar-para-execucao'),
CONFIG_NOME = re.compile(r"""'(fila_\w+|exchange_\w+)'\s*=>\s*env\(\s*'[A-Z_]+'\s*,\s*'([^']+)'\s*\)""")


def canonico(valor) -> str:
    return json.dumps(valor, sort_keys=True)


def exchanges(topologia: dict) -> dict:
    return {
        e["name"]: (e.get("type"), e.get("durable"), e.get("auto_delete"), e.get("internal"), canonico(e.get("arguments", {})))
        for e in topologia.get("exchanges", [])
    }


def filas(topologia: dict) -> dict:
    return {
        q["name"]: (q.get("durable"), q.get("auto_delete"), canonico(q.get("arguments", {})))
        for q in topologia.get("queues", [])
    }


def bindings(topologia: dict) -> set:
    return {
        (b["source"], b["destination"], b.get("destination_type"), b["routing_key"], canonico(b.get("arguments", {})))
        for b in topologia.get("bindings", [])
    }


def nomes_usados_pelo_servico(texto_config: str) -> dict:
    """Nome da config -> nome da fila ou exchange padrao, ex.: fila_enviar -> execucao.enviar-para-execucao."""
    return dict(CONFIG_NOME.findall(texto_config))


def confere(copia: dict, fonte: dict, usados: dict) -> list:
    problemas = []

    for nome, atributos in sorted(exchanges(copia).items()):
        na_fonte = exchanges(fonte)
        if nome not in na_fonte:
            problemas.append(f"exchange '{nome}' esta na copia e nao existe na fonte")
        elif na_fonte[nome] != atributos:
            problemas.append(f"exchange '{nome}' tem atributos diferentes da fonte (copia {atributos}, fonte {na_fonte[nome]})")

    for nome, atributos in sorted(filas(copia).items()):
        na_fonte = filas(fonte)
        if nome not in na_fonte:
            problemas.append(f"fila '{nome}' esta na copia e nao existe na fonte")
        elif na_fonte[nome] != atributos:
            problemas.append(f"fila '{nome}' tem argumentos diferentes da fonte (copia {atributos[2]}, fonte {na_fonte[nome][2]})")

    for origem, destino, tipo, chave, _ in sorted(bindings(copia) - bindings(fonte)):
        problemas.append(f"binding {origem} -[{chave}]-> {destino} esta na copia e nao existe na fonte")

    na_fonte_filas = filas(fonte)
    na_fonte_exchanges = exchanges(fonte)
    for chave, nome in sorted(usados.items()):
        existe = na_fonte_filas if chave.startswith("fila_") else na_fonte_exchanges
        if nome not in existe:
            tipo = "fila" if chave.startswith("fila_") else "exchange"
            problemas.append(f"{tipo} '{nome}' (config/rabbitmq.php, '{chave}') nao existe na fonte")

    return problemas


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--fonte", required=True, type=pathlib.Path, help="rabbitmq/definitions.json do oficina-infra-fase4")
    parser.add_argument("--copia", type=pathlib.Path, default=COPIA, help="a copia local (padrao: docker/rabbitmq/definitions.json)")
    parser.add_argument("--config", type=pathlib.Path, default=CONFIG, help="config/rabbitmq.php")
    args = parser.parse_args(argv)

    try:
        fonte = json.loads(args.fonte.read_text(encoding="utf-8"))
        copia = json.loads(args.copia.read_text(encoding="utf-8"))
        usados = nomes_usados_pelo_servico(args.config.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as erro:
        print(f"ERRO: {erro}", file=sys.stderr)
        return 2

    if not usados:
        # Sem isso a segunda conferencia passaria vazia: o config mudou de forma e o script nao sabe ler.
        print(f"ERRO: nao achei nenhuma fila_* ou exchange_* em {args.config}", file=sys.stderr)
        return 2

    problemas = confere(copia, fonte, usados)

    print(f"copia: {len(filas(copia))} filas, {len(exchanges(copia))} exchanges  |  "
          f"fonte: {len(filas(fonte))} filas, {len(exchanges(fonte))} exchanges  |  usados pelo servico: {len(usados)}")

    if problemas:
        print()
        for problema in problemas:
            print(f"  DIVERGENCIA: {problema}")
        print()
        print("A fonte e o infra. Mude la primeiro e copie para docker/rabbitmq/ depois.")
        return 1

    print("ok: a copia e subconjunto da fonte e o servico so usa o que a fonte declara")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
