<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\TrackChangesTrait;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRole extends Model
{
    use TrackChangesTrait;

    protected $fillable = [
        'user_id',
        'role_id',
        'roleable_id',
        'roleable_type',
        'created_by',
        'updated_by',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function roleable()
    {
        return $this->morphTo();
    }
}
