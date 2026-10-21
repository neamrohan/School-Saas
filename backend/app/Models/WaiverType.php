<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaiverType extends Model
{
    protected $fillable = ['school_id', 'name', 'description', 'calculation_type', 'value', 'is_active'];

    protected $casts = ['value' => 'decimal:2', 'is_active' => 'boolean'];

    public function school(): BelongsTo { return $this->belongsTo(School::class); }
    public function waivers(): HasMany { return $this->hasMany(StudentWaiver::class); }
}
