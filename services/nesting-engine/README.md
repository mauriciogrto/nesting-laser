# Motor de nesting

Serviço Node headless. Instale com `npm install`, copie `.env.example` para `.env` se quiser alterar os limites, e inicie com `npm start`. Execute `npm test` para os casos de geometria. O serviço escuta por padrão em `127.0.0.1:3001` e expõe `GET /health` e `POST /nesting`.

```json
{
  "chapa": {"largura_mm": 1000, "altura_mm": 2000},
  "pecas": [{"id": 1, "contorno": [[0,0],[200,0],[200,100],[0,100]], "quantidade": 3}]
}
```

`contornos` pode ser usado no lugar de `contorno` quando a peça contém furos. O retorno inclui `pecas_posicionadas` (com `instancia`, `id`, `x`, `y`, `rotacao_graus` e os contornos compensados normalizados), `pecas_nao_posicionadas`, `area_utilizada_pct` e `sobra_contorno`. Coordenadas `x,y` representam a translação do desenho normalizado após a rotação, para o canto inferior esquerdo da caixa da peça. O limite de 100 instâncias por pedido evita trabalho ilimitado na API local.

## Escolha do motor

Adaptação headless da estratégia SVGnest: o `clipper-lib` calcula candidatos no no-fit polygon com soma de Minkowski, e uma busca genética testa ordens e rotações. `polygon-clipping` valida cada posicionamento com polígonos reais antes de aceitá-lo, inclusive para contornos côncavos e chapa irregular. Para chapas retangulares com contornos detalhados, uma busca adicional em Python usa contatos de contornos simplificados, valida cada peça com a geometria completa e respeita a folga térmica configurada. Ela compacta o arranjo, tenta realocar a peça mais saliente para uma cavidade livre e ajusta localmente a posição para reduzir folgas ainda excessivas, sempre verificando a distância mínima. O resultado Python só substitui o arranjo base se mantiver ou aumentar a quantidade posicionada, for válido e melhorar o retalho. A busca adicional tem limite de 32 segundos.

O [SVGnest original](https://github.com/Jack000/SVGnest) usa browser workers. O [port Node](https://github.com/FrankSandqvist/svg-nest) ainda se descreve como incompleto; o [fork Deepnest para Node](https://github.com/ShaMan123/Deepnest) precisa de bindings C++ e versões antigas de Node/Python. A implementação local combina o serviço Node com o Python já usado para geometria DXF.

O aproveitamento representa a área geométrica das peças dividida pela área da chapa; `sobra_contorno` é uma aproximação retangular conservadora, gerada apenas para chapas retangulares. A saída parcial lista explicitamente as peças não posicionadas para que o pedido não seja tratado como pronto.

O posicionamento rápido começa na borda esquerda. Para lotes detalhados em chapas retangulares, a busca de qualidade também tenta preencher a base da chapa, propõe posições dentro de vazios internos grandes, compacta os contornos e procura novas posições para peças salientes. Cada candidato é validado pelos contornos compensados completos e pela distância configurada. Entre arranjos completos, prevalece o maior retalho retangular íntegro no topo ou à direita; a preferência esquerda-direita só decide empates. A busca adicional tem limite de 45 segundos e o resultado rápido permanece disponível caso uma proposta detalhada falhe na validação.
