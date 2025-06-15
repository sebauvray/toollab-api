<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'classroom_id',
        'day',
        'start_time',
        'end_time',
        'teacher_id'
    ];

    protected $casts = [
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i'
    ];

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function getFormattedTimeAttribute()
    {
        return sprintf('%s à %s',
            date('H\h', strtotime($this->start_time)),
            date('H\h', strtotime($this->end_time))
        );
    }
}
