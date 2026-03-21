<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Config;

enum RuntimeMode: string
{
    case Safe = 'safe';
    case Full = 'full';
}
