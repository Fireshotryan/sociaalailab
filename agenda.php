<?php 
session_start();
require 'db.php';
require 'helpers.php';


// Count the total upcoming evens
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE approval_status = 'approved' AND COALESCE(end_date, date) >= CURDATE()");
$stmt->execute();
$totalUpcoming = $stmt->fetchColumn();

// Count the total past events
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE approval_status = 'approved' AND COALESCE(end_date, date) < CURDATE()");
$stmt->execute();
$totalPast = $stmt->fetchColumn();


$banner1 = 'images/banner_website_01.jpg';
$banner2 = 'images/banner_website_02.jpg';
$banner3 = null;
$banner4 = null;

try {
    $banner1 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner1'")->fetchColumn() ?: $banner1;
    $banner2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner2'")->fetchColumn() ?: $banner2;
    $banner3 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner3'")->fetchColumn() ?: null;
    $banner4 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner4'")->fetchColumn() ?: null;
    $stmt = $pdo->prepare("SELECT * FROM events WHERE approval_status = 'approved' AND COALESCE(end_date, date) >= CURDATE() ORDER BY date, time");
    $stmt->execute();
    $events = $stmt->fetchAll();
} catch (Exception $e) {
    $events = [];
}

?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="modulepreload" as="script" href="build/assets/app-CAiCLEjY.js"><link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>"><script type="module" src="build/assets/app-CAiCLEjY.js"></script>    <title>programma</title>
    <meta name="description" content="SociaalAI helpt inwoners sterker te staan in een steeds digitalere wereld. We doen dit door Rotterdammers actief mee te laten denken, praten en beslissen over kunstmatige intelligentie.">
    <link rel="icon" type="image/png" href="images/Pixels_icon.png">
    <link rel="stylesheet" href="ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body class="bg-gradient-to-br from-[#00811F] to-[#b9eb34]">
<div class="banner-wrapper">
    <div class="banner banner-1 active">
        <img class="" src="<?php echo htmlspecialchars($banner1); ?>" alt="<?php echo htmlspecialchars(getSiteBannerAltText($pdo, 1, $banner1), ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <div class="banner banner-2">
        <img class="" src="<?php echo htmlspecialchars($banner2); ?>" alt="<?php echo htmlspecialchars(getSiteBannerAltText($pdo, 2, $banner2), ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <?php if ($banner3): ?>
    <div class="banner banner-3">
        <img class="" src="<?php echo htmlspecialchars($banner3); ?>" alt="<?php echo htmlspecialchars(getSiteBannerAltText($pdo, 3, $banner3), ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <?php endif; ?>
    <?php if ($banner4): ?>
    <div class="banner banner-4">
        <img class="" src="<?php echo htmlspecialchars($banner4); ?>" alt="<?php echo htmlspecialchars(getSiteBannerAltText($pdo, 4, $banner4), ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <?php endif; ?>
</div>

<?php
$navPrefix = '';
include __DIR__ . '/navbar.php';
?>

<main id="main-content" tabindex="-1">
    <h1 class="visually-hidden">Agenda</h1>
    <nav id="agenda-terugblik-switch" class="mobile flex items-center justify-center gap-1 " style="scroll-margin-top: 110px;" aria-label="Agenda en terugblik">
        <div class="agenda bg-white p-6 shadow-lg max-w-xl mt-6 w-full border-r text-center">
            <a class="block text-2xl text-green-800 font-semibold" href="agenda.php#agenda-terugblik-switch" aria-current="page">
                Agenda (<?php echo $totalUpcoming; ?>)
            </a>
        </div>
        <div class="terugblik bg-white p-6 max-w-xl mt-6 w-full text-center border-r border-gray-500">
            <a class="block text-2xl text-[#000000] font-semibold" href="terugblikken.php#agenda-terugblik-switch">
                Terugblik
            </a>
        </div>
    </nav>

<?php
require 'db.php';
$stmt = $pdo->prepare("SELECT * FROM events WHERE approval_status = 'approved' AND COALESCE(end_date, date) >= CURDATE() ORDER BY date, time");
$stmt->execute();
$events = $stmt->fetchAll();
foreach ($events as $event):
?>
    <?php
    $dateDisplay = formatEventDateWithWeekdayDisplay($event['date']);
    $endDateDisplay = !empty($event['end_date']) ? formatEventDateDisplay($event['end_date']) : null;
    $timeDisplay = $event['time'] ? formatEventTimeDisplay($event['time']) : '';
    $timeEndDisplay = $event['time_end'] ? formatEventTimeDisplay($event['time_end']) : '';
    $signupEmbed = trim((string)($event['signup_embed'] ?? ''));
    $dateTs = strtotime((string)$event['date']);
    $dayMonth = $dateTs ? date('d.m', $dateTs) : $dateDisplay;
    $year = $dateTs ? date('Y', $dateTs) : '';
    $loc = $event['location'] ?: 'Rotterdam - Hillevliet 90';
    $mapsLocationUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode((string)$loc);
    $eventImageName = trim((string)($event['image'] ?? ''));
    $hasValidImage = $eventImageName !== '' && file_exists(__DIR__ . '/uploads/' . $eventImageName);
    ?>
    <section class="agenda-event-card flex flex-col md:flex-row items-center gap-10 bg-white shadow-lg p-8 max-w-6xl mx-auto my-12" tabindex="0">
        <div class="flex-1">
            <span class="inline-block text-white text-sm font-medium px-4 py-1 mb-4" style="background-color:#d12254;">Evenement</span>
            <h2 class="text-2xl md:text-3xl font-semibold mb-4 text-gray-900"><?php echo renderEditorInline($event['title']); ?></h2>
            <div class="space-y-4">
                <div class="flex items-center space-x-3">
                    <i class="fa-regular fa-calendar text-[#00811F] ml-[2px] text-3xl"></i>
                    <p class="text-gray-700"><strong> Wanneer:</strong> <?php echo htmlspecialchars($dateDisplay); ?><?php if ($endDateDisplay) { echo ' t/m ' . htmlspecialchars($endDateDisplay); } ?></p>
                </div>
                <?php if ($timeDisplay || $timeEndDisplay): ?>
                <div class="flex items-center space-x-3">
                    <i class="fa-solid fa-clock text-[#00811F] ml-[2px] text-3xl"></i>
                    <p class="text-gray-700"><strong>Hoelaat:</strong> <?php echo htmlspecialchars($timeDisplay); ?><?php if ($timeEndDisplay) { echo ' - ' . htmlspecialchars($timeEndDisplay); } ?> uur</p>
                </div>
                <?php endif; ?>
                <div class="flex items-center space-x-3">
                    <i class="fa-solid fa-location-dot text-[#00811F] ml-1 text-3xl"></i>
                    <p class="text-gray-700 ml-1 "><strong>Waar:</strong> <a href="<?php echo htmlspecialchars($mapsLocationUrl); ?>" target="_blank" rel="noopener noreferrer" class="underline hover:text-[#00811F]"><?php echo htmlspecialchars($loc); ?></a></p>
                </div>
                <div class="flex mb-2 space-x-3">
                    <i class="fa-solid fa-bullseye text-[#00811F] text-3xl"></i>
                    <div class="text-gray-700 pb-1 "><strong> Wat:</strong><div class="mt-1"><?php echo renderEditorBlock($event['description']); ?></div></div>
                </div>
            </div>
            <?php if ($hasValidImage): ?>
            <div class="mobile-image mb-4">
                <img src="uploads/<?php echo htmlspecialchars($eventImageName); ?>" alt="<?php echo htmlspecialchars(getEventImageAltText($event), ENT_QUOTES, 'UTF-8'); ?>" class="image-template-photo" style="object-fit: contain;">
            </div>
            <?php endif; ?>
            <?php if ($signupEmbed !== ''): ?>
            <?php echo renderAanmelderEmbed($signupEmbed); ?>
            <?php endif; ?>
            <a href="event-detail.php?id=<?php echo (int)$event['id']; ?>" class="mt-4 ml-4 inline-flex items-center bg-[#00811F] text-white font-semibold px-6 py-3 rounded-md shadow hover:bg-[#006f19] transition">
                Meer info
            </a>
        </div>
        <?php if ($hasValidImage): ?>
        <div class="flex-1 desktop-image">
            <div class="image-template-wrap">
                <img src="uploads/<?php echo htmlspecialchars($eventImageName); ?>" alt="<?php echo htmlspecialchars(getEventImageAltText($event), ENT_QUOTES, 'UTF-8'); ?>" class="image-template-photo" style="object-fit: contain;">
                <!--
                <div class="image-template-badge">
                    <span><?php echo htmlspecialchars($dayMonth); ?></span>
                    <span><?php echo htmlspecialchars($year); ?></span>
                </div>
                -->
                <!-- <span class="image-template-square image-template-square-left"></span>
                <span class="image-template-square image-template-square-right"></span> -->
            </div>
        </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php if (!empty($_SESSION['can_access_admin'])): ?>
    <a href="admin.php" title="Voeg evenement toe" class="fixed bottom-6 right-6 bg-[#00811F] text-white rounded-full w-12 h-12 flex items-center justify-center text-3xl shadow-lg">+</a>
<?php endif; ?>
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
