<?php

declare(strict_types=1);

namespace App\Security\Detectors;

use Illuminate\Http\Request;

class EncodingDetector extends BaseDetector
{
    private const DECODED_MALICIOUS = [
        '/<script/i',
        '/\beval\s*\(/i',
        '/\bsystem\s*\(/i',
        '/\bexec\s*\(/i',
        '/<\?php/i',
        '/union.*select/i',
    ];

    public function detect(Request $request, array $inputs): array
    {
        $findings = [];

        foreach ($inputs as $key => $value) {
            // Base64-encoded payloads that decode to attack patterns.
            if (strlen($value) >= 20 && strlen($value) <= 4096 && preg_match('/^[A-Za-z0-9+\/=]+$/', $value)) {
                $decoded = base64_decode($value, true);

                if ($decoded !== false) {
                    foreach (self::DECODED_MALICIOUS as $pattern) {
                        if (preg_match($pattern, $decoded)) {
                            $findings[] = $this->finding('base64_encoded_payload', "Base64 encoded attack payload in parameter: {$key}", $value, 8);
                            break;
                        }
                    }
                }
            }

            // URL-encoded attack patterns (single decode pass, bounded input).
            if (preg_match('/%3C%73%63%72%69%70%74/i', $value)
                || preg_match('/%27%20or/i', $value)
                || preg_match('/%252e%252e/i', $value)) {
                $findings[] = $this->finding('encoded_malicious_pattern', "URL encoded attack pattern in parameter: {$key}", $value, 7);
            }

            // Overlong UTF-8 sequences used to bypass filters.
            if (preg_match('/[\xC0-\xC1][\x80-\xBF]/', $value)) {
                $findings[] = $this->finding('utf8_overlong', "UTF-8 overlong encoding in parameter: {$key}", $value, 7);
            }
        }

        return $findings;
    }
}
