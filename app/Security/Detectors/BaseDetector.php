<?php

declare(strict_types=1);

namespace App\Security\Detectors;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class BaseDetector
{
    /** Settings key and display name, e.g. "InjectionDetector". */
    public function key(): string
    {
        return class_basename(static::class);
    }

    /**
     * Inspect the request and return findings.
     *
     * @param  array<string, string>  $inputs  Flattened request inputs (query + post + route params)
     * @return array<int, array{type: string, description: string, payload: string, severity: int}>
     */
    abstract public function detect(Request $request, array $inputs): array;

    /** @return array{type: string, description: string, payload: string, severity: int} */
    protected function finding(string $type, string $description, string $payload, int $severity): array
    {
        return [
            'type' => $type,
            'description' => $description,
            'payload' => Str::limit($payload, 500),
            'severity' => $severity,
        ];
    }

    /**
     * @param  array<string, string>  $inputs
     * @param  array<int, string>  $patterns
     * @return array<int, array{type: string, description: string, payload: string, severity: int}>
     */
    protected function matchInputs(array $inputs, array $patterns, string $type, string $description, int $severity): array
    {
        $findings = [];

        foreach ($inputs as $key => $value) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    $findings[] = $this->finding($type, "{$description} in parameter: {$key}", $value, $severity);
                    break;
                }
            }
        }

        return $findings;
    }
}
