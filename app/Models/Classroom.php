<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Classroom extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'name',
        'years',
        'type',
        'size',
        'cursus_id',
        'level_id',
        'gender',
        'telegram_link'
    ];

    protected $appends = ['student_count', 'available_spots'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function cursus()
    {
        return $this->belongsTo(Cursus::class);
    }

    public function level()
    {
        return $this->belongsTo(CursusLevel::class);
    }

    public function students()
    {
        return $this->belongsToMany(User::class, 'student_classrooms', 'classroom_id', 'student_id')
            ->withPivot('family_id', 'status', 'enrollment_date')
            ->withTimestamps();
    }

    public function activeStudents()
    {
        return $this->students()->wherePivot('status', 'active');
    }

    public function schedules()
    {
        return $this->hasMany(ClassSchedule::class);
    }

    public function getStudentCountAttribute()
    {
        return $this->activeStudents()->count();
    }

    public function getAvailableSpotsAttribute()
    {
        return max(0, $this->size - $this->student_count);
    }

    public function isFull()
    {
        return $this->student_count >= $this->size;
    }
}
