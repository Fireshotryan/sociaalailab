<?php
session_start();
require 'db.php';
require_once 'role_permissions.php';

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<pre>";
echo "=== LOGIN DEBUG ===\n";
echo "REQUEST METHOD: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "POST data: " . print_r($_POST, true) . "\n";

$banner1 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner1'")->fetchColumn() ?: 'images/banner_website_01.jpg';
$banner2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner2'")->fetchColumn() ?: 'images/banner_website_02.jpg';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "\n--- Processing POST ---\n";
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    
    echo "Email: " . $email . "\n";
    echo "Password: " . (strlen($pass) > 0 ? 'PROVIDED' : 'EMPTY') . "\n";

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email format.';
        echo "ERROR: Invalid email format\n";
    } else {
        echo "\nQuerying database...\n";
        // Look up user in database
        $stmt = $pdo->prepare("SELECT email, wachtwoord, admin, role, permissions, first_name FROM accounts WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        echo "User found: " . ($user ? 'YES' : 'NO') . "\n";

        if (!$user) {
            $message = 'Email or password incorrect.';
            echo "ERROR: User not found\n";
        } elseif (!password_verify($pass, $user['wachtwoord'])) {
            $message = 'Email or password incorrect.';
            echo "ERROR: Password incorrect\n";
            echo "Hash in DB: " . substr($user['wachtwoord'], 0, 20) . "...\n";
        } else {
            echo "\n--- LOGIN SUCCESSFUL ---\n";
            // Set session and redirect
            $_SESSION['user'] = $user['email'];
            $role = siteNormalizeAccountRole($user['role'] ?? '', $user['admin'] ?? 0);
            
            echo "Mapped role: " . $role . "\n";

            $permissions = sitePermissionsForRole($role);
            echo "Permissions: " . implode(', ', $permissions) . "\n";
            
            $_SESSION['admin'] = $role === 'administrator' ? 1 : 0;
            $_SESSION['role'] = $role;
            $_SESSION['permissions'] = json_encode($permissions, JSON_UNESCAPED_SLASHES);
            $_SESSION['can_access_admin'] = !empty($permissions);
            
            echo "\nSession set, redirecting to admin.php...\n";
            echo "</pre>";
            header('Location: admin.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in - DEBUG</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        input { padding: 8px; margin: 5px; }
        button { padding: 10px 20px; background: #00811F; color: white; border: none; cursor: pointer; }
        .error { color: red; font-weight: bold; }
        pre { background: #f0f0f0; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Debug Login</h1>
    
    <?php if ($message): ?>
        <p class="error"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>
    
    <form method="POST">
        <div>
            <label for="email">Email:</label><br>
            <input type="email" id="email" name="email" value="test@onderzoeker.nl" required>
        </div>
        <div>
            <label for="password">Password:</label><br>
            <input type="password" id="password" name="password" value="test123" required>
        </div>
        <button type="submit">Login</button>
    </form>
    
    <hr>
    <h2>Session Info:</h2>
    <pre><?php print_r($_SESSION); ?></pre>
</body>
</html>
