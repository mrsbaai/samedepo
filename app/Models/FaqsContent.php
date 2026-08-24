<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FaqsContent extends Model
{
    use HasFactory;

    protected $table = 'faqs_content';

    protected $fillable = [
        'content',
    ];
}
