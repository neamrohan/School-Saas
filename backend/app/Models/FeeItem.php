<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeItem extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'category_id', 'name', 'code', 'description', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function school(): BelongsTo { return $this->belongsTo(School::class); }
    public function category(): BelongsTo { return $this->belongsTo(FeeCategory::class, 'category_id'); }
    public function pricings(): HasMany { return $this->hasMany(FeePricing::class); }
    public function monthlySetups(): HasMany { return $this->hasMany(MonthlyFeeSetup::class); }
}
