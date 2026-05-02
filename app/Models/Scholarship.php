<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Scholarship extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'continent',
        'country',
        'level',
        'description',
        'deadline',
        'category',
        'major',
        'benefit',
        'requirements',
        'source',
        'url',
        'original_url',
        'embedding'
    ];
}
