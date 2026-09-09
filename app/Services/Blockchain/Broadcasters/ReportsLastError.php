<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Broadcasters;

interface ReportsLastError
{
    public function lastError(): ?string;
}
