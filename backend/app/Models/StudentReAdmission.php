<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentReAdmission extends Model
{
    protected $fillable = [
        'school_id', 'student_id', 'previous_academic_year_id', 'academic_year_id',
        'previous_class_id', 'class_id', 'previous_section_id', 'section_id', 'created_by',
    ];

    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function academicYear(): BelongsTo { return $this->belongsTo(AcademicYear::class); }
    public function previousAcademicYear(): BelongsTo { return $this->belongsTo(AcademicYear::class, 'previous_academic_year_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
