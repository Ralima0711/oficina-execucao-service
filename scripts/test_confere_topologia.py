"""Testes do scripts/confere-topologia.py: roda com `python3 -m unittest discover -s scripts`."""
import contextlib
import importlib.util
import io
import json
import pathlib
import tempfile
import unittest

CAMINHO = pathlib.Path(__file__).resolve().parent / "confere-topologia.py"
ESPEC = importlib.util.spec_from_file_location("confere_topologia", CAMINHO)
confere_topologia = importlib.util.module_from_spec(ESPEC)
ESPEC.loader.exec_module(confere_topologia)

CONFIG_PHP = """<?php
return [
    'exchange_eventos'   => env('RABBITMQ_EXCHANGE_EVENTOS', 'oficina.eventos'),
    'fila_enviar'        => env('RABBITMQ_FILA_ENVIAR', 'execucao.enviar-para-execucao'),
    'fila_cancelar'      => env('RABBITMQ_FILA_CANCELAR', 'execucao.cancelar-execucao'),
    'prefetch' => (int) env('RABBITMQ_PREFETCH', 1),
];
"""


def fila(nome, dlq=None):
    argumentos = {"x-dead-letter-exchange": "oficina.dlx", "x-dead-letter-routing-key": dlq} if dlq else {}
    return {"name": nome, "vhost": "/", "durable": True, "auto_delete": False, "arguments": argumentos}


def exchange(nome, tipo):
    return {"name": nome, "vhost": "/", "type": tipo, "durable": True, "auto_delete": False, "internal": False, "arguments": {}}


def binding(origem, destino, chave):
    return {"source": origem, "vhost": "/", "destination": destino, "destination_type": "queue", "routing_key": chave, "arguments": {}}


def fonte():
    """A fonte tem a topologia do servico e a dos outros (o Billing)."""
    return {
        "exchanges": [exchange("oficina.eventos", "topic"), exchange("oficina.dlx", "direct")],
        "queues": [
            fila("execucao.enviar-para-execucao", "dlq.execucao.enviar-para-execucao"),
            fila("execucao.cancelar-execucao", "dlq.execucao.cancelar-execucao"),
            fila("dlq.execucao.enviar-para-execucao"),
            fila("dlq.execucao.cancelar-execucao"),
            fila("billing.gerar-orcamento", "dlq.billing.gerar-orcamento"),
        ],
        "bindings": [
            binding("oficina.dlx", "dlq.execucao.enviar-para-execucao", "dlq.execucao.enviar-para-execucao"),
            binding("oficina.dlx", "dlq.execucao.cancelar-execucao", "dlq.execucao.cancelar-execucao"),
            binding("oficina.eventos", "os.saga.eventos", "execucao.*"),
        ],
    }


def copia():
    topologia = fonte()
    topologia["queues"] = [q for q in topologia["queues"] if not q["name"].startswith("billing.")]
    return topologia


USADOS = confere_topologia.nomes_usados_pelo_servico(CONFIG_PHP)


class ConfereTopologiaTest(unittest.TestCase):
    def test_copia_que_e_subconjunto_da_fonte_nao_tem_divergencia(self):
        self.assertEqual([], confere_topologia.confere(copia(), fonte(), USADOS))

    def test_le_os_nomes_que_o_servico_usa_da_config(self):
        self.assertEqual(
            {
                "exchange_eventos": "oficina.eventos",
                "fila_enviar": "execucao.enviar-para-execucao",
                "fila_cancelar": "execucao.cancelar-execucao",
            },
            USADOS,
        )

    def test_fila_so_na_copia_e_divergencia(self):
        c = copia()
        c["queues"].append(fila("execucao.nova-fila"))

        self.assertEqual(
            ["fila 'execucao.nova-fila' esta na copia e nao existe na fonte"],
            confere_topologia.confere(c, fonte(), USADOS),
        )

    def test_nome_de_fila_diferente_na_copia_e_divergencia(self):
        # O caso que o contrato descreve: a copia declara uma fila com o nome errado.
        c = copia()
        c["queues"][1]["name"] = "execucao.cancelar-execucoes"

        problemas = confere_topologia.confere(c, fonte(), USADOS)

        self.assertIn("fila 'execucao.cancelar-execucoes' esta na copia e nao existe na fonte", problemas)

    def test_dead_letter_diferente_e_divergencia(self):
        c = copia()
        c["queues"][0]["arguments"]["x-dead-letter-routing-key"] = "dlq.outra"

        problemas = confere_topologia.confere(c, fonte(), USADOS)

        self.assertEqual(1, len(problemas))
        self.assertIn("fila 'execucao.enviar-para-execucao' tem argumentos diferentes da fonte", problemas[0])

    def test_ttl_diferente_e_divergencia(self):
        f = fonte()
        f["queues"][0]["arguments"]["x-message-ttl"] = 604800000

        problemas = confere_topologia.confere(copia(), f, USADOS)

        self.assertIn("fila 'execucao.enviar-para-execucao' tem argumentos diferentes da fonte", problemas[0])

    def test_binding_so_na_copia_e_divergencia(self):
        c = copia()
        c["bindings"].append(binding("oficina.eventos", "os.saga.eventos", "execucao.cancelada"))

        problemas = confere_topologia.confere(c, fonte(), USADOS)

        self.assertEqual(["binding oficina.eventos -[execucao.cancelada]-> os.saga.eventos esta na copia e nao existe na fonte"], problemas)

    def test_exchange_de_tipo_diferente_e_divergencia(self):
        c = copia()
        c["exchanges"][0]["type"] = "direct"

        problemas = confere_topologia.confere(c, fonte(), USADOS)

        self.assertIn("exchange 'oficina.eventos' tem atributos diferentes da fonte", problemas[0])

    def test_fonte_com_mais_coisa_que_a_copia_nao_e_divergencia(self):
        f = fonte()
        f["queues"].append(fila("billing.cobrar-pagamento", "dlq.billing.cobrar-pagamento"))
        f["bindings"].append(binding("oficina.eventos", "os.saga.eventos", "pagamento.*"))

        self.assertEqual([], confere_topologia.confere(copia(), f, USADOS))

    def test_fila_que_o_servico_usa_e_a_fonte_nao_declara_e_divergencia(self):
        # A copia esta certa, mas a config aponta para um nome que a fonte nao tem: sobe e consome o vazio.
        usados = dict(USADOS, fila_cancelar="execucao.cancelar-execucoes")

        problemas = confere_topologia.confere(copia(), fonte(), usados)

        self.assertEqual(["fila 'execucao.cancelar-execucoes' (config/rabbitmq.php, 'fila_cancelar') nao existe na fonte"], problemas)

    def test_exchange_que_o_servico_usa_e_a_fonte_nao_declara_e_divergencia(self):
        usados = dict(USADOS, exchange_eventos="oficina.eventos.v2")

        problemas = confere_topologia.confere(copia(), fonte(), usados)

        self.assertEqual(["exchange 'oficina.eventos.v2' (config/rabbitmq.php, 'exchange_eventos') nao existe na fonte"], problemas)

    def test_ordem_das_chaves_nos_argumentos_nao_importa(self):
        f = fonte()
        f["queues"][0]["arguments"] = dict(reversed(list(f["queues"][0]["arguments"].items())))

        self.assertEqual([], confere_topologia.confere(copia(), f, USADOS))


class ExecucaoDoScriptTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.dir.cleanup)
        self.base = pathlib.Path(self.dir.name)

    def escreve(self, nome, conteudo):
        caminho = self.base / nome
        caminho.write_text(conteudo if isinstance(conteudo, str) else json.dumps(conteudo), encoding="utf-8")
        return str(caminho)

    def roda(self, topologia_da_copia, topologia_da_fonte, config=CONFIG_PHP):
        argv = [
            "--fonte", self.escreve("fonte.json", topologia_da_fonte),
            "--copia", self.escreve("copia.json", topologia_da_copia),
            "--config", self.escreve("rabbitmq.php", config),
        ]
        saida, erro = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(saida), contextlib.redirect_stderr(erro):
            codigo = confere_topologia.main(argv)
        return codigo, saida.getvalue(), erro.getvalue()

    def test_sai_0_quando_nao_ha_divergencia(self):
        codigo, saida, _ = self.roda(copia(), fonte())

        self.assertEqual(0, codigo)
        self.assertIn("ok:", saida)

    def test_sai_1_e_lista_a_divergencia(self):
        c = copia()
        c["queues"].append(fila("execucao.nova-fila"))

        codigo, saida, _ = self.roda(c, fonte())

        self.assertEqual(1, codigo)
        self.assertIn("DIVERGENCIA: fila 'execucao.nova-fila'", saida)

    def test_sai_2_se_a_fonte_nao_existe(self):
        saida, erro = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(saida), contextlib.redirect_stderr(erro):
            codigo = confere_topologia.main(["--fonte", str(self.base / "nao-existe.json")])

        self.assertEqual(2, codigo)
        self.assertIn("ERRO", erro.getvalue())

    def test_sai_2_se_o_json_e_invalido(self):
        codigo, _, erro = self.roda(copia(), "isto nao e json")

        self.assertEqual(2, codigo)
        self.assertIn("ERRO", erro)

    def test_sai_2_se_nao_acha_nenhum_nome_na_config(self):
        # Config que mudou de forma: sem este erro a conferencia dos nomes passaria vazia.
        codigo, _, erro = self.roda(copia(), fonte(), config="<?php return [];")

        self.assertEqual(2, codigo)
        self.assertIn("nao achei nenhuma fila_*", erro)


class ArquivosReaisTest(unittest.TestCase):
    """A copia e a config deste repositorio precisam ser legiveis pelo script."""

    def test_a_config_do_servico_declara_as_duas_filas_de_comando_e_o_exchange(self):
        usados = confere_topologia.nomes_usados_pelo_servico(confere_topologia.CONFIG.read_text(encoding="utf-8"))

        self.assertIn("exchange_eventos", usados)
        self.assertIn("fila_enviar", usados)

    def test_a_copia_local_e_um_json_com_filas_e_exchanges(self):
        copia_local = json.loads(confere_topologia.COPIA.read_text(encoding="utf-8"))

        self.assertTrue(copia_local["queues"])
        self.assertTrue(copia_local["exchanges"])


if __name__ == "__main__":
    unittest.main()
