<?php

namespace App\Application\Messaging;

use Illuminate\Support\Str;

/**
 * Envelope do contrato v1.0 (docs/contrato-eventos-fase4.md, Parte 2):
 *
 *   { "header": { messageId, sagaId, causationId, type, occurredAt, traceparent, version },
 *     "body":   { ...payload... } }
 *
 * O traceparent viaja no header do envelope e também nos application_headers do
 * AMQP (o asyncapi.yaml descreve o envelope como headers de mensagem): quem
 * publica escreve nos dois, e quem consome lê o do envelope e, na falta dele, o
 * do AMQP.
 */
final class Envelope
{
    public const VERSAO = 1;

    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly string $messageId,
        public readonly string $sagaId,
        public readonly string $type,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly int $version,
        public readonly array $body,
        public readonly ?string $causationId = null,
        public readonly ?string $traceparent = null,
    ) {}

    /** @param array<string, mixed> $body */
    public static function novo(string $type, string $sagaId, ?string $causationId, array $body, ?string $traceparent): self
    {
        return new self(
            messageId: (string) Str::uuid(),
            sagaId: $sagaId,
            type: $type,
            occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            version: self::VERSAO,
            body: $body,
            causationId: $causationId,
            traceparent: $traceparent,
        );
    }

    /**
     * @param array<string, mixed> $headersAmqp application_headers da mensagem, se vierem
     *
     * @throws MensagemInvalida
     */
    public static function fromJson(string $json, array $headersAmqp = []): self
    {
        try {
            $dados = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MensagemInvalida('Corpo da mensagem não é um JSON válido: ' . $e->getMessage());
        }

        if (!is_array($dados) || !is_array($dados['header'] ?? null) || !is_array($dados['body'] ?? null)) {
            throw new MensagemInvalida('O envelope precisa de "header" e "body".');
        }

        $h = $dados['header'];

        foreach (['messageId', 'sagaId'] as $campo) {
            if (!isset($h[$campo]) || !is_string($h[$campo]) || !Str::isUuid($h[$campo])) {
                throw new MensagemInvalida("header.{$campo} é obrigatório e deve ser um UUID.");
            }
        }

        if (!isset($h['type']) || !is_string($h['type']) || $h['type'] === '') {
            throw new MensagemInvalida('header.type é obrigatório.');
        }

        if (!isset($h['version']) || !is_int($h['version']) || $h['version'] < 1) {
            throw new MensagemInvalida('header.version é obrigatório e deve ser um inteiro a partir de 1.');
        }

        // new DateTimeImmutable('') seria "agora": a ausência precisa ser recusada antes.
        if (!isset($h['occurredAt']) || !is_string($h['occurredAt']) || $h['occurredAt'] === '') {
            throw new MensagemInvalida('header.occurredAt é obrigatório e deve ser uma data ISO 8601.');
        }

        try {
            $occurredAt = new \DateTimeImmutable($h['occurredAt']);
        } catch (\Exception) {
            throw new MensagemInvalida('header.occurredAt é obrigatório e deve ser uma data ISO 8601.');
        }

        $causationId = $h['causationId'] ?? null;
        $traceparent = $h['traceparent'] ?? self::cabecalho($headersAmqp, 'traceparent');

        return new self(
            messageId: $h['messageId'],
            sagaId: $h['sagaId'],
            type: $h['type'],
            occurredAt: $occurredAt,
            version: $h['version'],
            body: $dados['body'],
            causationId: is_string($causationId) ? $causationId : null,
            traceparent: is_string($traceparent) && $traceparent !== '' ? $traceparent : null,
        );
    }

    public function toJson(): string
    {
        return json_encode(
            [
                'header' => array_filter([
                    'messageId'   => $this->messageId,
                    'sagaId'      => $this->sagaId,
                    'causationId' => $this->causationId,
                    'type'        => $this->type,
                    'occurredAt'  => $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                    'traceparent' => $this->traceparent,
                    'version'     => $this->version,
                ], fn ($v) => $v !== null),
                'body' => (object) $this->body,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /** @param array<string, mixed> $headers */
    private static function cabecalho(array $headers, string $nome): ?string
    {
        foreach ($headers as $chave => $valor) {
            if (strtolower((string) $chave) === $nome && is_string($valor)) {
                return $valor;
            }
        }

        return null;
    }
}
