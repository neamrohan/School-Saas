<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Transport extends Model {
    use HasFactory;
    protected $fillable = ['school_id','name','route','vehicle_number','driver_name','driver_phone','capacity','is_active'];
    protected $casts = ['capacity'=>'integer','is_active'=>'boolean'];
    public function school(): BelongsTo { return $this->belongsTo(School::class); }
}
