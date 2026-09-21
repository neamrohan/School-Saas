<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeePricing extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'fee_item_id', 'academic_year_id', 'class_id', 'amount', 'is_active'];
    protected $casts = ['amount' => 'decimal:2', 'is_active' => 'boolean'];

    public function item(): BelongsTo { return $this->belongsTo(FeeItem::class, 'fee_item_id'); }
    public function academicYear(): BelongsTo { return $this->belongsTo(AcademicYear::class); }
    public function class(): BelongsTo { return $this->belongsTo(SchoolClass::class, 'class_id'); }
}
