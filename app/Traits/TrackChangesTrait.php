<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

trait TrackChangesTrait
{
    protected static function bootTracksChanges()
    {
        static::creating(function ($model) {
            if (!$model->isDirty('created_by')) {
                $model->created_by = auth()->id() ?? null;
            }
        });

        static::updating(function ($model) {
            if (!$model->isDirty('updated_by')) {
                $model->updated_by = auth()->id() ?? null;
            }
        });
    }
}
