<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class RoutineRoom extends Model {
    use HasFactory;
    protected $fillable = ['school_id','name','code','capacity','is_active'];
    protected $casts = ['capacity'=>'integer','is_active'=>'boolean'];
    public function school(): BelongsTo { return $this->belongsTo(School::class); }
}
