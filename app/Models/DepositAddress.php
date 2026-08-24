<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepositAddress extends Model
{
    use BelongsToOwner;
    use HasFactory;

    protected static function ownerScopeRelation(): string
    {
        return 'customer';
    }

    protected static function ownerScopeKey(): string
    {
        return 'user_id';
    }

    protected $fillable = [
        'customer_id',
        'network',
        'address',
        'derivation_index',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }
}
