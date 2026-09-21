<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class GroupTrade extends Model {
    use HasFactory;
    protected $table = 'groups_trades';
    protected $fillable = ['school_id','name','code','type','is_active'];
    protected $casts = ['is_active'=>'boolean'];
    public function school(): BelongsTo { return $this->belongsTo(School::class); }
}
