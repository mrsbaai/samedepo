<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers;

/**
 * Backwards-compatible alias for the pre-MA04 name.
 * Prefer EvmLogsProvider — the driver is chain-agnostic.
 */
class InfuraProvider extends EvmLogsProvider {}
