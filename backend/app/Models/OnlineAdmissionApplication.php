<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineAdmissionApplication extends Model
{
    protected $fillable = [
        'school_id', 'name', 'email', 'phone', 'guardian_name', 'guardian_phone',
        'date_of_birth', 'gender', 'address', 'academic_year_id', 'class_id',
        'section_id', 'status', 'review_note', 'reviewed_by', 'reviewed_at',
        'approved_student_id',
    ];

    protected $casts = ['date_of_birth' => 'date', 'reviewed_at' => 'datetime'];

    public function school(): BelongsTo { return $this->belongsTo(School::class); }
    public function academicYear(): BelongsTo { return $this->belongsTo(AcademicYear::class); }
    public function class(): BelongsTo { return $this->belongsTo(SchoolClass::class, 'class_id'); }
    public function section(): BelongsTo { return $this->belongsTo(Section::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function approvedStudent(): BelongsTo { return $this->belongsTo(Student::class, 'approved_student_id'); }
}
