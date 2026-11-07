<?php

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/**
 * Valida mensagens contra o contrato AsyncAPI do grupo (docs/asyncapi.yaml): o mesmo
 * documento que o OS Service e o Billing usam. Se alguém mudar o contrato, estes testes
 * mostram o que a Execução deixa de cumprir.
 *
 * Para reaproveitar em outro serviço, copie esta classe e o `docs/asyncapi.yaml`.
 */
final class ContratoAsyncApi
{
    private const URI = 'asyncapi://contrato';

    private static ?self $instancia = null;

    private Validator $validator;

    /** @var array<string, mixed> */
    private array $doc;

    private function __construct(string $caminho)
    {
        $this->doc = Yaml::parseFile($caminho);

        $this->validator = new Validator();
        $this->validator->resolver()->registerRaw(json_decode(json_encode($this->doc)), self::URI);
    }

    public static function carregar(): self
    {
        return self::$instancia ??= new self(dirname(__DIR__, 2) . '/docs/asyncapi.yaml');
    }

    public function versao(): string
    {
        return (string) $this->doc['info']['version'];
    }

    /** Endereço (fila ou exchange) de um canal, ex.: enderecoDoCanal('enviarParaExecucao'). */
    public function enderecoDoCanal(string $canal): string
    {
        return (string) $this->doc['channels'][$canal]['address'];
    }

    /** Campos obrigatórios do header do envelope (messageTraits.envelope). */
    public function headersObrigatorios(): array
    {
        return $this->doc['components']['messageTraits']['envelope']['headers']['required'];
    }

    /**
     * @param string $mensagem nome em components.messages, ex.: 'EnviarParaExecucao'
     * @param string $json     o envelope como vai pelo fio: {"header": {...}, "body": {...}}
     *
     * @return string[] problemas encontrados; vazio se a mensagem cumpre o contrato
     */
    public function validarEnvelope(string $mensagem, string $json): array
    {
        $dados = json_decode($json);
        $erros = [];

        foreach ($this->doc['components']['messages'][$mensagem]['traits'] ?? [] as $trait) {
            $erros = array_merge($erros, $this->validar($dados->header ?? null, $trait['$ref'] . '/headers', "header de {$mensagem}"));
        }

        return array_merge($erros, $this->validar($dados->body ?? null, "#/components/messages/{$mensagem}/payload", "payload de {$mensagem}"));
    }

    /** @return string[] */
    private function validar(mixed $dados, string $ponteiro, string $rotulo): array
    {
        $resultado = $this->validator->validate($dados, self::URI . $ponteiro);

        if ($resultado->isValid()) {
            return [];
        }

        return array_map(
            fn (string $erro) => "{$rotulo}: {$erro}",
            array_values((new ErrorFormatter())->formatFlat($resultado->error()))
        );
    }
}
