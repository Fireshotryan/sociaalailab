<?php 
session_start();
require 'db.php';
require 'helpers.php';

// Fallback banners
$banner1 = 'images/banner_website_01.jpg';
$banner2 = 'images/banner_website_02.jpg';
$banner3 = null;
$banner4 = null;

try {
    $banner1 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner1'")->fetchColumn() ?: $banner1;
    $banner2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner2'")->fetchColumn() ?: $banner2;
    $banner3 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner3'")->fetchColumn() ?: null;
    $banner4 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner4'")->fetchColumn() ?: null;

    $stmt = $pdo->prepare("SELECT * FROM pages WHERE page_key = 'evenementen' ORDER BY (sort_order IS NULL OR sort_order = 0) ASC, sort_order ASC, created_at ASC, id ASC");
    $stmt->execute();
    $pageBlocks = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT * FROM events WHERE approval_status = 'approved' AND COALESCE(end_date, date) >= CURDATE() AND date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) ORDER BY date, time");
    $stmt->execute();
    $events = $stmt->fetchAll();
} catch (Exception $e) {
    $pageBlocks = [];
    $events = [];
}

$pageHeadingIndex = null;
foreach ($pageBlocks as $index => $block) {
    if (trim((string)($block['title'] ?? '')) !== '') {
        $pageHeadingIndex = $index;
        break;
    }
}

?>

<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="modulepreload" as="script" href="build/assets/app-CAiCLEjY.js"><link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>"><script type="module" src="build/assets/app-CAiCLEjY.js"></script>    <title>Event</title>
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
    <?php if ($pageHeadingIndex === null): ?>
        <h1 class="visually-hidden">Evenementen</h1>
    <?php endif; ?>
    <?php foreach ($pageBlocks as $blockIndex => $block): ?>
        <?php
        $metaArr = $block['meta'] ? json_decode($block['meta'], true) : [];
        $hasImage = !empty($block['image']);
        $hasText = !empty($block['title']) || !empty($block['body']);
        $imagePosition = $metaArr['image_position'] ?? 'normal';
        if (!in_array($imagePosition, ['normal', 'left', 'right'], true)) $imagePosition = 'normal';
        $greenText = trim((string)($metaArr['green_text'] ?? ($metaArr['green_heading'] ?? '')));
        $greenTextPosition = $metaArr['green_text_position'] ?? 'above';
        if (!in_array($greenTextPosition, ['above', 'below'], true)) $greenTextPosition = 'above';
        
        $flexDir = 'column';
        if ($imagePosition === 'left' && $hasText) {
            $flexDir = 'row';
        } elseif ($imagePosition === 'right' && $hasText) {
            $flexDir = 'row';
        }
        $divStyle = '';
        if ($hasImage && $imagePosition !== 'normal' && $hasText) {
            $divStyle = 'display: flex; flex-direction: ' . $flexDir . '; align-items: flex-start; gap: 2rem;';
        }
        ?>
        <section class="bg-white shadow-lg p-8 max-w-6xl mx-auto my-12">
            <div style="<?php echo $divStyle; ?>">
                <?php if ($hasImage && $imagePosition === 'left' && $hasText): ?>
                    <div style="flex: 0 0 50%; min-width: 0; max-width: 600px;">
                        <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars(getPageImageAltText($block), ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-auto object-cover shadow-md">
                    </div>
                <?php elseif ($hasImage && !$hasText): ?>
                    <div style="width: 100%;">
                        <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars(getPageImageAltText($block), ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-auto object-cover shadow-md">
                    </div>
                <?php endif; ?>

                <?php if ($hasText): ?>
                <div style="<?php echo ($hasImage && $imagePosition !== 'normal') ? 'flex: 1; padding: 0 1.5rem;' : ''; ?>">
                    <?php if ($greenText !== '' && $greenTextPosition === 'above'): ?>
                        <div class="green-highlight mb-3"><?php echo nl2br(htmlspecialchars($greenText)); ?></div>
                    <?php endif; ?>
                    <?php if (trim((string)($block['title'] ?? '')) !== ''): ?>
                        <?php if ($blockIndex === $pageHeadingIndex): ?>
                            <h1 class="text-3xl font-bold text-gray-900 mb-4"><?php echo renderEditorInline($block['title']); ?></h1>
                        <?php else: ?>
                            <h2 class="text-3xl font-bold text-gray-900 mb-4"><?php echo renderEditorInline($block['title']); ?></h2>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="text-gray-700 text-lg"><?php echo renderEditorBlock($block['body']); ?></div>
                    <?php if ($greenText !== '' && $greenTextPosition === 'below'): ?>
                        <div class="green-highlight mb-3"><?php echo nl2br(htmlspecialchars($greenText)); ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasImage && $imagePosition === 'right' && $hasText): ?>
                    <div style="flex: 0 0 50%; min-width: 0; max-width: 600px;">
                        <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars(getPageImageAltText($block), ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-auto object-cover shadow-md">
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($hasImage && $imagePosition === 'normal' && $hasText): ?>
                <div class="mt-6" style="max-width: 600px;">
                    <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars(getPageImageAltText($block), ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-auto object-cover shadow-md">
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <?php if (empty($events)): ?>
        <section class="bg-white shadow-lg p-8 max-w-4xl mx-auto my-12 text-center">
            <h2 class="text-2xl font-semibold text-gray-900 mb-2">Geen evenementen in de komende 2 weken</h2>
            <p class="text-gray-700">Houd de agenda in de gaten voor nieuwe activiteiten.</p>
        </section>
    <?php else: ?>
        <?php foreach ($events as $event): ?>
            <section class="flex flex-col md:flex-row items-center gap-10 bg-white shadow-lg p-8 max-w-6xl mx-auto my-12">
                <div class="flex-1">
                    <span class="inline-block text-white text-sm font-medium px-4 py-1 mb-4" style="background-color:#ce0245;">Evenement</span>
                    <h2 class="text-2xl md:text-3xl font-semibold mb-4 text-gray-900"><?php echo renderEditorInline($event['title']); ?></h2>
                    <div class="space-y-4">
                        <div class="flex items-center space-x-3">
                            <i class="fa-regular fa-calendar text-[#00811F] ml-[2px]  text-3xl"></i>
                            <?php $dateDisplay = formatEventDateWithWeekdayDisplay($event['date']); $timeDisplay = $event['time'] ? formatEventTimeDisplay($event['time']) : ''; ?>
                            <p class="text-gray-700"><strong> Wanneer:</strong> <?php echo htmlspecialchars($dateDisplay); ?><?php if ($timeDisplay) echo ' - ' . htmlspecialchars($timeDisplay); ?></p>
                        </div>
                        <div class="flex items-center space-x-3">
                            <i class="fa-solid fa-location-dot text-[#00811F] ml-1 text-3xl"></i>
                            <?php $loc = $event['location'] ?: 'Rotterdam - Hillevliet 90'; ?>
                            <p class="text-gray-700 ml-1 "><strong>Waar:</strong> <a href="<?php echo googleMapsDirectionsUrl($loc); ?>" target="_blank" rel="noopener noreferrer" class="underline hover:text-[#00811F]"><?php echo htmlspecialchars($loc); ?></a></p>
                        </div>
                        <div class="flex mb-6 space-x-3">
                            <i class="fa-solid fa-bullseye text-[#00811F] text-3xl"></i>
                            <div class="text-gray-700 pb-3 "><strong> Wat:</strong><div class="mt-1"><?php echo renderEditorBlock($event['description']); ?></div></div>
                        </div>
                    </div>
                    <?php if (!empty($event['signup_embed'])): ?>
                    <a href="<?php echo htmlspecialchars($event['signup_embed']); ?>" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center bg-[#00811F] text-white font-semibold px-6 py-3 rounded-md shadow hover:bg-[#006f19] transition">
                        Inschrijven
                    </a>
                    <?php endif; ?>
                </div>
                <?php if ($event['image']): ?>
                <div class="flex-1">
                    <img src="uploads/<?php echo htmlspecialchars($event['image']); ?>" alt="<?php echo htmlspecialchars(getEventImageAltText($event), ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-auto object-cover shadow-md">
                </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
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