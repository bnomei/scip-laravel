<?php

declare(strict_types=1);

namespace App\View\Components\Acceptance;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Banner extends Component
{
    public function __construct(
        public string $type = 'info',
        public string $message = '',
    ) {
    }

    public function render(): View
    {
        return view('components.acceptance.banner');
    }
}
