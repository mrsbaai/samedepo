<?php

declare(strict_types=1);

namespace App\Security\Detectors;

use Illuminate\Http\Request;

class InjectionDetector extends BaseDetector
{
    private const SQL_PATTERNS = [
        '/(\bunion\b.*\bselect\b)/i',
        '/(;\s*drop\s+(table|database))/i',
        '/(;\s*delete\s+from)/i',
        '/(\'|")\s*or\s*[\'"]?1[\'"]?\s*=\s*[\'"]?1/i',
        '/(\binto\s+outfile\b)/i',
        '/(\bload_file\s*\()/i',
    ];

    private const CMD_PATTERNS = [
        '/;\s*(rm|cat|wget|curl|nc|bash|sh|cmd|powershell)\b/i',
        '/\|\s*(rm|bash|sh|cmd|nc)\b/i',
        '/`.*\$.*`/',
    ];

    private const PHP_PATTERNS = [
        '/<\?php/i',
        '/\beval\s*\(/i',
        '/\bsystem\s*\(/i',
        '/\bshell_exec\s*\(/i',
        '/\bproc_open\s*\(/i',
    ];

    private const XXE_PATTERNS = [
        '/<!ENTITY/i',
        '/<!DOCTYPE[^>]*\[/i',
        '/SYSTEM\s+["\']file:/i',
    ];

    private const EMAIL_PATTERNS = [
        '/\r\n.*to:/i',
        '/\r\n.*cc:/i',
        '/\r\n.*bcc:/i',
        '/\n.*content-type:/i',
    ];

    public function detect(Request $request, array $inputs): array
    {
        $findings = [
            ...$this->matchInputs($inputs, self::SQL_PATTERNS, 'sql_injection', 'SQL injection pattern detected', 10),
            ...$this->matchInputs($inputs, self::CMD_PATTERNS, 'command_injection', 'Command injection pattern detected', 10),
            ...$this->matchInputs($inputs, self::PHP_PATTERNS, 'php_injection', 'PHP code injection detected', 10),
        ];

        $body = (string) $request->getContent();

        foreach (self::XXE_PATTERNS as $pattern) {
            if (preg_match($pattern, $body)) {
                $findings[] = $this->finding('xxe_injection', 'XXE injection attempt detected in request body', $body, 9);
                break;
            }
        }

        $emailInputs = array_filter(
            $inputs,
            fn ($key) => stripos((string) $key, 'email') !== false || stripos((string) $key, 'mail') !== false,
            ARRAY_FILTER_USE_KEY
        );

        return [
            ...$findings,
            ...$this->matchInputs($emailInputs, self::EMAIL_PATTERNS, 'email_injection', 'Email header injection detected', 8),
        ];
    }
}
