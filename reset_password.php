<?php
session_start();
require 'db.php';

$message = '';
$validToken = false;
$email = '';

if (isset($_GET['token'])) {
    $token = urldecode($_GET['token']);

    // Check if token exists in database and is not expired
    $stmt = $pdo->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    $resetRecord = $stmt->fetch();
    
    if ($resetRecord) {
        $validToken = true;
        $email = $resetRecord['email'];
    } else {
        $message = 'Ongeldige of verlopen reset link.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $newPassword = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'];

    if (strlen($newPassword) < 6) {
        $message = 'Wachtwoord moet minimaal 6 karakters zijn.';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'Wachtwoorden komen niet overeen.';
    } else {
        // Update password in database - hash it for security
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE accounts SET wachtwoord = ? WHERE email = ?");
        $stmt->execute([$hashedPassword, $email]);

        // Delete used token from database
        $stmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt->execute([$email]);

        $message = 'Wachtwoord succesvol gewijzigd. <a href="login.php" class="text-[#00811F] hover:underline">Log in</a>';
        $validToken = false; // Hide form
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Wachtwoord resetten - SociaalAI Lab">
    <title>Wachtwoord resetten - SociaalAI Lab</title>
    <link rel="preload" href="build/assets/app-CAiCLEjY.js" as="script">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha512-QWW4UvCRwT1iu11i/LCSVyitVqqkBIQviyLblhMlLKL6+0JSVDtB+cdcIUMyZVQd2+bwTKgCCAPEnjRBeWV2vQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gradient-to-br from-[#00811F] to-[#b9eb34]">
    <div class="banner-wrapper relative">
        <img src="images/banner_website_01.jpg" alt="Sociaal AI Lab Rotterdam-logo naast een groep mensen tijdens een bijeenkomst." class="banner active h-60 md:h-96 w-full object-cover">
        <img src="images/banner_website_02.jpg" alt="Een groep mensen werkt samen rond een laptop bij Sociaal AI Lab Rotterdam." class="banner h-60 md:h-96 w-full object-cover">
    </div>

    <?php
$navPrefix = '';
include __DIR__ . '/navbar.php';
?>

<main id="main-content" tabindex="-1">
    <section class="bg-white shadow-lg rounded-2xl p-8 sm:p-10 w-[92%] max-w-md mx-auto my-12">
        <h2 class="text-2xl md:text-3xl font-semibold mb-4 text-gray-900">Wachtwoord resetten</h2>
        <?php if ($message): ?>
            <p class="text-center text-lg <?php echo strpos($message, 'succesvol') !== false ? 'text-green-600' : 'text-red-600'; ?>"><?php echo $message; ?></p>
        <?php endif; ?>
        <?php if ($validToken): ?>
        <form method="POST" class="space-y-4">
            <div>
                <label for="password" class="block text-gray-700">Nieuw wachtwoord:</label>
                <input type="password" id="password" name="password" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#00811F]">
            </div>
            <div>
                <label for="confirm_password" class="block text-gray-700">Bevestig wachtwoord:</label>
                <input type="password" id="confirm_password" name="confirm_password" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#00811F]">
            </div>
            <button type="submit" class="bg-[#00811F] text-white px-6 py-2 rounded-md hover:bg-green-700 transition">Wachtwoord wijzigen</button>
        </form>
        <?php endif; ?>
        <p class="text-center mt-4">
            <a href="login.php" class="text-[#00811F] hover:underline">Terug naar inloggen</a>
        </p>
    </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>

<script>
    // Same navbar script as login.php
    (function() {
        const toggle = document.getElementById('programma-toggle');
        const menu = document.getElementById('programma-menu');
        if (!toggle || !menu) return;
        function openMenu() {
            menu.classList.remove('hidden');
            toggle.setAttribute('aria-expanded', 'true');
        }
        function closeMenu() {
            menu.classList.add('hidden');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus();
        }
        function toggleMenu() {
            if (menu.classList.contains('hidden')) openMenu();
            else closeMenu();
        }
        toggle.addEventListener('click', function(e){
            e.preventDefault();
            toggleMenu();
        });
        toggle.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleMenu();
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (menu.classList.contains('hidden')) openMenu();
            }
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (!menu.classList.contains('hidden')) closeMenu();
            }
        });
        document.addEventListener('click', function(e) {
            const target = e.target;
            if (!menu.contains(target) && !toggle.contains(target)) {
                if (!menu.classList.contains('hidden')) closeMenu();
            }
        });
        const items = menu.querySelectorAll('[role="menuitem"]');
        items.forEach(item => {
            item.setAttribute('tabindex', '0');
            item.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeMenu();
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    const next = item.nextElementSibling || menu.querySelector('[role="menuitem"]');
                    if (next) next.focus();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    const prev = item.previousElementSibling || menu.querySelector('[role="menuitem"]:last-child');
                    if (prev) prev.focus();
                }
            });
        });
    })();

</script>
</body>
</html>