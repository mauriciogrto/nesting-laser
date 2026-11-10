<?php

namespace App\Enums;

enum StatusProcessamento: string
{
    case Pendente = 'pendente';
    case Processado = 'processado';
    case Erro = 'erro';
}
