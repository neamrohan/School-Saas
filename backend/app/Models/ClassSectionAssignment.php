<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class ClassSectionAssignment extends Model {
    use HasFactory;
    protected $fillable = ['school_id','school_class_id','section_id'];
    public function schoolClass() { return $this->belongsTo(SchoolClass::class, 'school_class_id'); }
    public function section() { return $this->belongsTo(Section::class); }
}
