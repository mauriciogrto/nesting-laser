# Technical knowledge

## Perfis simples podiam escapar da busca angular

- Sintoma (2026-10-09): uma peça retangular de 60 × 10 mm não era posicionada numa chapa de 55 × 55 mm com margem de 2 mm, embora coubesse na diagonal. Contornos ortogonais curtos com reentrâncias também não acionavam a busca detalhada.
- Causa: a seleção da fase Python exigia furos, mais de 48 vértices ou uma aresta oblíqua; o motor Node de base só testava rotações cardinais. A construção angular incompleta usava uma única ordem e uma direção.
- Solução: enviar esses casos à busca detalhada e, quando o lote cardinal está incompleto, testar mais ordens e direções; uma peça que excede a área útil nas orientações cardinais recebe sementes a cada 15 graus. A aceitação continua sujeita à validação geométrica e à qualidade da sobra.
- Verificação: casos que só cabem a 45° e a um ângulo intermediário posicionaram 1/1 peça; 23 testes Node e 18 testes Python passaram. Uma requisição pela ponte SFTP do servidor retornou 1/1 peça a 227,5°. No benchmark de 31 peças, a busca manteve 31/31 e a sobra de 2,275716 m², sem ganho adicional medido.

## UNI5 compartilhada interrompe o motor de otimização em segundo plano

- Sintoma (2026-10-02): `app.example.com` usa PHP-FPM 8.3 com `proc_open` e `exec` desabilitados. Node 22 pode ser instalado na pasta privada e funciona em primeiro plano; iniciado com `nohup`, chegou a responder em loopback, mas deixou de responder após a sessão e a pasta `services/nesting-engine` passou a modo 000. `crontab` e `systemctl --user` retornam permissão negada; `tmux` e `screen` não existem.
- Causa operacional observada: a conta não ofereceu, nos acessos disponíveis, um meio confiável de manter e reiniciar Node e o worker de filas. A razão específica da mudança de permissão pelo provedor não foi identificada; não atribuir sem confirmação.
- Solução de compatibilidade de código: concentrar as operações Python em `ExecutorPython`, com Symfony Process local e ponte HTTP autenticada via Node quando o PHP web não pode iniciar subprocessos. O endpoint aceita apenas scripts conhecidos e arquivos em armazenamento privado. Um teste real pela web executou offset com sucesso. Isso resolve a restrição do PHP, mas não a persistência do motor.
- Lição: antes de publicar, testar o processamento completo pelo PHP-FPM e a sobrevivência dos serviços a logout/reinício. Uma página Laravel que abre sem motor e fila não representa um sistema de nesting funcional. Exigir hospedagem com supervisão de processos ou habilitação explícita equivalente.

## Angular search needs a quality floor and actual neighbourhood changes

- Symptom (2026-10-02): cardinal-only automatic orientations prevented testing diagonal and fractional interlocks, although editor/CNC transforms already accepted arbitrary rotations.
- Solution: lazy per-shape orientation generation in `RegionPacker`, fractional incumbent import/export, and `angular_nesting.py` using long-edge alignments, relative neighbour directions, offsets and fractional adjustments. Some trials remove and repack neighbours instead of rotating one locked piece. Simple oblique outlines also trigger the quality pipeline, including a single part.
- Verification: a 60 × 10 mm rectangle supplied at 23.75 degrees cannot fit a 70 × 20 mm sheet with 2 mm borders at any cardinal rotation; the complete Node/Python pipeline places it at 156.25 degrees. Fractional-rotation tests cover holes, spacing, incumbent roundtrip and CNC coordinates.
- Real-batch finding: 150-second angular and 180-second compact/angular-group trials retained the 31-piece benchmark's 2.275716 m² remnant. A 315-degree internal move reduced a secondary extent metric but did not lower the top boundary. It passed exact Node/CNC preflight but was deliberately not saved as a claimed material improvement. No further percentage gain has been demonstrated in this step.

## Sparse contact search and rounded holes leave usable pockets unexplored

- Symptom (2026-10-02): the 31-piece arrangement was only marginally better than manual and left large internal gaps despite valid pairwise distances.
- Cause: sparse vertices of individual no-fit polygons did not include intersections induced by several neighbours. Hole anchors at the hole bounding-box corners failed on rounded/reentrant corners. A single large-first greedy pass could also leave the wrong fillers outside cutouts.
- Solution: subtract the union of forbidden translation regions, test vertices of the remaining feasible regions, and reinsert local groups. For cutouts, use the internal rings of the Minkowski boundary sweep and subtract nearby obstacles. Preserve full compensated polygons for final distance/collision checks. Move remaining external fillers into feasible holes without moving an occupied host. Keep validated saved geometry as the quality floor.
- Experiments: globally reducing simplification to 0.025 mm made the real benchmark drastically slower (three attempts in 90 seconds); keep 0.15 mm for external search and 0.025 mm only for inner fitting and nearby obstacles. Inflating candidates by the approximation tolerances plus a numerical reserve keeps full-contour validation authoritative. More rotations/seeds alone had already failed to improve the old baseline.
- Related output fix: all-holes-first would release a host slug before its nested parts. CNC/DXF and the time service now sort by containment depth, deepest first, then holes and smaller exterior releases at equal depth. Dedicated regressions cover nested cuts and rounded-hole fillers.
- Lesson: measure the complete batch and exact remaining rectangle. Filling more holes alone may leave the same top extent; accept it as room for subsequent group moves, not as an invented additional percentage gain.

## Manual piece-use percentage differs from the sum of per-code areas

- Symptom on 2026-10-01: the manual report for the 31-piece batch states 36.469% use by reported mass, but summing its per-code areas yields about 35.636% of a 4.5 m² sheet.
- Verification: parsing the ten original DXFs gives 1.603563 m² of nominal piece material after holes are subtracted, or 35.635% of the sheet. The engine reports 36.47% outer-contour occupation, matching the manual mass percentage. These are different area conventions, not an optimization gain.
- Lesson: compare physical sheets and reusable rectangular remnants with the same rule. Do not divide percentages built from different area definitions or claim savings from that discrepancy. With mandatory 10 mm clearance, Pedido #13 currently leaves a smaller reusable rectangle than the manual arrangement shown in the image.

## Increasing clearance leaves a saved order unchanged until replanning

- Symptom: after requesting 10 mm between pieces, Pedido #12 still displayed the original 3 mm layout.
- Cause: thickness settings affect new nesting and editor validation, while the exported order retains its saved geometry and CNC. Increasing the clearance from 3 to 10 mm made its 35-piece single-sheet placement invalid.
- Solution: update all thickness profiles and the form minimum to 10 mm, search several part orders and both sheet orientations, then validate and reflow Pedido #12 as one order with 28 pieces on sheet #20 and seven on sheet #21. Independent Shapely measurements found minimum cut-contour gaps of 10.002 and 10.009 mm and 10 mm edge clearance. Preflight the CNC paths before changing inventory; then regenerate per-sheet CNC/DXF and confirm both PDFs.
- Lesson: a stricter safety setting can increase physical sheet consumption even after optimizing the batch. Compare total net area across sheets, not first-sheet occupancy alone, and never claim a material saving when none was measured.

## MySQL sort memory error on recent orders

- Symptom: the dashboard showed SQLSTATE HY001 / MySQL 1038 (`Out of sort memory`) while loading the ten latest orders, although the web server and database were running.
- Cause: `SELECT * FROM pedidos_corte ORDER BY created_at DESC LIMIT 10` required a filesort over large nesting JSON fields because `created_at` had no standalone index. The local MySQL sort buffer was 256 KiB.
- Solution: add an index on `pedidos_corte.created_at` and select only the fields used by the dashboard and order list. `EXPLAIN` now shows a backward index scan rather than filesort; both views render with the real MySQL data.
- Lesson: list queries must avoid loading and sorting complete nesting geometry; load it only for order details.

## Runtime compatibility

- Symptom: global PHP cannot run Laravel 13.
- Cause: XAMPP provides PHP 8.2.12; Laravel 13 requires PHP >= 8.3.
- Solution: project-local PHP CLI; scripts must explicitly select it.
- Lesson: do not run the application with an unqualified global `php` before configuring the shell environment.

## Composer autoload on this Windows workspace

- Symptom: `composer require` stalled after `Generating optimized autoload files`.
- Cause: optimizing this vendor tree is prohibitively slow on this Windows environment.
- Solution: set `optimize-autoloader` to `false`, then run `composer dump-autoload --no-scripts --no-plugins` and `php artisan package:discover`.
- Lesson: use normal nonoptimized autoload for local development; optimize only when deployment infrastructure can complete it.

## SQL percentage portability

- Symptom: the report test showed 0% for 2,500 mm² used on a 10,000 mm² sheet, while MySQL browser data appeared correct.
- Cause: SQLite performed integer division when the expression divided persisted whole-number decimal values before multiplying by 100.
- Solution: compute `area_pecas_mm2 * 100.0 / area_chapa_mm2` in SQL, with a zero-area guard.
- Lesson: use a floating-point factor before division in cross-database percentage queries.

## DXF parser environment in the Windows web server

- Symptom: DXF extraction passed in CLI tests but failed during an HTTP preview with a Python traceback before JSON output.
- Cause: the web server process did not provide a usable user home/configuration directory for Python dependencies.
- Solution: pass explicit writable `HOME`, `USERPROFILE`, `XDG_CONFIG_HOME` and `XDG_CACHE_HOME` paths under Laravel's private storage to DXF extraction, kerf offset and output generation processes. Read stderr before treating blank stdout as a JSON error.
- Lesson: test subprocesses through the real HTTP runtime as well as CLI tests when their inherited environment can differ.

## Generic preview error for open DXF contours

- Symptom: Otimizar showed only “Não foi possível gerar o preview” for an uploaded DXF; after adding an accented parser diagnostic, the web process still showed a generic geometry error.
- Cause: the uploaded drawing contained open or disconnected entities, so the parser correctly rejected it. The controller replaced that useful parser error with a generic message. On Windows, Python also emitted non-ASCII JSON through the web process with a locale encoding that PHP's UTF-8 JSON decoder could not parse.
- Solution: the parser now reports disconnected start/end coordinates and tolerance; the controller names the failing DXF and returns its geometry error; all Python DXF command-line tools emit ASCII-safe JSON escape sequences so PHP can decode accented messages reliably.
- Lesson: keep invalid cut contours rejected rather than silently omitting or closing them. Test both valid and invalid uploads through the real local HTTP/browser path, including non-ASCII diagnostics.

## Preview looks blank before scrolling

- Symptom: the optimized sheet outline appeared in the initial browser viewport, but its parts seemed missing.
- Cause: the nesting engine correctly packed parts against the bottom edge of a Y-up sheet; summary cards and sheet tabs pushed the canvas far enough down the page that this edge was below the initial viewport.
- Solution: show the shorter canvas immediately after the sheet description, center the sheet horizontally and move summary cards below the editor. A batch with no positioned parts now displays an explicit message in the canvas.
- Lesson: verify the actual initial viewport for bottom-packed geometry, not only full-page screenshots or the presence of canvas nodes.

## Detailed DXFs exhaust nesting time before the second part

- Symptom: nine requested instances from three real DXFs fit easily on a 3,000 × 1,200 mm sheet, but the first optimization placed only one and reported eight unplaced after the 30-second deadline.
- Cause: a Minkowski sum was computed for every pair of detailed contours, and polygon clipping was repeated even for obviously disjoint bounding boxes and a rectangular sheet.
- Solution: use bounding-box contact candidates for contours over 48 vertices, keep Minkowski candidates for simpler contours, skip exact overlap checks for disjoint boxes, and check rectangular-sheet bounds directly. Reorganization then placed all nine instances. A dense-outline regression test covers this case.
- Lesson: preserve exact contours in the result and CNC, while using conservative coarse placement candidates when detailed geometry makes contact generation too expensive.

## Rounded placement briefly flagged safe parts as too close

- Symptom: a two-piece preview with 5 mm requested clearance displayed both parts as colliding immediately after the engine positioned them.
- Cause: the engine rounds positions to 0.001 mm. A compensated contour measured 419.57413 mm wide, so an intended 5 mm gap became 4.99987 mm in the saved placement; the client and validator had a tighter 0.00001 mm tolerance.
- Solution: add 0.002 mm to generated clearance contacts and use a 0.001 mm numerical tolerance consistently in the optimizer, manual validator and canvas display. Replanning showed both parts in the normal state and manual order changes saved successfully.
- Lesson: quantization tolerances must match the coordinate precision used for persisted positions.

## Detailed profiles fit but waste the reusable remnant

- Symptom: Pedido #5 placed 24 real parts on a 3,000 x 1,200 mm sheet, yet its conservative retalho was only 253,005 mm2 and the outlines looked widely separated compared with a manual layout.
- Cause: the timed Node search used bounding-box contacts for detailed drawings. It could fit every piece but could not exploit the concave contours; naive full-detail Minkowski trials exceeded the time limit before reaching a complete layout.
- Solution: run a bounded contact search on sampled outlines in Python, test each candidate against the complete polygons with Shapely, retain bounding-box fallback positions, and accept only a validated full proposal with a larger reusable rectangle. Reorganizing Pedido #5 kept 24/24 pieces and increased the registered retalho to 345,385 mm2; the CNC lead plan was verified before saving.
- Lesson: a complete layout is not necessarily economical. Compare the reusable remnant area, and keep a safe complete incumbent while exploring tighter contacts.

## Independent spacing prevents contour nesting

- Symptom: after the first contour-aware improvement, the optimizer still wasted sheet area whenever a nonzero clearance was selected.
- Cause: automatic and manual validators treated any bounding-box overlap as a collision when piece spacing was positive, even when the real cut contours were separate. The Python contact search also skipped contour contacts under that condition.
- Solution: derive clearance from kerf, compare the distance between complete compensated cut contours, restore Minkowski contact candidates with a kerf offset, and compact complete layouts against exact geometry. Keep the sheet-edge margin as the separate safe cutting rectangle, including CNC entry/exit paths.
- Verification: 24/24 pieces of Pedido #5 on sheet #6 passed Node geometry and 41-path CNC validation. The saved usable remnant rose from about 345,385 to 721,430 mm² while the sheet remained debited once and the existing remnant record was updated.
- Lesson: compare cutter paths directly; axis-aligned boxes are only a broad-phase filter, not a physical clearance rule for irregular profiles.

## Irregular remnant edge margin was only checked against its bounding box

- Symptom: a piece could remain inside a concave remnant while lying closer to its actual outline than the configured safe margin; the rectangular bounds check still passed.
- Cause: the nesting and manual validators checked full containment but applied the margin only to the sheet's rectangular bounding dimensions.
- Solution: retain full-polygon containment and measure the distance between every external piece segment and the real remnant outline. The Python lead-in planner also tests the real outline along each proposed entry path. A regression test rejects a 2 mm clearance when 5 mm is required.
- Lesson: safety margins on remnants must follow the physical sheet boundary, not only its width and height.

## Pedidos #8/#9 parecem grudados ou espaçados de modo desigual

- Sintoma: no lote de aço carbono de 1 mm, algumas peças parecem coladas na Chapa 1 e outras distantes na Chapa 2.
- Diagnóstico geométrico em 2026-09-28: os Pedidos #8/#9 usam kerf cadastrado de 0,1 mm, distância mínima entre trajetórias de 0,6 mm e margem de 0,6 mm. A menor distância medida entre trajetórias compensadas foi 0,602 mm em ambas as chapas; a Chapa 1 tem 20 peças e uso geométrico de 52,06%, a Chapa 2 tem 15 peças e 17,87%. Na Chapa 2, a mediana da distância ao vizinho mais próximo foi 11,494 mm e o maior vizinho mais próximo 35,899 mm. A faixa inteira livre à direita mede 1.190,847 mm e é registrada como retalho.
- Causa da percepção: 0,6 mm é um mínimo entre linhas centrais do corte, não a distância igual entre todos os pares. Com kerf nominal de 0,1 mm, a ponte intacta no ponto mínimo seria aproximadamente 0,5 mm. O algoritmo privilegia uma faixa inteira reutilizável à direita; o desenho completo de 3 m reduz uma folga de 0,6 mm a menos de um pixel.
- Solução em 2026-09-28: o usuário definiu explicitamente 3 mm de folga mínima entre contornos, separada do kerf. O perfil local de aço carbono de 1 mm passou de 0,6 para 3 mm. A busca atual com 3 mm gerou um encaixe muito mais coeso para a Chapa 2: distância ao vizinho mais próximo de 2,999 a 4,214 mm, contra até 35,899 mm antes; o retalho direito passou de 1.190,847 para 1.832,642 mm. Na Chapa 1, a faixa de 3,002 a 9,304 mm reflete o formato das peças, sem violar o mínimo. Ambos os pedidos foram validados geometricamente, tiveram trajetos CNC planejados e foram salvos com CNC/DXF regenerados; não houve nova baixa de estoque. `output/pedidos-8-9-proposta-folga-3mm.png` registra a disposição. Kerf real de máquina e prova na controladora continuam pendentes.
- Lição: uma folga térmica mínima muito baixa pode produzir peças que parecem coladas; uma reotimização com a folga correta também pode mudar favoravelmente o encaixe e o retalho. Não bloquear uma recalibração mais segura só porque o retalho de um pedido diminuiu.

## Otimizar informa motor indisponível após editar os Pedidos #8/#9

- Sintoma em 2026-09-28: ao criar um novo preview, a tela dizia que o motor não respondeu, apesar de `/health` responder 200.
- Causa: o motor devolvia HTTP 422 porque o retalho #11 tinha largura gravada de 1.832,642 mm e contorno até 1.832,64212 mm. A largura DECIMAL(8,3) arredondou, mas o JSON preservou mais casas. O controlador tratava qualquer `RequestException`, inclusive uma rejeição geométrica, como falta de conexão. A prioridade dada a retalhos fazia esse registro bloquear o lote inteiro.
- Solução: normalizar e arredondar o contorno de novos retalhos na mesma grade de 0,001 mm da largura e altura, reparar contornos antigos que excediam apenas pelo arredondamento e exibir a mensagem geométrica retornada pelo motor quando houver HTTP 422. O retalho #11 passou a ter máximo X de 1.832,642 mm; uma chamada real a `ArranjoPreview::planejar` posicionou uma peça nele. Todas as chapas disponíveis passaram na verificação de limites.
- Lição: uma resposta HTTP de validação não é indisponibilidade de serviço; reportar sua causa. Dados geométricos persistidos precisam respeitar a precisão das dimensões com que são validados.

## Perfis estreitos deixam espaço evitável na Chapa 2 do Pedido #9

- Sintoma em 2026-09-28: mesmo após configurar 3 mm de folga, um perfil longo permanecia saliente à direita da Chapa 2, deixando a largura ocupada em 1.167,358 mm.
- Causa: a compactação antiga só movia peças ao longo de X ou Y a partir da posição atual. Mover o perfil para uma cavidade livre exigia saltar simultaneamente para outro X e Y; a busca por ordem/rotação não descobriu esse reposicionamento mesmo com 50 segundos.
- Solução: após a compactação, testar a peça que define a extremidade direita em candidatos de contato NFP e caixas com todas as demais peças, validando os contornos completos e a folga mínima antes de aceitar uma largura menor. Um refinamento local move o perfil mais alguns milímetros até reduzir a distância ao vizinho. Reservar orçamento separado para compactação e realocação. O Pedido #9 passou de 1.167,358 para 1.020,167 mm ocupados, com 15/15 peças; a maior distância ao vizinho mais próximo caiu de 4,214 para cerca de 3,1 mm, mantendo o mínimo de 3 mm. O retalho direito foi atualizado de 1.832,642 para 1.979,833 mm. CNC/DXF foram regenerados, sem nova baixa de estoque.
- Lição: compactar uma peça ao longo dos eixos não encontra todos os bolsões livres; uma realocação discreta pode aumentar o retalho sem reduzir a distância segura.

## Contornos vermelhos parecem colados no preview do Pedido #8

- Sintoma em 2026-09-28: no recorte enviado, os trajetos das peças `05405 · 13` e `05411` aparentam se tocar.
- Verificação: nos contornos compensados e posições salvas do Pedido #8, a distância exata entre `05405 · 13` e a `05411` vizinha é 3,0235 mm. O menor par de todo o pedido mede 3,002 mm. O arranjo respeita a folga configurada de 3 mm.
- Causa visual: o editor desenhava cada corte vermelho com 2,5 pixels fixos e cada borda com 2 pixels. Na escala de uma chapa de 3.000 mm, uma folga de 3 mm pode ocupar menos de um pixel; os traços sobrepunham visualmente a folga real.
- Solução: reduzir os traços do corte e dos contornos para 1 pixel e acrescentar zoom até 16×, roda do mouse e Alt-arrastar para examinar localmente. O visual não altera geometria, CNC nem estoque. O build de produção e os cinco testes de edição de pedido passaram.
- Lição: largura de traço em tela não representa kerf físico; apresentar ferramentas de inspeção quando folgas em milímetros são subpixel na vista da chapa inteira.

## Mais peças na primeira chapa podem aumentar o consumo do lote

- Sintoma em 2026-09-29: o lote real dos Pedidos #8/#9 tem 35 peças em duas chapas físicas iguais de 3.000 × 1.200 mm. O motor rápido posiciona 20 na primeira; reduzir o número de candidatos da busca detalhada produziu uma alternativa válida com 25 na primeira.
- Causa: a busca privilegia número de peças posicionadas em cada chapa. A alternativa colocou peças menores primeiro e deixou perfis maiores para a segunda chapa.
- Evidência: com a divisão experimental 25+10, os limites ocupados foram 2.906,981 e 1.937,213 mm, respectivamente, equivalentes a 5,813 m² líquidos com retalhos inteiros à direita. Os pedidos atuais 20+15 ocupam 2.960,344 e 1.020,167 mm, equivalentes a 4,777 m² líquidos. A alternativa gastaria aproximadamente 21,7% mais; não foi salva nem incorporada ao motor.
- Lição: o objetivo precisa comparar o lote completo e valorizar retalhos realmente reutilizáveis. Uma heurística que só aumenta a contagem da primeira chapa pode piorar o resultado global.

## Benchmark contra CNC manual das 35 peças

- Sintoma em 2026-09-29: quatro DXFs nas quantidades 10/10/10/5 cabem no CNC manual em uma chapa de 1.500 × 3.000 mm, mas o motor automático só posiciona 25 na primeira e 10 na segunda.
- Verificação: o CNC tem 65 pares M07/M08 (35 contornos externos e 30 furos), alcança X=1.491,298 e Y=2.989,829 mm, e o menor afastamento entre seus contornos externos amostrados é cerca de 3,276 mm. O DXF retangular mede 251,5 × 130 mm; seu percurso manual mede 253,5 × 132 mm, indicando 2 mm de kerf nominal. O teste foi refeito com kerf 2 mm e folga 3 mm.
- Resultado: o pedido local `teste` usa duas chapas de 4,5 m². Registra 0,094605 m² e 3,742539 m² de retalhos retangulares, logo consome 5,162856 m² líquidos contra 4,5 m² da chapa manual: 14,73% **mais** material. Não declarar melhoria; a meta de reduzir consumo permanece aberta.
- Causa provável: a busca automática só experimenta rotações 0/90/180/270°, enquanto o manual inclina alguns perfis estreitos; a busca por lotes completos também privilegia a contagem posicionada localmente, não o consumo global das duas chapas. Essa hipótese precisa ser testada antes de alterar o motor. Não usar o layout manual importado como se fosse uma otimização independente.

## Busca detalhada descartava DXFs com furos antes do contorno externo

- Sintoma em 2026-09-30: o motor de qualidade retornava erro de contorno inválido para os DXFs `05405` e `05401_03`; o Node silenciosamente mantinha seu encaixe parcial de 25 peças, embora o CNC manual provasse que 35 cabem na chapa.
- Causa: o parser/offset preserva a ordem das entidades. Nesses dois arquivos, os furos aparecem antes da borda externa; `quality_nesting.py` construía `Polygon(rings[0], rings[1:])` sem ordenar por área, ao contrário do normalizador Node.
- Solução: ordenar anéis por área absoluta antes de formar o polígono. Acrescentar contato horizontal calculado sobre contornos completos e um candidato limitado de fileiras alternadas, faixa de perfis estreitos e duas colunas intercaladas para lotes repetitivos. Todos os candidatos passam pelo validador completo do Node; o algoritmo não importa coordenadas do CNC manual.
- Verificação: os quatro DXFs nas quantidades 10/10/10/5 formam 35/35 em uma chapa 1.500 × 3.000 mm, com 3.002 mm de menor folga real. Pedido #12 e CNC de 65 ciclos foram gerados sem erro. A redução líquida é 13,65% contra o automático anterior de duas chapas. Pela mesma regra de maior retalho retangular aplicada ao CNC manual e ao novo arranjo, o ganho geométrico frente ao manual é 0,357% (4,473894 contra 4,457908 m² líquidos); as faixas de 8,702 mm e 28,062 mm ainda não têm reaproveitamento econômico comprovado. Ambos consomem uma chapa física.
# Interior cutout search and the 31-piece manual CNC — 2026-10-02

- Symptom: the automatic search missed placements inside large internal cutouts even though manual placement validation allowed them. Its Python search proposed contacts from exterior rings only and optimized occupied width, so bottom-packed arrangements with larger top remnants were overlooked. The Node fallback additionally treated holes as solid during its fast automatic candidate search.
- Solution: generate bounded anchors inside holes, run bottom-up as well as left-first quality layouts, choose the largest full-width/full-height rectangular remnant, and relocate high protrusions through a coarse grid. All candidate placements still pass exact full-contour validation and preserve the 10 mm configured cut-contour gap. Add a 0.005 mm numerical reserve in the Python search to avoid Node rejecting borderline rounding results.
- Benchmark: the same ten original DXFs (31 pieces), 1,500 × 3,000 mm A36 sheet, 2 mm kerf and 10 mm cut-contour clearance produced a Node-validated one-sheet result with two pieces inside the `16972_01` cutout and a 1.969500 m² top remnant. The generated CNC has 69 cut cycles and a top cut extent of Y=1,687.000 mm. The saved Pedido #13 had a 1.6792215 m² registered remnant; at equal sheet price per m² and assuming both remnants are reusable, the proposal reduces estimated net sheet consumption from 2.8207785 to 2.5305000 m² (10.29%). The saved order was not modified by this offline benchmark.
- The manual CNC supplied on 2026-10-02 has 69 cycles and a top cut extent of Y=1,629.342 mm, giving a 2.055987 m² full-width free strip under the same envelope rule. Its net estimated consumption is 2.444013 m², **3.54% less than the new automatic proposal**. Several distinct manual cut cycles are 2–3 mm apart (for example cycles 26/27, 2.115 mm), so the manual layout does not obey the system's 10 mm clearance and is not an equal-safety benchmark. Both arrangements use one sheet; if the remnants are not economically reusable, physical-sheet savings are 0%. Do not present 10.29% as a gain over the manual CNC or claim that the new algorithm is globally optimal.

# Comparable-spacing 31-piece order — 2026-10-02

- The blanket 10 mm spacing caused an apples-to-oranges comparison with `A36_19,05MM_1.CNC`: distinct external manual trajectories can be 2.115 mm apart. For this A36 19.05 mm profile alone, use 2.1 mm between compensated contours and from cut paths to the sheet edge, keeping the 2 mm kerf inferred from DXF/CNC offsets. Manual cutting at Y=0 remains a boundary difference; the automatic 2.1 mm margin is more conservative there. The CNC does not encode an authoritative machine-kerf setting, so this is a geometric inference.
- A 45-second search left only 1.986239 m² of intact top remnant; 110 seconds and nine candidate attempts found 2.087622 m². This demonstrates search-budget sensitivity, not global optimality. Node `/avaliar` accepted the final 31 positions with 2.1 mm minimum separation and two nested pieces; CNC path planning accepted 69 cycles. The saved Pedido #15 has one source sheet, 2.087622 m² registered remnant and no generation error.
- Manual top strip: 1,500 × 1,370.658 mm = 2.055987 m². Automatic top strip: 1,500 × 1,391.748 mm = 2.087622 m². With equal 4.5 m² source sheets, estimated net consumption is 2.444013 versus 2.412378 m², so `(2.444013 - 2.412378) / 2.444013 = 1.2944%` less material tied up if those full-width remnants are actually reusable. Nominal part area is unchanged and physical sheet-count saving is 0%; do not call the 1.2944% a verified cash saving.

# Why Pedido #15 looks half empty — 2026-10-02

- The engine's 36.47% is the area inside the compensated piece contours divided by the whole 4.5 m² sheet. That number cannot improve by rearranging the same 31 shapes. The whole 1,500 × 1,391.748 mm top strip is a registered 2.087622 m² remnant (46.39%). The remaining approximately 0.771226 m² (17.14% of the original sheet) consists of inter-piece voids, holes and clearance within the occupied envelope. This is the geometric target for better packing; economic benefit from the top strip requires later reuse.
- Tests at 2.1 mm with 45° rotations, a 250-candidate cap, and alternative seeds at 110 seconds returned smaller top remnants (1.937043, 1.948767, 2.026328 and 2.043852 m²) than the current 2.087622 m². The experimental engine branches were not retained. Increasing angles or random attempts alone has no demonstrated gain on this lot. A partial remove/reinsert or swap search is a separate next hypothesis, inspired by published local-search approaches, and must be benchmarked before changing a saved order.

## Preview do site falha com DXFs válidos quando o Node remoto encerra — 2026-10-05

- Sintoma: `Não foi possível gerar o preview. Verifique os DXFs e tente novamente.` para um DXF válido na hospedagem UNI5.
- Causa: o log Laravel registrava `ConnectionException` na ponte Python `http://127.0.0.1:30101/python`; o serviço Node remoto havia sido encerrado pela hospedagem. Um túnel SSH reverso foi recusado e o banco remoto não aceitava conexão direta do IP do PC.
- Solução: ativar um worker local que consulta por SFTP uma fila de arquivos privados no servidor, executa Node/Python no PC e publica respostas; o Laravel usa a ponte para preview e geração de arquivos. A tarefa `NestLaserPcWorker` inicia no login e reinicia após falha. A tela distingue PC desconectado de DXF inválido. Um envio web real posicionou a peça e a geração de PDF também passou.
- Lição: mensagem de erro de DXF não prova defeito geométrico. Verificar primeiro o log e a disponibilidade da dependência externa; em hospedagem compartilhada, processo em segundo plano sem supervisão pode desaparecer mesmo após um teste bem-sucedido.

## Tarefa local de worker interrompida sem reinício imediato — 2026-10-05

- Sintoma: após funcionar inicialmente, o site mostrou `O computador de otimização está desconectado`. No PC, o Node respondia em `127.0.0.1:31999`, mas a tarefa `NestLaserPcWorker` estava `Ready`, o Python não rodava e o heartbeat privado expirara.
- Diagnóstico: o último resultado do Agendador foi `3221225786` (processo interrompido), sem nova linha no log após a última tarefa concluída. Não há evidência suficiente para afirmar quem interrompeu o processo.
- Solução: acrescentar gatilho repetido a cada minuto à tarefa, mantendo o gatilho de login e `MultipleInstances=IgnoreNew`. Uma parada controlada foi seguida de reinício automático no minuto seguinte e um novo preview web completou.
- Lição: o gatilho de login e a política de reinício por falha podem não cobrir todas as formas de término; testar explicitamente a recuperação após parar o worker.

## Timeout de preview com motor Node saudável, mas worker SFTP encerrado — 2026-10-09

- Sintoma: o site informou que o computador de otimização não concluiu o cálculo a tempo. A tarefa manual estava encerrada (`Ready`, resultado 1), mas o Node ainda respondia localmente.
- Causa observada: o log terminou após `SSHException` de SFTP; sem processo Python, o heartbeat que havia sido publicado fez o Laravel aceitar a tarefa e esperar até o timeout. O motivo exato da saída com código 1 não apareceu no log antigo.
- Solução: ampliar a recuperação de exceções no loop SFTP, proteger o fechamento da conexão e reiniciar o worker filho dentro do launcher da tarefa manual após uma saída inesperada. Não adicionar gatilhos de login ou periódicos, conforme preferência do usuário.
- Verificação: após matar o processo filho, a mesma tarefa manual permaneceu ativa, o novo filho se conectou em segundos, o PDF web foi gerado e um cálculo de nesting pela fila SFTP posicionou 3/3 peças. A tela de novo arranjo não tinha chapas disponíveis para um teste de preview novo.

## Múltiplos consumidores e falsos alarmes de conexão no worker manual — 2026-10-09

- Sintomas: o log mostrou `SSHException`/falha de DNS, várias mensagens de motor local ativo sem mudança do PID Node, e um `FileNotFoundError` após um teste apagar seu pedido temporário. Parar a tarefa do Windows não matou sempre o Python filho; reiniciar diretamente criou dois consumidores SFTP.
- Causas: a tarefa agendada não garantia o encerramento da árvore de processos; o probe `/health` de 1 s interpretava lentidão como ausência do Node; o loop de fila confundia um pedido cancelado pelo Laravel com falha de conexão. Respostas antigas de requisições encerradas também ficavam na fila.
- Solução: eliminar filhos órfãos antes de iniciar um novo consumidor e usar um script de parada que também encerra Node; esperar mais pela saúde do Node e evitar novo processo quando a porta dedicada está aberta; tratar `FileNotFoundError` de pedido como cancelamento; atualizar heartbeat entre itens e expirar arquivos temporários com mais de uma hora. O launcher não transforma stderr nativo do Python em exceção PowerShell fatal.
- Verificação: um reinício deliberado trocou os PIDs órfãos por um só par Python; quatro testes unitários Python passaram; três cálculos consecutivos e dois cálculos simultâneos com PDF web responderam normalmente. Quedas futuras de rede externa ainda podem interromper uma operação ativa, pois a hospedagem de teste depende de SFTP e do PC.

