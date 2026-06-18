<?php

use App\Support\StaffRolePermissions;

test('a director can manage every assignable staff role', function () {
    expect(StaffRolePermissions::canManage(['director'], 'admin'))->toBeTrue()
        ->and(StaffRolePermissions::canManage(['director'], 'registar'))->toBeTrue()
        ->and(StaffRolePermissions::canManage(['director'], 'teacher'))->toBeTrue();
});

test('an admin can manage registration and teacher roles only', function () {
    expect(StaffRolePermissions::canManage(['admin'], 'registar'))->toBeTrue()
        ->and(StaffRolePermissions::canManage(['admin'], 'teacher'))->toBeTrue()
        ->and(StaffRolePermissions::canManage(['admin'], 'admin'))->toBeFalse();
});

test('a registration manager cannot manage staff roles', function () {
    expect(StaffRolePermissions::canManage(['registar'], 'teacher'))->toBeFalse()
        ->and(StaffRolePermissions::canManage([], 'teacher'))->toBeFalse();
});
