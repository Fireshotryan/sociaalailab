<?php 
session_start();
require 'db.php';
require 'helpers.php';

$banner1 = 'images/banner_website_01.jpg';
$banner2 = 'images/banner_website_02.jpg';
$banner3 = null;
$banner4 = null;

try {
    $banner1 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner1'")->fetchColumn() ?: $banner1;
    $banner2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner2'")->fetchColumn() ?: $banner2;
    $banner3 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner3'")->fetchColumn() ?: null;
    $banner4 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner4'")->fetchColumn() ?: null;

    $stmt = $pdo->prepare("SELECT * FROM pages WHERE page_key = 'ons-team' ORDER BY (sort_order IS NULL OR sort_order = 0) ASC, sort_order ASC, created_at ASC, id ASC");
    $stmt->execute();
    $pageBlocks = $stmt->fetchAll();
} catch (Exception $e) {
    $pageBlocks = [];
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="modulepreload" as="script" href="build/assets/app-CAiCLEjY.js"><link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>"><script type="module" src="build/assets/app-CAiCLEjY.js"></script>    <title>Ons team - SociaalAI Lab</title>
    <meta name="description" content="Maak kennis met de mensen die werken in het SociaalAI Lab Rotterdam.">
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

<main class="ons-team-page watdoenwe-page">
    <section class="bg-white shadow-lg p-8 max-w-6xl mx-auto my-12 text-padding ons-team-intro" tabindex="0">
        <h2 class="text-2xl md:text-3xl font-semibold mb-4 text-gray-900">Ons team</h2>
        <div class="text-gray-700 leading-relaxed">Maak kennis met de mensen die werken in het Lab: het team achter SociaalAI Lab Rotterdam.</div>
    </section>

    <?php if (empty($pageBlocks)): ?>
    <section class="bg-white shadow-lg p-8 max-w-6xl mx-auto my-12 text-padding" tabindex="0">
        <p class="text-gray-700">Teamleden volgen binnenkort. Voeg ze toe via Admin &gt; Ons team.</p>
    </section>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-4 max-w-6xl mx-auto">
        <?php foreach ($pageBlocks as $block): ?>
        <?php
        $metaArr = $block['meta'] ? json_decode($block['meta'], true) : [];
        $role = trim((string)($metaArr['role'] ?? ''));
        $imagePath = trim((string)($block['image'] ?? ''));
        if ($imagePath !== '' && strpos($imagePath, 'images/') !== 0 && strpos($imagePath, 'uploads/') !== 0 && strpos($imagePath, '../') !== 0 && !preg_match('#^https?://#i', $imagePath)) {
            $imagePath = 'uploads/' . $imagePath;
        }
        $hasImage = $imagePath !== '';
        $hasText = !empty($block['title']) || !empty($block['body']) || $role !== '';
        ?>
        <article class="team-card bg-white shadow-lg" tabindex="0">
            <?php if ($hasImage): ?>
            <img src="<?php echo htmlspecialchars($imagePath); ?>" alt="<?php echo htmlspecialchars(strip_tags((string)($block['title'] ?? 'Teamlid'))); ?>" class="team-photo" loading="lazy">
            <?php else: ?>
            <div class="team-photo-placeholder" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr(trim(strip_tags((string)($block['title'] ?? '?'))), 0, 1))); ?></div>
            <?php endif; ?>
            <div class="team-card-body">
                <?php if (!empty($block['title'])): ?>
                <h3 class="font-bold text-xl mb-1 text-gray-900"><?php echo renderEditorInline($block['title']); ?></h3>
                <?php endif; ?>
                <?php if ($role !== ''): ?>
                <p class="team-role"><?php echo htmlspecialchars($role); ?></p>
                <?php endif; ?>
                <?php if (!empty($block['body'])): ?>
                <div class="text-gray-700 leading-relaxed mt-2 team-bio"><?php echo renderEditorBlock($block['body']); ?></div>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
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
