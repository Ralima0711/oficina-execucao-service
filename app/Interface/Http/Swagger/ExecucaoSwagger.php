<?php

namespace App\Interface\Http\Swagger;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Oficina — Execução e Produção',
    description: 'Microsserviço de Execução: fila de execução (Redis) e timeline de diagnóstico e reparo (MongoDB). O mecânico vem no corpo da requisição até a autenticação entre serviços ser definida.'
)]
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'Servidor local')]
#[OA\Schema(
    schema: 'ItemFila',
    properties: [
        new OA\Property(property: 'osId', type: 'integer', example: 123),
        new OA\Property(property: 'posicao', type: 'integer', nullable: true, example: 1, description: '1 = próxima a ser atendida'),
        new OA\Property(property: 'enfileiradaEm', type: 'string', format: 'date-time', example: '2026-10-27T14:00:00.000Z'),
    ]
)]
#[OA\Schema(
    schema: 'EventoTimeline',
    properties: [
        new OA\Property(property: 'osId', type: 'integer', example: 123),
        new OA\Property(property: 'tipo', type: 'string', enum: ['DIAGNOSTICO_INICIADO', 'EXECUCAO_INICIADA', 'EXECUCAO_FINALIZADA', 'NOTA'], example: 'EXECUCAO_INICIADA'),
        new OA\Property(property: 'statusAnterior', type: 'string', nullable: true, enum: ['RECEBIDA', 'EM_DIAGNOSTICO', 'APROVADA', 'EM_EXECUCAO', 'FINALIZADA'], example: 'APROVADA'),
        new OA\Property(property: 'statusNovo', type: 'string', nullable: true, enum: ['RECEBIDA', 'EM_DIAGNOSTICO', 'APROVADA', 'EM_EXECUCAO', 'FINALIZADA'], example: 'EM_EXECUCAO'),
        new OA\Property(property: 'mecanicoId', type: 'integer', nullable: true, example: 7),
        new OA\Property(property: 'duracaoSegundos', type: 'integer', nullable: true, example: 840, description: 'Tempo que a OS ficou no status anterior'),
        new OA\Property(property: 'observacao', type: 'string', nullable: true, example: 'Troca de pastilhas iniciada'),
        new OA\Property(property: 'traceId', type: 'string', nullable: true, example: '0af7651916cd43dd8448eb211c80319c'),
        new OA\Property(property: 'ocorridoEm', type: 'string', format: 'date-time', example: '2026-10-27T14:00:00.000Z'),
    ]
)]
#[OA\Schema(
    schema: 'Erro',
    properties: [new OA\Property(property: 'message', type: 'string', example: 'A OS #123 já está na fila de execução.')]
)]
#[OA\Tag(name: 'Fila de execução', description: 'OS aprovadas esperando um mecânico (Redis)')]
#[OA\Tag(name: 'Timeline', description: 'Diagnóstico e reparo de cada OS (MongoDB)')]
#[OA\Tag(name: 'Métricas', description: 'Tempo médio por status')]
#[OA\Tag(name: 'Saúde', description: 'Liveness')]
class ExecucaoSwagger
{
    #[OA\Get(
        path: '/api/health',
        tags: ['Saúde'],
        summary: 'Checagem de saúde',
        responses: [new OA\Response(response: 200, description: 'Serviço no ar')]
    )]
    public function healthDoc(): void {}

    #[OA\Get(
        path: '/api/execucao/fila',
        tags: ['Fila de execução'],
        summary: 'Lista a fila em ordem de chegada',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Fila (vazia retorna [])',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ItemFila'))
            ),
        ]
    )]
    public function filaIndexDoc(): void {}

    #[OA\Post(
        path: '/api/execucao/fila',
        tags: ['Fila de execução'],
        summary: 'Enfileira uma OS aprovada',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['osId'],
                properties: [new OA\Property(property: 'osId', type: 'integer', example: 123)]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'OS enfileirada', content: new OA\JsonContent(ref: '#/components/schemas/ItemFila')),
            new OA\Response(response: 409, description: 'OS já está na fila, em execução ou finalizada', content: new OA\JsonContent(ref: '#/components/schemas/Erro')),
            new OA\Response(response: 422, description: 'Erro de validação'),
        ]
    )]
    public function filaStoreDoc(): void {}

    #[OA\Post(
        path: '/api/execucao/fila/proxima',
        tags: ['Fila de execução'],
        summary: 'Mecânico puxa a próxima OS da fila e inicia a execução',
        description: 'Operação atômica: a OS sai da fila, o mecânico fica ocupado com ela e a timeline ganha EXECUCAO_INICIADA.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['mecanicoId'],
                properties: [new OA\Property(property: 'mecanicoId', type: 'integer', example: 7)]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Execução iniciada', content: new OA\JsonContent(ref: '#/components/schemas/EventoTimeline')),
            new OA\Response(response: 404, description: 'Fila vazia', content: new OA\JsonContent(ref: '#/components/schemas/Erro')),
            new OA\Response(response: 409, description: 'Mecânico já está executando outra OS', content: new OA\JsonContent(ref: '#/components/schemas/Erro')),
            new OA\Response(response: 422, description: 'Erro de validação'),
        ]
    )]
    public function filaProximaDoc(): void {}

    #[OA\Delete(
        path: '/api/execucao/fila/{osId}',
        tags: ['Fila de execução'],
        summary: 'Remove uma OS da fila',
        parameters: [new OA\Parameter(name: 'osId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))],
        responses: [
            new OA\Response(response: 204, description: 'Removida'),
            new OA\Response(response: 404, description: 'OS não está na fila', content: new OA\JsonContent(ref: '#/components/schemas/Erro')),
        ]
    )]
    public function filaDestroyDoc(): void {}

    #[OA\Get(
        path: '/api/execucao/os/{osId}/timeline',
        tags: ['Timeline'],
        summary: 'Histórico de diagnóstico e reparo da OS, do mais antigo ao mais recente',
        parameters: [new OA\Parameter(name: 'osId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Eventos da OS',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EventoTimeline'))
            ),
            new OA\Response(response: 404, description: 'OS sem eventos', content: new OA\JsonContent(ref: '#/components/schemas/Erro')),
        ]
    )]
    public function timelineDoc(): void {}

    #[OA\Post(
        path: '/api/execucao/os/{osId}/diagnostico',
        tags: ['Timeline'],
        summary: 'Inicia o diagnóstico (RECEBIDA → EM_DIAGNOSTICO)',
        parameters: [new OA\Parameter(name: 'osId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['mecanicoId'],
                properties: [
                    new OA\Property(property: 'mecanicoId', type: 'integer', example: 7),
                    new OA\Property(property: 'observacao', type: 'string', nullable: true, example: 'Cliente relata barulho na frente'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Diagnóstico iniciado', content: new OA\JsonContent(ref: '#/components/schemas/EventoTimeline')),
            new OA\Response(response: 422, description: 'OS não está RECEBIDA ou erro de validação', content: new OA\JsonContent(ref: '#/components/schemas/Erro')),
        ]
    )]
    public function diagnosticoDoc(): void {}

    #[OA\Post(
        path: '/api/execucao/os/{osId}/notas',
        tags: ['Timeline'],
        summary: 'Registra uma anotação durante o diagnóstico ou o reparo',
        parameters: [new OA\Parameter(name: 'osId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['mecanicoId', 'texto'],
                properties: [
                    new OA\Property(property: 'mecanicoId', type: 'integer', example: 7),
                    new OA\Property(property: 'texto', type: 'string', example: 'Pastilhas dianteiras no limite, discos ok'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Nota registrada', content: new OA\JsonContent(ref: '#/components/schemas/EventoTimeline')),
            new OA\Response(response: 409, description: 'OS está com outro mecânico', content: new OA\JsonContent(ref: '#/components/schemas/Erro')),
            new OA\Response(response: 422, description: 'OS fora de diagnóstico/execução ou erro de validação', content: new OA\JsonContent(ref: '#/components/schemas/Erro')),
        ]
    )]
    public function notaDoc(): void {}

    #[OA\Post(
        path: '/api/execucao/os/{osId}/finalizar',
        tags: ['Timeline'],
        summary: 'Encerra o reparo (EM_EXECUCAO → FINALIZADA) e libera o mecânico',
        parameters: [new OA\Parameter(name: 'osId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['mecanicoId'],
                properties: [
                    new OA\Property(property: 'mecanicoId', type: 'integer', example: 7),
                    new OA\Property(property: 'observacao', type: 'string', nullable: true, example: 'Pastilhas trocadas e testadas'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Reparo finalizado', content: new OA\JsonContent(ref: '#/components/schemas/EventoTimeline')),
            new OA\Response(response: 409, description: 'OS está com outro mecânico', content: new OA\JsonContent(ref: '#/components/schemas/Erro')),
            new OA\Response(response: 422, description: 'OS não está EM_EXECUCAO ou erro de validação', content: new OA\JsonContent(ref: '#/components/schemas/Erro')),
        ]
    )]
    public function finalizarDoc(): void {}

    #[OA\Get(
        path: '/api/execucao/metricas/tempo-medio',
        tags: ['Métricas'],
        summary: 'Tempo médio que as OS ficaram em cada status',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Média por status',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'EM_EXECUCAO'),
                    new OA\Property(property: 'tempoMedioSegundos', type: 'number', format: 'float', example: 5400.5),
                    new OA\Property(property: 'amostras', type: 'integer', example: 12),
                ]))
            ),
        ]
    )]
    public function tempoMedioDoc(): void {}
}
