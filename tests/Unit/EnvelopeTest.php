<?php

namespace Tests\Unit;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MensagemInvalida;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnvelopeTest extends TestCase
{
    private const MESSAGE_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
    private const SAGA_ID = '9b2f4c1d-3e5a-4f6b-8c7d-0a1b2c3d4e5f';
    private const TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    public function test_ida_e_volta_preserva_o_envelope(): void
    {
        $original = Envelope::novo('execucao.iniciada', self::SAGA_ID, self::MESSAGE_ID, ['osId' => 'x', 'n' => 1], self::TRACEPARENT);

        $lido = Envelope::fromJson($original->toJson());

        $this->assertSame($original->messageId, $lido->messageId);
        $this->assertSame(self::SAGA_ID, $lido->sagaId);
        $this->assertSame(self::MESSAGE_ID, $lido->causationId);
        $this->assertSame('execucao.iniciada', $lido->type);
        $this->assertSame(1, $lido->version);
        $this->assertSame(self::TRACEPARENT, $lido->traceparent);
        $this->assertSame(['osId' => 'x', 'n' => 1], $lido->body);
    }

    public function test_novo_gera_message_id_uuid_e_data_em_utc(): void
    {
        $a = Envelope::novo('t', self::SAGA_ID, null, [], null);
        $b = Envelope::novo('t', self::SAGA_ID, null, [], null);

        $this->assertNotSame($a->messageId, $b->messageId);
        $this->assertMatchesRegularExpression('/^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/', $a->messageId);
        $this->assertMatchesRegularExpression('/"occurredAt":"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z"/', $a->toJson());
    }

    public function test_corpo_vazio_vira_objeto_e_campos_nulos_ficam_de_fora_do_header(): void
    {
        $json = Envelope::novo('t', self::SAGA_ID, null, [], null)->toJson();

        $this->assertStringContainsString('"body":{}', $json);
        $this->assertStringNotContainsString('causationId', $json);
        $this->assertStringNotContainsString('traceparent', $json);
    }

    public function test_traceparent_cai_para_o_header_do_amqp_quando_falta_no_envelope(): void
    {
        $json = $this->json();

        $this->assertNull(Envelope::fromJson($json)->traceparent);
        $this->assertSame(self::TRACEPARENT, Envelope::fromJson($json, ['Traceparent' => self::TRACEPARENT])->traceparent);
    }

    public function test_o_traceparent_do_envelope_vence_o_do_amqp(): void
    {
        $envelope = Envelope::fromJson($this->json(['traceparent' => self::TRACEPARENT]), ['traceparent' => '00-ffffffffffffffffffffffffffffffff-ffffffffffffffff-01']);

        $this->assertSame(self::TRACEPARENT, $envelope->traceparent);
    }

    #[DataProvider('invalidos')]
    public function test_recusa_envelope_fora_do_contrato(string $json, string $trecho): void
    {
        $this->expectException(MensagemInvalida::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($trecho, '/') . '/');

        Envelope::fromJson($json);
    }

    public static function invalidos(): array
    {
        $ok = ['messageId' => self::MESSAGE_ID, 'sagaId' => self::SAGA_ID, 'type' => 'T', 'occurredAt' => '2026-11-02T12:00:00Z', 'version' => 1];
        $com = fn (array $troca, array $tira = []) => json_encode(['header' => array_diff_key(array_merge($ok, $troca), array_flip($tira)), 'body' => []]);

        return [
            'não é JSON'          => ['{quebrado', 'JSON válido'],
            'sem header'          => ['{"body":{}}', '"header" e "body"'],
            'sem body'            => [json_encode(['header' => $ok]), '"header" e "body"'],
            'messageId ausente'   => [$com([], ['messageId']), 'header.messageId'],
            'messageId não-uuid'  => [$com(['messageId' => '123']), 'header.messageId'],
            'sagaId não-uuid'     => [$com(['sagaId' => 'abc']), 'header.sagaId'],
            'type vazio'          => [$com(['type' => '']), 'header.type'],
            'version como texto'  => [$com(['version' => '1']), 'header.version'],
            'version zero'        => [$com(['version' => 0]), 'header.version'],
            'occurredAt inválida' => [$com(['occurredAt' => 'ontem']), 'header.occurredAt'],
            'occurredAt ausente'  => [$com([], ['occurredAt']), 'header.occurredAt'],
        ];
    }

    private function json(array $extraHeader = []): string
    {
        return json_encode(['header' => array_merge([
            'messageId' => self::MESSAGE_ID, 'sagaId' => self::SAGA_ID, 'type' => 'T', 'occurredAt' => '2026-11-02T12:00:00Z', 'version' => 1,
        ], $extraHeader), 'body' => ['osId' => 'x']]);
    }
}
