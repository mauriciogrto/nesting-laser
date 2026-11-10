<?php

namespace App\Enums;

enum StatusPedido: string
{
    case Rascunho = 'rascunho';
    case AguardandoNesting = 'aguardando_nesting';
    case Pronto = 'pronto';
    case EmProducao = 'em_producao';
    case Concluido = 'concluido';
    case Erro = 'erro';
}
