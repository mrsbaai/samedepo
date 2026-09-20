<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Vite;
use Throwable;

final class CryptoIcon
{
    private const DIRECTORY = 'node_modules/cryptocurrency-icons/svg/color';

    /**
     * Public URL for an icon in the atomiclabs cryptocurrency-icons set
     * (e.g. 'usdt', 'trx'). Files are emitted into the Vite build by the
     * import.meta.glob in resources/js/app.js.
     */
    public static function url(string $icon): string
    {
        return Vite::asset(self::DIRECTORY.'/'.$icon.'.svg');
    }

    /**
     * Raw SVG markup for server-side embedding (QR code logos). Reads the
     * npm package directly when present, otherwise the built asset.
     */
    public static function svg(string $icon): ?string
    {
        $path = base_path(self::DIRECTORY.'/'.$icon.'.svg');

        if (! is_file($path)) {
            try {
                $path = public_path(ltrim((string) parse_url(self::url($icon), PHP_URL_PATH), '/'));
            } catch (Throwable) {
                return null;
            }
        }

        if (! is_file($path)) {
            return null;
        }

        return file_get_contents($path) ?: null;
    }
}
