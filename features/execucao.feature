# language: pt
Funcionalidade: A Execução responde à saga com o evento certo
  Para a saga avançar ou compensar sem ficar esperando
  Como orquestrador da saga (OS Service)
  Eu preciso que a Execução responda a cada comando, e a cada ação do mecânico,
  com o evento do contrato, na mesma saga e no mesmo trace

  Contexto:
    Dado que o orquestrador enviou a OS "A" para execução com prioridade 3

  Cenário: Caminho feliz: a OS é executada e a saga é avisada do início e do fim
    Quando o mecânico "João" puxar a próxima OS da fila
    E o mecânico "João" finalizar o reparo da OS "A"
    Então o orquestrador deve receber os eventos "execucao.iniciada, execucao.concluida" da OS "A"
    E os eventos devem estar na mesma saga e no mesmo trace do comando

  Cenário: O reparo falha e a saga é avisada para compensar
    Dado que o mecânico "João" puxou a OS "A"
    Quando o mecânico "João" informar que o reparo da OS "A" falhou com o motivo "Peça com defeito de fábrica"
    Então o orquestrador deve receber "execucao.falhou" da OS "A" com o motivo "Peça com defeito de fábrica"
    E o mecânico "João" deve estar livre para puxar outra OS

  Cenário: A compensação tira da fila uma OS que ninguém puxou
    Quando o orquestrador mandar cancelar a execução da OS "A" com o motivo "execucao_falhou"
    Então a OS "A" não deve estar mais na fila
    E o orquestrador deve receber "execucao.falhou" da OS "A" com o motivo "cancelada"

  Cenário: A compensação não desfaz uma execução já concluída
    Dado que o mecânico "João" puxou a OS "A"
    E que o mecânico "João" finalizou o reparo da OS "A"
    Quando o orquestrador mandar cancelar a execução da OS "A" com o motivo "execucao_falhou"
    Então o orquestrador não deve receber "execucao.falhou" da OS "A"
    E a timeline da OS "A" deve terminar em "FINALIZADA"
    E a tentativa de cancelamento deve ficar registrada na timeline da OS "A"
