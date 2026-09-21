<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class RoutinePeriod extends Model {
    use HasFactory;
    protected $fillable = ['school_id','name','start_time','end_time','display_order','is_active'];
    protected $casts = ['display_order'=>'integer','is_active'=>'boolean'];
    public function school(): BelongsTo { return $this->belongsTo(School::class); }
}
