<?php

use App\Support\PermissionCatalog;
use App\Support\StaffRolePermissions;

function canAssignDefault(string $caller, string $target): bool
{
    return StaffRolePermissions::canAssign(
        PermissionCatalog::defaultsFor($caller),
        PermissionCatalog::defaultsFor($target),
        in_array($target, PermissionCatalog::LOCKED_SLUGS, true)
    );
}

test('a director can manage every assignable staff role', function () {
    expect(canAssignDefault('director', 'admin'))->toBeTrue()
        ->and(canAssignDefault('director', 'registar'))->toBeTrue()
        ->and(canAssignDefault('director', 'teacher'))->toBeTrue();
});

test('nobody can assign the locked director role', function () {
    expect(canAssignDefault('director', 'director'))->toBeFalse()
        ->and(canAssignDefault('admin', 'director'))->toBeFalse();
});

test('an admin can manage registration and teacher roles only', function () {
    expect(canAssignDefault('admin', 'registar'))->toBeTrue()
        ->and(canAssignDefault('admin', 'teacher'))->toBeTrue()
        ->and(canAssignDefault('admin', 'admin'))->toBeFalse();
});

test('a registration manager cannot manage staff roles', function () {
    expect(canAssignDefault('registar', 'teacher'))->toBeFalse()
        ->and(canAssignDefault('teacher', 'teacher'))->toBeFalse()
        ->and(StaffRolePermissions::canAssign([], ['teaching.access'], false))->toBeFalse();
});

test('a custom role cannot grant rights its holder does not have', function () {
    $caller = ['staff.manage', 'families.view', 'families.edit'];

    expect(StaffRolePermissions::canAssign($caller, ['families.view'], false))->toBeTrue()
        ->and(StaffRolePermissions::canAssign($caller, ['families.view', 'statistics.view'], false))->toBeFalse()
        ->and(StaffRolePermissions::canAssign($caller, $caller, false))->toBeFalse();
});
