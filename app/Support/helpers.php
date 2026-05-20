<?php

if (!function_exists('currentSchoolId')) {
    function currentSchoolId(): ?int
    {
        $request = request();
        if (!$request) {
            return null;
        }

        $value = $request->attributes->get('current_school_id');

        return is_int($value) ? $value : null;
    }
}
