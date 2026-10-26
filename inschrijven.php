<?php
session_start();
require 'db.php';
require 'helpers.php';

$banner1 = 'images/banner_website_01.jpg';
$banner2 = 'images/banner_website_02.jpg';
$banner3 = null;
$banner4 = null;
$message = '';
$messageType = '';

$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$event = null;

try {
    $banner1 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner1'")->fetchColumn() ?: $banner1;
    $banner2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner2'")->fetchColumn() ?: $banner2;
    $banner3 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner3'")->fetchColumn() ?: null;
    $banner4 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner4'")->fetchColumn() ?: null;

    if ($eventId) {
        $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ? AND approval_status = ? LIMIT 1');
        $stmt->execute([$eventId, 'approved']);
        $event = $stmt->fetch();
    }
} catch (Exception $e) {
    $event = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $event) {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($fullName === '' || $email === '') {
        $message = 'Vul je volledige naam en e-mailadres in.';
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Vul een geldig e-mailadres in.';
        $messageType = 'error';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO inschrijven (event_id, event_title, full_name, email, created_at) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([$event['id'], $event['title'], $fullName, $email]);
            $message = 'Je inschrijving is ontvangen. Bedankt!';
            $messageType = 'success';
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $message = 'Dit e-mailadres is al ingeschreven voor dit evenement.';
                $messageType = 'error';
            } else {
                $message = 'Er ging iets mis bij het inschrijven. Probeer het later opnieuw.';
                $messageType = 'error';
            }
        }
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="modulepreload" as="script" href="build/assets/app-CAiCLEjY.js"><link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>"><script type="module" src="build/assets/app-CAiCLEjY.js"></script>
    <title>Inschrijven</title>
    <meta name="description" content="Inschrijven voor een evenement van SociaalAI Lab Rotterdam.">
    <link rel="icon" type="image/png" href="images/Pixels_icon.png">
    <link rel="stylesheet" href="ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body class="bg-gradient-to-br from-[#00811F] to-[#b9eb34]">
<div class="banner-wrapper">
    <div class="banner banner-1 active">
        <img class="" src="<?php echo htmlspecialchars($banner1); ?>">
    </div>
    <div class="banner banner-2">
        <img class="" src="<?php echo htmlspecialchars($banner2); ?>">
    </div>
    <?php if ($banner3): ?>
    <div class="banner banner-3">
        <img class="" src="<?php echo htmlspecialchars($banner3); ?>">
    </div>
    <?php endif; ?>
    <?php if ($banner4): ?>
    <div class="banner banner-4">
        <img class="" src="<?php echo htmlspecialchars($banner4); ?>">
    </div>
    <?php endif; ?>
</div>

<?php
$navPrefix = '';
include __DIR__ . '/navbar.php';
?>

<main id="main-content" tabindex="-1" class="min-h-[60vh] flex items-start justify-center px-4 sm:px-6 py-12 sm:py-16">
    <section class="bg-white shadow-xl p-6 sm:p-8 max-w-md w-full mx-auto rounded-lg">
        <?php if (!$event): ?>
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 mb-2">Evenement niet gevonden</h1>
            <p class="text-gray-700 text-sm sm:text-base">Het evenement bestaat niet of is niet meer beschikbaar.</p>
        <?php else: ?>
            <h1 class="text-2xl sm:text-3xl font-semibold text-gray-900 mb-3">Inschrijven</h1>
            <p class="text-gray-700 mb-8 text-base">
                <?php echo renderEditorInline($event['title']); ?>
                <?php if (!empty($event['date'])): ?>
                    - <?php echo htmlspecialchars(formatEventDateWithWeekdayDisplay($event['date'])); ?>
                <?php endif; ?>
            </p>

            <?php if ($message): ?>
                <div class="mb-8 p-5 rounded text-base <?php echo $messageType === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <div>
                    <label for="full_name" class="block text-gray-700 font-medium text-base mb-2">Volledige naam</label>
                    <input type="text" id="full_name" name="full_name" required class="w-full px-4 py-3 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#00811F] text-base">
                </div>
                <div>
                    <label for="email" class="block text-gray-700 font-medium text-base mb-2">E-mailadres</label>
                    <input type="email" id="email" name="email" required class="w-full px-4 py-3 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#00811F] text-base">
                </div>
                <button type="submit" class="bg-[#00811F] text-white px-12 py-3 rounded-md hover:bg-green-700 transition font-medium text-base">Inschrijven</button>
            </form>
        <?php endif; ?>
    </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>

<script>
    (function() {
        const toggle = document.getElementById('programma-toggle');
        const menu = document.getElementById('programma-menu');
        const caret = document.getElementById('programma-caret');

        if (!toggle || !menu) return;

        function openMenu() {
            menu.classList.remove('hidden');
            toggle.setAttribute('aria-expanded', 'true');
            if (caret) {
                caret.classList.add('rotate-180');
            }
            const first = menu.querySelector('[role="menuitem"]');
            if (first) first.focus();
        }

        function closeMenu() {
            menu.classList.add('hidden');
            toggle.setAttribute('aria-expanded', 'false');
            if (caret) {
                caret.classList.remove('rotate-180');
            }
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


    const banners = document.querySelectorAll('.banner');
    let current = 0;

    setInterval(() => {
      banners[current].classList.remove('active');
      current = (current + 1) % banners.length;
      banners[current].classList.add('active');
    }, 10000);
</script>
</body>
</html>
