<?php

declare(strict_types=1);

namespace App\Security\Detectors;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReconDetector extends BaseDetector
{
    private const SENSITIVE_PATHS = [
        '/^\.env/',
        '/\/\.env/',
        '/\.git(\/|$)/',
        '/\.svn(\/|$)/',
        '/\.htaccess$/',
        '/\.htpasswd$/',
        '/web\.config$/',
        '/phpinfo\.php$/',
        '/wp-config\.php$/',
        '/config\.inc\.php$/',
        '/\.(sql|bak|old)$/',
        '/\.DS_Store$/',
        '/\.npmrc$/',
        '/\.dockerenv$/',
        '/(^|\/)Dockerfile$/',
        '/(^|\/)composer\.json$/',
        '/(^|\/)package\.json$/',
        '/vendor\/phpunit/',
        '/server-status$/',
    ];

    private const VULN_PROBES = [
        'cgi-bin', 'fcgi-bin', 'servlet', 'struts', 'spring-boot',
        'solr', 'elasticsearch', 'actuator', 'jolokia', 'wp-admin', 'wp-login',
    ];

    public function detect(Request $request, array $inputs): array
    {
        $findings = [];
        $path = $request->path();

        foreach (self::SENSITIVE_PATHS as $pattern) {
            if (preg_match($pattern, $path)) {
                $findings[] = $this->finding('sensitive_file_access', 'Attempt to access sensitive file or directory', $path, 9);
                break;
            }
        }

        foreach (self::VULN_PROBES as $probe) {
            if (stripos($path, $probe) !== false) {
                $findings[] = $this->finding('vulnerability_probe', 'Known vulnerability path being probed', $path, 7);
                break;
            }
        }

        // Behavioral recon: many unique paths probed in a short window.
        $key = 'security.recon.'.$request->ip();
        $paths = Cache::get($key, []);
        $paths[sha1($path)] = true;
        Cache::put($key, $paths, 600);

        if (count($paths) > 60) {
            $findings[] = $this->finding('path_enumeration', 'Rapid enumeration of many unique paths', 'unique paths: '.count($paths), 8);
        }

        return $findings;
    }
}
