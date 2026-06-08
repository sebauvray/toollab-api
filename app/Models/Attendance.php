<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use App\Traits\BelongsToSchoolYear;
use App\Traits\TrackChangesTrait;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use BelongsToSchool, BelongsToSchoolYear, TrackChangesTrait;

    protected $fillable = [
        'student_id',
        'classroom_id',
        'date',
        'status',
        'justification',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }
}
