<?php
require __DIR__ . '/../role_permissions.php';

$assertions = 0;

function assertRoleSame($expected, $actual, $label)
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

assertRoleSame(['administrator', 'content_manager', 'onderzoeker', 'viewer'], siteCanonicalRoles(), 'Only canonical roles are offered');
assertRoleSame('onderzoeker', siteNormalizeSubmittedRole('onderzoeker'), 'Canonical researcher role is accepted');
assertRoleSame('onderzoeker', siteNormalizeSubmittedRole('editor'), 'Legacy editor submission is normalized');
assertRoleSame('onderzoeker', siteNormalizeAccountRole('booking_only'), 'Legacy booking role is normalized');
assertRoleSame('administrator', siteNormalizeAccountRole('superadmin', 1), 'Legacy superadmin is normalized');
assertRoleSame('viewer', siteNormalizeAccountRole('', 0), 'Missing session role cannot trust a stale admin flag');
assertRoleSame('viewer', siteNormalizeAccountRole('unknown_role', 1), 'Unknown stored role receives least privilege');
assertRoleSame(null, siteNormalizeSubmittedRole('unknown_role'), 'Unknown submitted role is rejected');
assertRoleSame(null, siteNormalizeSubmittedRole(['administrator']), 'Non-string submitted role is rejected');

$researcherPermissions = sitePermissionsForRole('onderzoeker');
assertRoleSame(['access_booking', 'create_events', 'view_feedback', 'view_pages'], $researcherPermissions, 'Researcher keeps intended permissions');
assertRoleSame(false, in_array('create_users', $researcherPermissions, true), 'Researcher cannot create users');
assertRoleSame(false, in_array('edit_users', $researcherPermissions, true), 'Researcher cannot edit users');
assertRoleSame(false, in_array('delete_users', $researcherPermissions, true), 'Researcher cannot delete users');
assertRoleSame(false, in_array('approve_content', $researcherPermissions, true), 'Researcher cannot approve content');
assertRoleSame(true, in_array('create_users', sitePermissionsForRole('administrator'), true), 'Administrator retains account-management rights');
assertRoleSame(true, in_array('approve_content', sitePermissionsForRole('content_manager'), true), 'Content manager retains approval rights');
assertRoleSame([], sitePermissionsForRole('viewer'), 'Viewer has no permissions');

echo "OK ({$assertions} assertions)\n";