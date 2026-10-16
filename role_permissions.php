<?php

function siteRolePermissions()
{
    return [
        'administrator' => ['create_users', 'edit_users', 'delete_users', 'manage_banners', 'manage_events', 'manage_pages', 'delete_events', 'delete_pages', 'optimize_images', 'approve_content', 'access_booking', 'delete_bookings', 'view_audit'],
        'content_manager' => ['manage_banners', 'manage_events', 'manage_pages', 'delete_events', 'delete_pages', 'optimize_images', 'approve_content', 'access_booking'],
        'onderzoeker' => ['access_booking', 'create_events', 'view_feedback', 'view_pages'],
        'viewer' => [],
    ];
}

function siteCanonicalRoles()
{
    return array_keys(siteRolePermissions());
}

function siteRoleLabels()
{
    return [
        'administrator' => 'Administrator',
        'content_manager' => 'Content Manager',
        'onderzoeker' => 'Onderzoeker',
        'viewer' => 'Geen vaste rol',
    ];
}

function siteLegacyRoleAliases()
{
    return [
        'editor' => 'onderzoeker',
        'booking_only' => 'onderzoeker',
    ];
}

function siteNormalizeAccountRole($role, $adminFlag = 0)
{
    $role = is_string($role) ? trim($role) : '';

    if ($role === '' || $role === 'superadmin') {
        return (int)$adminFlag === 1 ? 'administrator' : 'viewer';
    }

    $legacyAliases = siteLegacyRoleAliases();
    if (isset($legacyAliases[$role])) {
        return $legacyAliases[$role];
    }

    return in_array($role, siteCanonicalRoles(), true) ? $role : 'viewer';
}

function siteNormalizeSubmittedRole($role)
{
    if (!is_string($role)) {
        return null;
    }

    $role = trim($role);
    if (in_array($role, siteCanonicalRoles(), true)) {
        return $role;
    }

    $legacyAliases = siteLegacyRoleAliases();
    if (isset($legacyAliases[$role])) {
        return $legacyAliases[$role];
    }

    return null;
}

function sitePermissionsForRole($role, $adminFlag = 0)
{
    $canonicalRole = siteNormalizeAccountRole($role, $adminFlag);
    $rolePermissions = siteRolePermissions();
    return $rolePermissions[$canonicalRole];
}