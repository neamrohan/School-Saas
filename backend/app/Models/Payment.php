<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'student_fee_id', 'student_id', 'amount', 'payment_date', 'payment_method', 'transaction_reference', 'remarks'];

    protected $casts = ['amount' => 'decimal:2', 'payment_date' => 'date'];

    public function school(): BelongsTo { return $this->belongsTo(School::class); }
    public function studentFee(): BelongsTo { return $this->belongsTo(StudentFee::class); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
}