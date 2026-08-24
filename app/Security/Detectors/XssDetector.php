<?php

declare(strict_types=1);

namespace App\Security\Detectors;

use Illuminate\Http\Request;

class XssDetector extends BaseDetector
{
    // High-confidence executable payloads: block.
    private const BLOCKING_PATTERNS = [
        '/<script[\s\S]*?>/i',
        '/<iframe[\s\S]*?>/i',
        '/<embed[\s\S]*?>/i',
        '/<object[\s\S]*?>/i',
        '/javascript:/i',
        '/vbscript:/i',
        '/data:text\/html/i',
        '/<img[\s\S]*?onerror[\s\S]*?>/i',
        '/<svg[\s\S]*?onload[\s\S]*?>/i',
        '/<body[\s\S]*?onload[\s\S]*?>/i',
    ];

    // Suspicious but false-positive-prone: record only, feeds the Fraud Engine.
    private const SUSPICIOUS_PATTERNS = [
        '/on\w+\s*=\s*["\'][^"\']*["\']/i',
        '/document\.cookie/i',
        '/expression\s*\(/i',
        '/<meta[\s\S]*?http-equiv/i',
    ];

    public function detect(Request $request, array $inputs): array
    {
        $findings = [
            ...$this->matchInputs($inputs, self::BLOCKING_PATTERNS, 'xss', 'XSS payload detected', 8),
            ...$this->matchInputs($inputs, self::SUSPICIOUS_PATTERNS, 'xss_suspicious', 'Suspicious XSS-like pattern detected', 6),
        ];

        // Check headers (cookies are inspected by CookieDetector).
        foreach ($request->headers->all() as $name => $values) {
            if (in_array(strtolower((string) $name), ['cookie', 'set-cookie'], true)) {
                continue;
            }

            $value = implode(',', (array) $values);

            foreach (self::BLOCKING_PATTERNS as $pattern) {
                if (preg_match($pattern, $value)) {
                    $findings[] = $this->finding('xss_header', "XSS payload detected in header: {$name}", $value, 8);
                    break;
                }
            }
        }

        return $findings;
    }
}
