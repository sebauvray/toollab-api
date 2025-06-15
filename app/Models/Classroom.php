<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Classroom extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'school_id',
        'cursus_id',
        'level_id',
        'size',
        'gender',
        'type',
        'years'
    ];

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
        return $this->belongsTo(Level::class);
    }

    public function userRoles()
    {
        return $this->hasMany(UserRole::class);
    }

    public function students()
    {
        return $this->belongsToMany(User::class, 'student_classrooms', 'classroom_id', 'student_id')
            ->withPivot('status', 'enrollment_date', 'family_id')
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

    public function studentClassrooms()
    {
        return $this->hasMany(StudentClassroom::class);
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
        return $this->available_spots <= 0;
    }
}
