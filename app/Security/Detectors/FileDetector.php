<?php

declare(strict_types=1);

namespace App\Security\Detectors;

use Illuminate\Http\Request;

class FileDetector extends BaseDetector
{
    private const PATH_TRAVERSAL_PATTERNS = [
        '/\.\.\//',
        '/\.\.\\\\/',
        '/%2e%2e%2f/i',
        '/%2e%2e\//i',
        '/\.\.%2f/i',
        '/%5c\.\./i',
        '/\.\.%5c/i',
        '/%00/i',
    ];

    private const FILE_PARAMS = [
        'file', 'path', 'template', 'include', 'page', 'document', 'dir', 'folder',
        'filename', 'filepath', 'download', 'upload', 'attachment', 'resource',
        'view', 'module', 'plugin', 'theme', 'layout', 'img', 'image', 'src',
    ];

    private const LFI_PATTERNS = [
        '/\/etc\/passwd/i',
        '/\/etc\/shadow/i',
        '/\/proc\/self/i',
        '/c:\\\\windows/i',
        '/php:\/\/filter/i',
        '/php:\/\/input/i',
        '/expect:\/\//i',
    ];

    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'phtml', 'pht', 'phar',
        'exe', 'bat', 'cmd', 'com', 'scr',
        'sh', 'bash', 'cgi', 'pl',
        'jsp', 'asp', 'aspx', 'ashx',
        'htaccess', 'htpasswd',
    ];

    public function detect(Request $request, array $inputs): array
    {
        $fileInputs = array_filter(
            $inputs,
            function ($key): bool {
                foreach (self::FILE_PARAMS as $param) {
                    if (stripos((string) $key, $param) !== false) {
                        return true;
                    }
                }

                return false;
            },
            ARRAY_FILTER_USE_KEY
        );

        $findings = [
            ...$this->matchInputs($fileInputs, self::PATH_TRAVERSAL_PATTERNS, 'path_traversal', 'Path traversal attempt detected', 9),
            ...$this->matchInputs($inputs, self::LFI_PATTERNS, 'lfi', 'Local file inclusion attempt detected', 9),
        ];

        foreach ($request->allFiles() as $files) {
            foreach (is_array($files) ? $files : [$files] as $file) {
                if (! is_object($file)) {
                    continue;
                }

                $filename = (string) $file->getClientOriginalName();
                $extension = strtolower((string) $file->getClientOriginalExtension());

                if (in_array($extension, self::DANGEROUS_EXTENSIONS, true)) {
                    $findings[] = $this->finding('malicious_file_upload', "Dangerous file type upload attempt: {$extension}", $filename, 8);
                }

                if (preg_match('/\.(php|phtml|phar|exe|sh|pl|jsp|asp)\./i', $filename)) {
                    $findings[] = $this->finding('double_extension_upload', 'Double extension file upload attempt', $filename, 9);
                }

                if (str_contains($filename, "\x00")) {
                    $findings[] = $this->finding('null_byte_upload', 'Null byte in uploaded filename', $filename, 9);
                }
            }
        }

        return $findings;
    }
}
