<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class ClassSubjectAssignment extends Model {
    use HasFactory;
    protected $fillable = ['school_id','school_class_id','subject_id'];
    public function schoolClass() { return $this->belongsTo(SchoolClass::class, 'school_class_id'); }
    public function subject() { return $this->belongsTo(Subject::class); }
}
