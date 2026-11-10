# DXF parser

Instale com `python -m pip install -r requirements.txt` e execute:

```bash
python extrair_geometria.py caminho/arquivo.dxf --tolerancia-mm 0.05
```

O script lê o modelspace, converte LINE, LWPOLYLINE, POLYLINE, ARC, CIRCLE e SPLINE em polígonos fechados e devolve `bounding_box` e `contornos` em milímetros. Arcos e splines são aproximados com a tolerância informada. Segmentos abertos podem ser unidos quando as extremidades coincidem dentro da tolerância. Um desenho que ainda contém contornos abertos retorna erro JSON e código de saída 1.

O valor DXF `$INSUNITS=0` (sem unidade declarada) é interpretado como milímetros. Unidades conhecidas são convertidas para mm; unidades desconhecidas geram erro para evitar dimensões erradas.

Teste isolado: `python -m unittest discover -s tests -v`.

## Offset de kerf

`python aplicar_offset.py` lê JSON de stdin como `{"contornos": [[[x,y],...], ...], "offset_mm": -0.1, "join_type": "miter"}` e devolve `{"contornos": [...]}`. `join_type` pode ser `miter` (padrão), `round` ou `square`. O sinal é aplicado ao contorno externo; furos internos recebem o sinal oposto. A compensação padrão do sistema para `kerf_mm` será `offset_mm = -kerf_mm / 2`: o contorno externo diminui uma largura total igual ao kerf, e os furos aumentam. O desenho original da peça permanece intacto. Um offset que colapsa a geometria ou abre um furo para fora do contorno retorna JSON de erro e exit code 1.

Para conferir visualmente o retângulo com furo: `python -m pip install matplotlib` e `python tests/plot_offset.py offset-validation.png`.

## DXF de saída

`python gerar_dxf_saida.py caminho/resultado.dxf` lê JSON de stdin com `chapa` e `pecas_posicionadas`. Cada peça precisa carregar os `contornos` já compensados, a posição `x,y` e a `rotacao_graus`. O script aplica a mesma rotação, normalização pela bounding box e translação do motor de nesting e do editor. O DXF declara milímetros e tem layers `CHAPA`, `PECAS` (trechos efetivamente cortados), `PERFURACAO` (pontos), `LEAD_IN` (aproximações), `LEAD_OUT` (saídas quando presentes) e `TABS` (gaps não cortados). Ele é um desenho técnico complementar; o arquivo entregue à controladora continua sendo `.cnc`.

O DXF e o CNC compartilham o mesmo planejador. `lead_in_mm` define o comprimento da entrada a partir de um canto/reentrância que tenha espaço livre; se não houver entrada segura, a geração falha. A sequência corta todos os furos internos primeiro e os externos depois, começando pelos perfis menores para adiar a liberação dos maiores. Não há cortes de esqueleto independentes. Com `usar_tabs=true`, `tamanho_tab_mm` deixa uma lacuna aberta em cada contorno externo; a layer `TABS` mostra visualmente o trecho preservado e a layer `PECAS` permanece aberta nesse ponto. O plano é uma estimativa geométrica e exige calibração/validação na máquina antes do corte físico.
