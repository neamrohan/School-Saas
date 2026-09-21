<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentFee extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'student_id', 'fee_type_id', 'fee_item_id', 'academic_year_id', 'class_id', 'section_id', 'month', 'amount', 'due_date', 'status', 'remarks'];

    protected $casts = ['amount' => 'decimal:2', 'due_date' => 'date'];

    public function school(): BelongsTo { return $this->belongsTo(School::class); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function feeType(): BelongsTo { return $this->belongsTo(FeeType::class); }
    public function feeItem(): BelongsTo { return $this->belongsTo(FeeItem::class); }
    public function academicYear(): BelongsTo { return $this->belongsTo(AcademicYear::class); }
    public function class(): BelongsTo { return $this->belongsTo(SchoolClass::class, 'class_id'); }
    public function section(): BelongsTo { return $this->belongsTo(Section::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
}