<?php

namespace App\Models;

use App\Traits\TrackChangesTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentClassroom extends Model
{
    use HasFactory, TrackChangesTrait;

    protected $fillable = [
        'student_id',
        'classroom_id',
        'family_id',
        'status',
        'enrollment_date',
        'created_by',
    ];

    protected $casts = [
        'enrollment_date' => 'date'
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public function family()
    {
        return $this->belongsTo(Family::class);
    }
}
