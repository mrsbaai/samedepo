<?php

declare(strict_types=1);

namespace App\Security\Detectors;

use Illuminate\Http\Request;

class HeaderDetector extends BaseDetector
{
    public function detect(Request $request, array $inputs): array
    {
        $findings = [];

        // HTTP response splitting through inputs.
        foreach ($inputs as $key => $value) {
            if (preg_match('/(content-type:|location:).*[\r\n]/i', $value)) {
                $findings[] = $this->finding('response_splitting', "HTTP response splitting attempt in parameter: {$key}", $value, 9);
            }
        }

        // Host header injection.
        $host = (string) $request->headers->get('host', '');

        if ($host !== '' && (str_contains($host, "\n") || str_contains($host, "\r"))) {
            $findings[] = $this->finding('host_header_injection', 'Host header injection detected', $host, 8);
        }

        // Excessive header count.
        $headerCount = count($request->headers->all());

        if ($headerCount > 100) {
            $findings[] = $this->finding('excessive_headers', 'Excessive number of request headers', "count: {$headerCount}", 5);
        }

        return $findings;
    }
}
