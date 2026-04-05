<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InspiredLead extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'name',
        'source',
        'status',
        'notes',
    ];
}
