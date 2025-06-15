<?php

namespace App\Models;

use App\Notifications\CustomResetPasswordNotification;
use Illuminate\Auth\Passwords\CanResetPassword;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, CanResetPassword;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'access',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function infos()
    {
        return $this->hasMany(UserInfo::class);
    }

    public function roles()
    {
        return $this->hasMany(UserRole::class);
    }

    public function schools()
    {
        return $this->belongsToMany(School::class, 'user_roles')
            ->withPivot('role_id');
    }

    public function families()
    {
        return $this->belongsToMany(Family::class, 'user_roles')
            ->withPivot('role_id');
    }

    public function classrooms()
    {
        return $this->belongsToMany(Classroom::class, 'user_roles')
            ->withPivot('role_id');
    }

    public function studentClassrooms()
    {
        return $this->hasMany(StudentClassroom::class, 'student_id');
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new CustomResetPasswordNotification($token));
    }
}
