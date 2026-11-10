# Prompts para Codex — Sistema de Nesting para Corte a Laser

Instruções sequenciais para construção local (ambiente de testes/validação). Envie um bloco por vez, na ordem. Cada bloco assume que o anterior já foi concluído e validado.

Stack: Laravel + MySQL + Blade/Alpine.js (app principal), Node.js (motor de nesting via Deepnest), Python + ezdxf (extração de geometria DXF), Fabric.js ou Konva.js (editor manual no frontend).

---

## FASE 0 — Setup do ambiente local

```
Crie um novo projeto Laravel (última versão estável) chamado "nesting-laser".
Configure MySQL como banco de dados local (.env com credenciais padrão de dev).
Configure a fila (QUEUE_CONNECTION=database) e rode as migrations padrão do Laravel
para a tabela de jobs (php artisan queue:table + migrate).
Instale Laravel Breeze ou similar apenas com autenticação simples (login/senha),
sem necessidade de registro público — o sistema é interno, uso por operadores.
Crie um usuário seed via seeder para login inicial de teste.
Confirme ao final que "php artisan serve" e "php artisan queue:work" rodam sem erro.
```

---

## FASE 1 — Schema do banco (estoque de chapas, materiais, peças)

```
Crie as migrations e models Eloquent para as seguintes tabelas, com relacionamentos
Eloquent apropriados (belongsTo/hasMany) e factories para dados de teste:

1. materiais
   - id, nome (ex: "Aço Carbono"), created_at, updated_at

2. espessuras_material
   - id, material_id (fk), valor_mm (decimal), velocidade_corte_mm_min (decimal),
     tempo_perfuracao_seg (decimal), created_at, updated_at

3. chapas
   - id, material_id (fk), espessura_id (fk, references espessuras_material),
     largura_mm (decimal), altura_mm (decimal),
     origem (enum: 'nova', 'retalho'), chapa_pai_id (fk nullable, self-reference),
     contorno_json (json, nullable — usado quando origem = 'retalho' e o formato
     não é retangular simples),
     quantidade_disponivel (integer),
     status (enum: 'disponivel', 'reservada', 'baixada'),
     created_at, updated_at

4. pecas
   - id, nome (string), arquivo_dxf_original (string, path),
     contorno_json (json, nullable — preenchido após extração de geometria),
     largura_bbox_mm (decimal, nullable), altura_bbox_mm (decimal, nullable),
     created_at, updated_at

5. pedidos_corte
   - id, status (enum: 'rascunho', 'aguardando_nesting', 'pronto',
     'em_producao', 'concluido'),
     chapa_id (fk, nullable até ser definida),
     resultado_nesting_json (json, nullable),
     dxf_saida (string, path, nullable),
     tempo_corte_estimado_seg (integer, nullable),
     tempo_corte_real_seg (integer, nullable),
     sobra_aproveitavel_json (json, nullable),
     created_at, updated_at

6. pedido_corte_pecas (tabela pivot)
   - id, pedido_corte_id (fk), peca_id (fk), quantidade (integer)

7. movimentacoes_estoque
   - id, chapa_id (fk), tipo (enum: 'entrada', 'baixa', 'geracao_retalho'),
     pedido_corte_id (fk, nullable), quantidade (integer),
     observacao (string, nullable), created_at

Crie também os Models com os relacionamentos, os enums como PHP enums nativos
(não strings soltas), e um seeder de exemplo populando materiais e espessuras
comuns de chapa metálica (aço carbono 1mm/2mm/3mm, inox 1mm/2mm, alumínio 1mm/2mm)
com valores de velocidade de corte estimados razoáveis para laser de fibra.

Gere também Controllers e views Blade simples (CRUD básico, sem estilização
avançada ainda) para: materiais, chapas (listagem com filtro por material/status),
e pecas (upload de arquivo DXF, só salvando o arquivo por enquanto, sem parsing).
```

---

## FASE 2 — Serviço de extração de geometria DXF (Python)

```
Crie um script Python standalone em um diretório "services/dxf-parser/" dentro
do projeto Laravel, usando a biblioteca ezdxf.

Requisitos:
- Script recebe como argumento de linha de comando o caminho de um arquivo .dxf
- Extrai todas as entidades de contorno relevantes (LINE, LWPOLYLINE, POLYLINE,
  ARC, CIRCLE, SPLINE) e converte para uma lista de polígonos simples
  (lista de pontos [x, y], com arcos/splines discretizados em segmentos de reta
  com tolerância configurável)
- Calcula a bounding box (largura e altura) do contorno total
- Retorna tudo como JSON via stdout, no formato:
  {
    "bounding_box": {"largura_mm": ..., "altura_mm": ...},
    "contornos": [[[x1,y1],[x2,y2],...], ...]
  }
- Trata erros (arquivo inválido, DXF corrompido) retornando JSON de erro
  no formato {"erro": "mensagem"} e exit code diferente de 0

Crie também um requirements.txt para esse serviço (ezdxf e dependências),
e um README.md curto explicando como rodar isoladamente para teste:
python extrair_geometria.py caminho/arquivo.dxf

Não integre com o Laravel ainda nesta etapa — só valide o script funcionando
sozinho via linha de comando com um arquivo DXF de teste.
```

---

## FASE 3 — Integração Laravel → parser Python

```
No Laravel, crie:

1. Um Job "ExtrairGeometriaDxf" (implements ShouldQueue) que:
   - Recebe o ID de uma peça
   - Executa o script Python via Symfony Process (não usar exec/shell_exec direto,
     usar a classe Process do Symfony que o Laravel já usa internamente)
   - Captura o JSON de saída, faz parse, e atualiza a peça no banco
     (contorno_json, largura_bbox_mm, altura_bbox_mm)
   - Em caso de erro do script, marca a peça com um campo de status de erro
     (adicione esse campo via migration: status_processamento enum
     'pendente','processado','erro' + erro_mensagem nullable)
   - Loga falhas de forma clara (Log::error)

2. Ajuste o Controller de upload de peças para, após salvar o arquivo,
   disparar o Job ExtrairGeometriaDxf::dispatch($peca->id)

3. Na view de listagem de peças, mostre o status_processamento
   (pendente/processado/erro) com atualização simples via polling
   (Alpine.js com setInterval chamando uma rota que retorna o status em JSON,
   sem necessidade de websockets nesta fase)

Teste o fluxo completo: upload de um DXF de teste → job disparado →
geometria extraída e salva → status atualizado na tela.
```

---

## FASE 3.5 — Offset/kerf de peças (compensação do feixe de corte)

```
Objetivo: compensar a largura do feixe de laser (kerf) deslocando o contorno
de cada peça antes do nesting, para que o arranjo calculado já reflita o
tamanho real de material consumido no corte, não o tamanho de desenho.

1. Migration: adicione o campo "kerf_mm" (decimal, nullable) na tabela
   espessuras_material, junto aos campos já existentes de velocidade de
   corte e tempo de perfuração. Popule com valores de exemplo razoáveis
   no seeder já criado na Fase 1 (kerf típico de laser de fibra fica na
   faixa de 0,1 a 0,2mm, ajuste conforme material/espessura).

2. No serviço Python (services/dxf-parser/), adicione a dependência
   "pyclipper" ao requirements.txt e crie um novo script
   "aplicar_offset.py" que:
   - Recebe via stdin (ou arquivo temporário) um JSON no formato:
     { "contornos": [[[x,y],...], ...], "offset_mm": ... }
   - Usa pyclipper para aplicar o offset em cada contorno (offset positivo
     expande, negativo contrai — decida e documente no README qual direção
     o sistema usa como padrão para compensar kerf, normalmente contrai a
     peça em metade do valor do kerf pra cada lado do corte)
   - Trata contornos com furos internos corretamente (furos devem receber
     offset na direção oposta ao contorno externo, para não fechar o furo
     nem alargar demais)
   - Usa join type "miter" como padrão para cantos, mas deixe configurável
     via parâmetro
   - Retorna JSON no mesmo formato de entrada, com os contornos já
     deslocados: { "contornos": [[[x,y],...], ...] }
   - Trata erro de geometria inválida após offset (ex: contorno que colapsa
     completamente com offset negativo muito agressivo) retornando JSON de
     erro claro

   Teste esse script isoladamente antes de integrar, usando um contorno
   simples de teste (retângulo com furo) e conferindo visualmente (plotando
   com matplotlib num script auxiliar de teste, não precisa ir pro sistema)
   que o resultado faz sentido geometricamente.

3. No Laravel, ajuste o Job "ExtrairGeometriaDxf" (Fase 3): depois de extrair
   a geometria bruta da peça, adicione uma etapa que busca o kerf_mm da
   espessura de material vinculada ao pedido/chapa (nota: como a peça pode
   ser usada em pedidos com materiais diferentes ao longo do tempo, avalie
   se o offset deve ser aplicado e salvo permanentemente no contorno_json
   da peça, ou se deve ser um cálculo feito sob demanda por pedido — a
   segunda opção é mais correta, já que o mesmo desenho de peça pode ser
   cortado em materiais/espessuras diferentes com kerfs diferentes;
   implemente dessa forma: mantenha contorno_json da peça sempre como o
   desenho original sem offset, e aplique o offset como uma etapa separada
   dentro do Job "RodarNesting" da Fase 5, logo antes de montar o payload
   pro motor de nesting)

4. Ajuste o Job "RodarNesting" (Fase 5) para, antes de chamar o serviço
   Node de nesting: buscar o kerf_mm da espessura vinculada à chapa do
   pedido, chamar o script aplicar_offset.py para cada peça com esse valor,
   e só então montar o payload com os contornos já compensados.

5. Ajuste também o Job "GerarDxfSaida" (Fase 7): o DXF final deve ser
   gerado a partir dos contornos JÁ com offset aplicado (os mesmos usados
   no nesting), não do contorno original da peça — garanta que o
   resultado_nesting_json ou uma estrutura auxiliar carregue os contornos
   com offset já aplicado junto com as posições/rotações, para não precisar
   recalcular o offset novamente nesta etapa.

Teste o fluxo completo: pedido com peça de teste → offset aplicado
corretamente segundo o kerf do material escolhido → nesting rodando sobre
os contornos compensados → DXF final gerado já refletindo o tamanho real
de corte. Confira abrindo o DXF de saída num visualizador e comparando
manualmente a medida da peça exportada com (medida original - kerf).
```

---

## FASE 4 — Motor de nesting (Node.js + Deepnest headless)

```
Crie um serviço Node.js standalone em "services/nesting-engine/" (separado do
Laravel, vai rodar como processo próprio).

Requisitos:
- Servidor Express simples com um único endpoint POST /nesting
- Recebe no body JSON:
  {
    "chapa": {"largura_mm": ..., "altura_mm": ..., "contorno": [[x,y],...] (opcional, se não for retangular)},
    "pecas": [
      {"id": ..., "contorno": [[x,y],...], "quantidade": ...},
      ...
    ]
  }
- Usa uma biblioteca de nesting baseada em No-Fit-Polygon e algoritmo genético
  (pesquise e use a lib "svgnest" adaptada para uso headless/Node, ou implemente
  a chamada ao núcleo do Deepnest sem a parte de UI Electron — avalie qual dessas
  duas abordagens é mais viável de rodar sem interface gráfica e documente a
  escolha em um README.md dentro da pasta do serviço)
- Retorna JSON no formato:
  {
    "pecas_posicionadas": [
      {"id": ..., "x": ..., "y": ..., "rotacao_graus": ...},
      ...
    ],
    "area_utilizada_pct": ...,
    "sobra_contorno": [[x,y],...] (aproximação da maior área retangular livre, se calculável)
  }
- Trate timeout: se o cálculo passar de um limite configurável (ex: 30s),
  retorne o melhor resultado parcial encontrado até então, não erro
- Adicione um endpoint GET /health para checagem simples de que o serviço está no ar

Crie um package.json com as dependências, e um README explicando como rodar
localmente (npm install && npm start, porta configurável via .env).

Teste isoladamente via curl/Postman com um payload de exemplo simples
(ex: chapa 1000x2000mm, 3 peças retangulares) antes de integrar com o Laravel.
```

---

## FASE 5 — Integração Laravel → motor de nesting

```
No Laravel, crie:

1. Um Job "RodarNesting" (implements ShouldQueue) que:
   - Recebe o ID de um pedido_corte
   - Monta o payload (dados da chapa vinculada + geometria de todas as peças
     do pedido, já extraídas na Fase 3)
   - Faz uma chamada HTTP (Http::timeout(60)->post(...)) para o serviço Node
     rodando em localhost (endereço configurável via .env: NESTING_ENGINE_URL)
   - Salva o retorno em resultado_nesting_json e atualiza status do pedido
     para 'pronto'
   - Calcula tempo_corte_estimado_seg a partir do perímetro total das peças
     posicionadas e da velocidade de corte da espessura/material da chapa
     (crie um Service class "CalculadoraTempoCorte" separado, não deixe essa
     lógica dentro do Job)
   - Trata falha de conexão com o serviço Node (marca pedido com status de
     erro e mensagem legível)

2. Ajuste o fluxo do pedido_corte: uma action/controller que, ao confirmar
   as peças e a chapa escolhida, muda status para 'aguardando_nesting' e
   dispara RodarNesting::dispatch($pedido->id)

3. Crie uma tela de detalhe do pedido que mostra o status em tempo real
   via polling (igual à Fase 3), e quando status = 'pronto', mostra um
   resumo (área utilizada %, tempo estimado, lista de peças posicionadas)
   em formato texto/tabela — ainda sem visualização gráfica, isso vem
   na próxima fase.

Teste o fluxo completo local: criar um pedido com 2-3 peças de teste,
escolher uma chapa, confirmar, acompanhar o status até 'pronto', e conferir
que os dados retornados fazem sentido.
```

---

## FASE 6 — Editor visual do arranjo (visualização + ajuste manual)

```
Crie uma tela de editor visual para o pedido_corte quando status = 'pronto',
usando Konva.js (ou Fabric.js — escolha uma e justifique brevemente no início
da implementação) para renderizar:

- A chapa como um retângulo (ou polígono, se for retalho irregular) na escala
  correta, ocupando a área disponível da tela
- Cada peça posicionada segundo resultado_nesting_json, desenhada a partir do
  seu contorno_json real (não um retângulo placeholder — usar os pontos reais
  do DXF extraído)
- Cada peça deve ser arrastável (drag) e rotacionável (handle de rotação),
  com os limites da chapa restringindo o movimento (peça não pode sair da
  chapa)
- Detecção de colisão simples entre peças durante o arraste: destaque em
  vermelho quando duas peças se sobrepõem, sem bloquear o movimento (o
  operador decide se quer resolver antes de confirmar)
- Um botão "Recalcular tempo de corte" que envia as posições atuais (originais
  + ajustes manuais) para o backend, que recalcula tempo_corte_estimado_seg
  com base na nova ordem/posição e retorna o valor atualizado sem reload de
  página (fetch + Alpine.js)
- Um botão "Confirmar arranjo final" que salva as posições ajustadas de volta
  em resultado_nesting_json (sobrescrevendo com os valores finais confirmados
  pelo operador) e muda o status do pedido para 'em_producao'

Crie as rotas/controllers necessárias no Laravel para:
- Servir os dados do pedido + geometria das peças em JSON para o frontend
- Receber o payload de posições ajustadas e recalcular o tempo de corte
- Confirmar o arranjo final

Não se preocupe ainda com geração do DXF de saída nesta fase — isso é a
Fase 7. Foque em ter o editor funcional e testável visualmente com dados
de um pedido de teste.
```

---

## FASE 7 — Geração do DXF de saída

```
Volte ao serviço Python criado na Fase 2 e adicione um segundo script
"gerar_dxf_saida.py" que:

- Recebe via stdin (ou arquivo temporário, o que for mais robusto) um JSON no
  formato:
  {
    "chapa": {"largura_mm": ..., "altura_mm": ...},
    "pecas_posicionadas": [
      {"contorno": [[x,y],...], "x": ..., "y": ..., "rotacao_graus": ...},
      ...
    ]
  }
- Usa ezdxf para montar um novo arquivo DXF com cada peça desenhada na posição
  e rotação final (transformação geométrica: rotacionar o contorno original
  em torno de seu centro/origem, depois transladar para x,y)
- Inclui o contorno da chapa como referência (layer separado, ex: "CHAPA")
- Salva o arquivo DXF de saída no caminho informado como argumento

No Laravel, crie um Job "GerarDxfSaida" (implements ShouldQueue) que:
- Dispara quando o pedido muda para 'em_producao' (após confirmação no editor
  da Fase 6)
- Monta o payload a partir de resultado_nesting_json
- Chama o script Python via Process
- Salva o caminho do arquivo gerado em dxf_saida
- Disponibiliza um link de download na tela de detalhe do pedido

Teste o fluxo ponta a ponta: pedido criado → nesting automático → ajuste
manual no editor → confirmação → DXF final gerado → download do arquivo
e abertura em um visualizador DXF qualquer para conferir visualmente que
as peças estão nas posições corretas.
```

---

## FASE 8 — Estoque: baixa automática e geração de retalho

```
No Laravel, implemente:

1. Uma action "ConfirmarProducaoConcluida" (controller/rota) para o pedido
   que, quando chamada (operador confirma manualmente que o corte físico foi
   feito):
   - Muda status do pedido_corte para 'concluido'
   - Cria um registro em movimentacoes_estoque (tipo 'baixa') vinculado à
     chapa usada
   - Atualiza quantidade_disponivel da chapa (decrementa, ou marca status
     'baixada' se quantidade chegar a zero)
   - Se sobra_aproveitavel_json estiver preenchido (retornado pelo motor de
     nesting na Fase 4), pergunta ao operador (via formulário simples) se
     quer cadastrar o retalho: se confirmado, cria uma nova chapa com
     origem='retalho', chapa_pai_id apontando pra chapa original, e
     contorno_json = sobra_aproveitavel_json; cria também um registro em
     movimentacoes_estoque (tipo 'geracao_retalho')

2. Uma tela de listagem de movimentacoes_estoque com filtro por chapa e por
   tipo, para o operador conferir o histórico

3. Ajuste a listagem de chapas (Fase 1) para deixar visualmente clara a
   diferença entre chapa nova e retalho (ex: badge/tag), e ordenar por
   padrão priorizando retalhos disponíveis antes de chapas novas do mesmo
   material/espessura — isso é só ordenação na query, não precisa de lógica
   de sugestão automática ainda.

Teste o fluxo: concluir um pedido de teste, confirmar baixa, verificar que
o estoque foi decrementado corretamente e que o retalho (se houver) aparece
listado como nova chapa disponível.
```

---

## FASE 9 — Dashboard e visão geral de gestão

```
Crie uma tela de dashboard (rota inicial após login) com uma visão geral
gerencial do sistema, usando queries agregadas do Eloquent (evite N+1,
use withCount/withSum onde fizer sentido):

- Cards de resumo no topo:
  - Total de chapas disponíveis em estoque (agrupado por material)
  - Total de retalhos disponíveis (quantidade e, se possível, área total
    estimada somando bounding boxes)
  - Pedidos em andamento (contagem por status: rascunho, aguardando_nesting,
    pronto, em_producao)
  - Alertas de estoque baixo (chapas com quantidade_disponivel abaixo de um
    limite configurável por material — adicione um campo
    "estoque_minimo_alerta" na tabela materiais via migration)

- Uma tabela/lista dos últimos pedidos_corte (10 mais recentes) com status,
  material/chapa usada, e link pro detalhe

- Um gráfico simples (pode usar Chart.js via CDN, sem necessidade de biblioteca
  pesada) mostrando quantidade de pedidos concluídos por semana nos últimos
  30 dias — dado simples pra visualizar volume de produção ao longo do tempo

Não precisa de real-time aqui, carregamento normal da página basta.
```

---

## FASE 10 — Relatórios de aproveitamento e produtividade

```
Crie uma seção de relatórios (nova rota /relatorios) com os seguintes
relatórios, cada um como uma tela separada com filtro por período
(data inicial/final) e, quando aplicável, por material:

1. Relatório de aproveitamento de material
   - Lista pedidos concluídos no período com: chapa usada, área total da
     chapa, área ocupada pelas peças (calculável a partir dos contornos em
     resultado_nesting_json), percentual de aproveitamento
   - Rodapé com média de aproveitamento do período
   - Botão de exportar para CSV

2. Relatório de tempo de corte: estimado vs real
   - Lista pedidos concluídos com tempo_corte_estimado_seg e
     tempo_corte_real_seg lado a lado, e a diferença percentual
   - Isso serve pra você perceber ao longo do tempo se a fórmula de
     estimativa está precisa ou precisa de ajuste nos parâmetros de
     velocidade de corte cadastrados em espessuras_material
   - Adicione, se ainda não existir, um campo simples na tela de conclusão
     do pedido (Fase 8) para o operador informar o tempo real de corte
     manualmente (não precisa de integração com a máquina nesta fase)

3. Relatório de consumo de chapas por material
   - Quantidade de chapas baixadas por material/espessura no período,
     útil pra planejar reposição de estoque

4. Relatório de retalhos gerados vs retalhos reaproveitados
   - Quantos retalhos foram criados no período vs quantos foram
     efetivamente usados em algum pedido_corte (chapa_id de um pedido
     apontando pra uma chapa com origem='retalho')
   - Isso mede se a lógica de reaproveitamento está realmente sendo usada
     na prática ou se os retalhos estão só acumulando sem uso

Todos os relatórios devem ter paginação se a lista for longa, e os cálculos
agregados pesados (soma de área, médias) devem ser feitos via query SQL
(agregação no banco), não carregando todos os registros em memória e
somando em PHP.
```

---

## FASE 11 — Usuários, permissões e histórico de ações

```
Implemente controle de acesso simples com Laravel (pode usar Spatie
Laravel-Permission ou Gates/Policies nativos — escolha o mais simples de
manter e justifique a escolha):

- Dois papéis: "admin" (acesso total, inclui cadastro de materiais,
  usuários, relatórios) e "operador" (acesso a pedidos, chapas, e ao
  editor visual, mas sem acesso a cadastro de materiais/usuários)
- Tela de gestão de usuários (só admin acessa): criar, editar, desativar
  usuário, definir papel
- Middleware protegendo as rotas administrativas (materiais, usuários,
  relatórios) para apenas admin

Adicione também um log de auditoria simples: crie uma tabela "historico_acoes"
(user_id, acao, entidade, entidade_id, dados_alterados json nullable,
created_at) e registre automaticamente as ações críticas: baixa de estoque,
geração de retalho, confirmação de arranjo final, mudança de status de
pedido. Use um Observer do Eloquent ou um trait simples aplicado aos models
relevantes, evite espalhar código de log manualmente em cada controller.

Crie uma tela (só admin) pra consultar esse histórico, com filtro por
usuário, tipo de ação e período.
```

---

## Observações gerais para todas as fases

- Peça ao Codex para escrever testes automatizados básicos (Feature tests do
  Laravel) para os fluxos principais de cada fase, não só o código funcional.
- Peça para não usar `shell_exec`/`exec` diretamente em nenhum momento — sempre
  `Symfony\Component\Process\Process`, por segurança e melhor tratamento de erro.
- Valide cada fase rodando local antes de mandar a próxima — isso evita acumular
  erro de uma fase escondido dentro da complexidade da próxima.
- A partir da Fase 4, você vai ter três processos rodando em paralelo localmente
  para testar: `php artisan serve`, `php artisan queue:work`, e `npm start`
  dentro de services/nesting-engine — vale documentar isso num README na raiz
  do projeto para não esquecer a ordem de start.
