<?php

declare(strict_types=1);

namespace App\Fraud;

use App\Fraud\Models\UserRisk;
use App\Models\User;

/**
 * Entity-level risk system. Collects signals, calculates a fraud score,
 * determines the fraud level, and applies the level policy when it changes.
 */
class FraudEngine
{
    public function __construct(
        private readonly LinkDetector $linkDetector,
        private readonly RiskCalculator $riskCalculator,
        private readonly FraudPolicy $fraudPolicy,
    ) {}

    public function evaluate(User $user): UserRisk
    {
        $this->linkDetector->sync($user);

        $result = $this->riskCalculator->calculate($user);
        $level = UserRisk::levelFor($result['score']);

        $previousLevel = UserRisk::query()->where('user_id', $user->id)->value('level');

        $risk = UserRisk::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'score' => $result['score'],
                'level' => $level,
                'breakdown' => $result['breakdown'],
                'calculated_at' => now(),
            ]
        );

        if ($previousLevel !== $level) {
            $this->fraudPolicy->apply($user, $level, $result['score']);
        }

        return $risk;
    }
}
