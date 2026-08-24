<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = [
        'content',
    ];

    public static function current(): ?self
    {
        $announcement = static::query()->first();

        return $announcement?->content ? $announcement : null;
    }
}
