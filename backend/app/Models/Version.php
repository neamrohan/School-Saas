<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Version extends Model {
    use HasFactory;
    protected $fillable = ['school_id','name','is_active'];
    protected $casts = ['is_active'=>'boolean'];
    public function school(): BelongsTo { return $this->belongsTo(School::class); }
}
