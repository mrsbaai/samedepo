<?php

declare(strict_types=1);

namespace App\Security\Detectors;

use Illuminate\Http\Request;

class WebshellDetector extends BaseDetector
{
    private const WEBSHELL_PATTERNS = [
        '/c99shell/i',
        '/r57shell/i',
        '/b374k/i',
        '/wso\.php/i',
        '/FilesMan/i',
        '/\$_(GET|POST|REQUEST|COOKIE)\[[^\]]*\]\s*\(/i',
        '/assert\s*\(\s*\$_/i',
        '/preg_replace\s*\(.*\/e[\'"]/i',
        '/array_map\s*\(\s*["\']assert/i',
        '/create_function\s*\(/i',
        '/(include|require)(_once)?\s*\(\s*\$_/i',
    ];

    public function detect(Request $request, array $inputs): array
    {
        $findings = $this->matchInputs($inputs, self::WEBSHELL_PATTERNS, 'webshell_pattern', 'Webshell pattern detected', 10);

        $body = (string) $request->getContent();

        if ($body !== '' && strlen($body) <= (int) config('security.max_inspect_bytes')) {
            foreach (self::WEBSHELL_PATTERNS as $pattern) {
                if (preg_match($pattern, $body)) {
                    $findings[] = $this->finding('webshell_upload', 'Webshell pattern detected in request body', $body, 10);
                    break;
                }
            }
        }

        return $findings;
    }
}
