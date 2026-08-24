<?php

declare(strict_types=1);

namespace App\Fraud;

use App\Fraud\Models\FraudMetricSetting;
use App\Fraud\Signals\DatacenterProxySignal;
use App\Fraud\Signals\FraudSignal;
use App\Fraud\Signals\MultipleAccountsSameDeviceSignal;
use App\Fraud\Signals\PreviousFraudConnectionSignal;
use App\Fraud\Signals\SameFingerprintSignal;
use App\Fraud\Signals\SamePaymentEmailSignal;
use App\Fraud\Signals\SharedPaymentMethodSignal;
use App\Fraud\Signals\SuspiciousIpSignal;
use App\Fraud\Signals\SuspiciousPaymentPatternSignal;
use App\Fraud\Signals\ThreatDetectorEventSignal;
use App\Models\User;

class RiskCalculator
{
    /** @var array<int, class-string<FraudSignal>> */
    public const SIGNALS = [
        SameFingerprintSignal::class,
        SharedPaymentMethodSignal::class,
        SamePaymentEmailSignal::class,
        SuspiciousPaymentPatternSignal::class,
        MultipleAccountsSameDeviceSignal::class,
        PreviousFraudConnectionSignal::class,
        SuspiciousIpSignal::class,
        DatacenterProxySignal::class,
        ThreatDetectorEventSignal::class,
    ];

    /** @return array<int, FraudSignal> */
    public static function signals(): array
    {
        return array_map(fn (string $class): FraudSignal => new $class, self::SIGNALS);
    }

    /**
     * @return array{score: int, breakdown: array<int, array{key: string, label: string, weight: int, reason: string}>}
     */
    public function calculate(User $user): array
    {
        $settings = FraudMetricSetting::query()->get()->keyBy('key');
        $breakdown = [];
        $score = 0;

        foreach (self::signals() as $signal) {
            $setting = $settings->get($signal->key());
            $enabled = $setting->enabled ?? true;
            $weight = $setting->weight ?? $signal->defaultWeight();

            if (! $enabled || $weight === 0) {
                continue;
            }

            $reason = $signal->evaluate($user);

            if ($reason !== null) {
                $score += $weight;
                $breakdown[] = [
                    'key' => $signal->key(),
                    'label' => $signal->label(),
                    'weight' => $weight,
                    'reason' => $reason,
                ];
            }
        }

        return [
            'score' => max(0, min(100, $score)),
            'breakdown' => $breakdown,
        ];
    }
}
