<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AcademicYear extends Model {
    use HasFactory;
    protected $fillable = ['school_id','name','start_date','end_date','is_current','is_active'];
    protected $casts = ['start_date'=>'date:Y-m-d','end_date'=>'date:Y-m-d','is_current'=>'boolean','is_active'=>'boolean'];
    public function school(): BelongsTo { return $this->belongsTo(School::class); }
}
