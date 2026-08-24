<?php

declare(strict_types=1);

namespace App\Security\Detectors;

use Illuminate\Http\Request;

class CookieDetector extends BaseDetector
{
    private const MALICIOUS_PATTERNS = [
        '/<script/i',
        '/javascript:/i',
        '/onerror=/i',
        '/union.*select/i',
        '/drop\s+table/i',
        '/;\s*(ls|cat|wget|curl)\b/i',
        '/\|\s*bash/i',
        '/<\?php/i',
        '/\beval\s*\(/i',
        '/\.\.\//',
        '/%3Cscript/i',
    ];

    public function detect(Request $request, array $inputs): array
    {
        $findings = [];

        foreach ($request->cookies->all() as $name => $value) {
            if (! is_string($value)) {
                continue;
            }

            foreach (self::MALICIOUS_PATTERNS as $pattern) {
                if (preg_match($pattern, $value)) {
                    $findings[] = $this->finding('malicious_cookie', "Malicious pattern detected in cookie: {$name}", $value, 8);
                    break;
                }
            }

            // PHP serialized object injection.
            if (preg_match('/^(a|O):\d+:/i', $value)) {
                $findings[] = $this->finding('serialized_cookie', "Serialized object in cookie: {$name}", $value, 7);
            }

            // Oversized cookie values are a common smuggling vector.
            if (strlen($value) > 8192) {
                $findings[] = $this->finding('oversized_cookie', "Oversized cookie value: {$name}", 'length: '.strlen($value), 6);
            }
        }

        return $findings;
    }
}
