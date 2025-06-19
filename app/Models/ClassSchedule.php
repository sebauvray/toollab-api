<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class ClassSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'classroom_id',
        'day',
        'start_time',
        'end_time',
        'teacher_name'
    ];

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public function getFormattedTimeAttribute()
    {
        return sprintf('%s à %s',
            Carbon::parse($this->start_time)->format('H\hi'),
            Carbon::parse($this->end_time)->format('H\hi')
        );
    }
}
