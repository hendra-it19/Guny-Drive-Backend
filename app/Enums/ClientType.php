<?php

namespace App\Enums;

enum ClientType: string
{
    case Device = 'device';
    case Integration = 'integration';
}
