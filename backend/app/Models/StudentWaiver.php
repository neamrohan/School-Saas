<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentWaiver extends Model
{
    protected $fillable = [
        'school_id', 'student_id', 'student_fee_id', 'waiver_type_id', 'amount',
        'status', 'reason', 'assigned_by', 'assigned_at', 'revoked_by', 'revoked_at',
    ];

    protected $casts = ['amount' => 'decimal:2', 'assigned_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function studentFee(): BelongsTo { return $this->belongsTo(StudentFee::class); }
    public function waiverType(): BelongsTo { return $this->belongsTo(WaiverType::class); }
    public function assignedBy(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }
    public function revokedBy(): BelongsTo { return $this->belongsTo(User::class, 'revoked_by'); }
}
