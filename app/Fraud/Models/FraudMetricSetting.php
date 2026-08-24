<?php

declare(strict_types=1);

namespace App\Fraud\Models;

use Illuminate\Database\Eloquent\Model;

class FraudMetricSetting extends Model
{
    protected $fillable = ['key', 'enabled', 'weight'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'weight' => 'integer',
        ];
    }
}
