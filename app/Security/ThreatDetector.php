<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\Detectors\AbuseDetector;
use App\Security\Detectors\BaseDetector;
use App\Security\Detectors\CookieDetector;
use App\Security\Detectors\EncodingDetector;
use App\Security\Detectors\FileDetector;
use App\Security\Detectors\HeaderDetector;
use App\Security\Detectors\InjectionDetector;
use App\Security\Detectors\ReconDetector;
use App\Security\Detectors\SessionDetector;
use App\Security\Detectors\WebshellDetector;
use App\Security\Detectors\XssDetector;
use App\Security\Models\DetectorSetting;
use Illuminate\Http\Request;

class ThreatDetector
{
    /** @var array<int, class-string<BaseDetector>> */
    public const DETECTORS = [
        ReconDetector::class,
        AbuseDetector::class,
        InjectionDetector::class,
        XssDetector::class,
        FileDetector::class,
        CookieDetector::class,
        SessionDetector::class,
        EncodingDetector::class,
        HeaderDetector::class,
        WebshellDetector::class,
    ];

    /**
     * Run all enabled detectors and return their findings, each tagged with
     * the detector that produced it.
     *
     * @return array<int, array{detector: string, type: string, description: string, payload: string, severity: int}>
     */
    public function inspect(Request $request): array
    {
        $inputs = $this->gatherInputs($request);
        $findings = [];

        foreach (self::DETECTORS as $detectorClass) {
            $detector = new $detectorClass;

            if (! DetectorSetting::isEnabled($detector->key())) {
                continue;
            }

            foreach ($detector->detect($request, $inputs) as $finding) {
                $findings[] = ['detector' => $detector->key(), ...$finding];
            }
        }

        return $findings;
    }

    /**
     * Flatten query, post, and route parameters into a single string map,
     * with hard limits so inspection cannot be abused for DoS.
     *
     * @return array<string, string>
     */
    private function gatherInputs(Request $request): array
    {
        $raw = [
            ...$request->query->all(),
            ...$request->request->all(),
            ...($request->route()?->parameters() ?? []),
        ];

        if ($request->isJson()) {
            $raw = [...$raw, ...(array) $request->json()->all()];
        }

        $maxBytes = (int) config('security.max_inspect_bytes');
        $maxInputs = (int) config('security.max_inputs');
        $inputs = [];

        $flatten = function (array $values, string $prefix) use (&$flatten, &$inputs, $maxBytes, $maxInputs): void {
            foreach ($values as $key => $value) {
                if (count($inputs) >= $maxInputs) {
                    return;
                }

                $name = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

                if (is_array($value)) {
                    $flatten($value, $name);
                } elseif (is_scalar($value)) {
                    $inputs[$name] = substr((string) $value, 0, $maxBytes);
                }
            }
        };

        $flatten($raw, '');

        return $inputs;
    }
}
