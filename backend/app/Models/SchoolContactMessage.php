<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolContactMessage extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'message', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
