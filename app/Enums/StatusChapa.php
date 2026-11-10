<?php

namespace App\Enums;

enum StatusChapa: string
{
    case Disponivel = 'disponivel';
    case Reservada = 'reservada';
    case Baixada = 'baixada';
}
