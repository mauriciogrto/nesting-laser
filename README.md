# Otimizador de nesting a laser

Sistema web para encaixar automaticamente peças de arquivos DXF em chapas de corte a laser. Controla estoque de chapas e retalhos, compensa a largura do corte (kerf) e gera CNC, DXF e PDF para cada chapa.

## Como é dividido

| Camada | Tecnologia | O que faz |
| --- | --- | --- |
| Aplicação | Laravel 13, PHP 8.3, MySQL | Telas, login, pedidos, estoque, fila de cálculo |
| Leitura de DXF e saídas | Python (`ezdxf`, `pyclipper`, `shapely`, `reportlab`) em `services/dxf-parser` | Extrai a geometria, aplica o offset do corte e gera CNC, DXF e PDF |
| Motor de encaixe | Node em `services/nesting-engine` | Posiciona as peças (NFP, busca genética) e valida a sobreposição |
| Worker remoto (opcional) | `scripts/pc-worker.py` | Executa o cálculo em outro computador, ligado ao servidor por SFTP de saída |

O cálculo pode rodar de três formas: processo local (com argumentos em lista, sem shell), ponte HTTP com token ou worker remoto por SFTP.

## Como rodar

Requisitos: PHP 8.3, Composer 2, Node 22, Python 3.12, MySQL 8.

```bash
cp .env.example .env
composer install
php artisan key:generate
npm install && npm run build
python -m venv .venv && .venv/bin/pip install -r services/dxf-parser/requirements.txt
(cd services/nesting-engine && npm install)
```

Crie o banco, ajuste `DB_*` e defina `SEED_USER_PASSWORD` (12+ caracteres) no `.env`; depois:

```bash
php artisan migrate
php artisan db:seed
php artisan serve
```

O cadastro público é desabilitado. O acesso inicial vem de `SEED_USER_EMAIL` e `SEED_USER_PASSWORD`.

## Testes

```bash
php artisan test
(cd services/nesting-engine && npm test)
(cd services/dxf-parser && python -m unittest discover -s tests -v)
```

## Documentação de trabalho com agentes

`AGENTS.md` traz as regras permanentes para o agente de código. Em `docs/` ficam o hub de leitura (`00-PROJECT-HUB.md`), a arquitetura, as decisões e o estado validado de cada fase. `docs/prompts-codex-sistema-nesting.md` é o roteiro por fases.

## Limites

Falta homologar com arquivos reais antes de enviar o resultado a uma máquina de corte. Os documentos em `docs/` descrevem o ambiente de teste de origem com caminhos genéricos.

## Validação da cópia pública

Em 10/11/2026, a revisão concluiu 68 testes PHP e 546 asserções, 23 testes Node e 28 testes Python. O build da interface foi gerado com sucesso.

A suíte PHP usa o motor Node em execução. Configure o mesmo `NESTING_ENGINE_TOKEN` no Laravel e no serviço, instale as dependências e inicie o motor em `services/nesting-engine` antes dos testes. Defina `DXF_PYTHON_BIN` para o interpretador Python com os pacotes do parser e `NESTING_PYTHON_BIN` para o serviço Node.

Os testes passaram a usar `DXF_PYTHON_BIN`, com `python3` como padrão, no lugar de um caminho de ambiente local. As asserções foram preservadas.

[Estudo de caso e arquitetura](https://grxsistemas.com.br/portfolio/nesting-laser)