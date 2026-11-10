<?php

namespace App\Enums;

enum TipoMovimentacao: string
{
    case Entrada = 'entrada';
    case Baixa = 'baixa';
    case GeracaoRetalho = 'geracao_retalho';
}
