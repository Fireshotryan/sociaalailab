<?php
require 'db.php';
require_once 'role_permissions.php';

echo "=== Updating Live Database - Onderzoeker Permissions ===\n\n";

$permJson = json_encode(sitePermissionsForRole('onderzoeker'), JSON_UNESCAPED_SLASHES);
$legacyResearcherRoles = array_keys(array_filter(
    siteLegacyRoleAliases(),
    function ($canonicalRole) {
        return $canonicalRole === 'onderzoeker';
    }
));
$targetRoles = array_merge(['onderzoeker'], $legacyResearcherRoles);
$rolePlaceholders = implode(', ', array_fill(0, count($targetRoles), '?'));

$query = "UPDATE accounts SET permissions = ? WHERE role IN ($rolePlaceholders)";
$stmt = $pdo->prepare($query);

try {
	$stmt->execute(array_merge([$permJson], $targetRoles));
    $count = $stmt->rowCount();
    echo "✅ Updated $count onderzoeker account(s)\n";
    echo "✅ Added view_pages permission\n";
    echo "\n✓ Onderzoekers can now see all pages in the admin panel!\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
