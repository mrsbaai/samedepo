<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PublicContentPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'content',
    ];
}
