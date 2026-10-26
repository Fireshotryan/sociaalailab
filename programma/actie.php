<?php
session_start();
require '../db.php';
require '../helpers.php';

// Fallback banners
$banner1 = '../images/banner_website_01.jpg';
$banner2 = '../images/banner_website_02.jpg';
$banner3 = null;
$banner4 = null;

try {
    $b1 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner1'")->fetchColumn();
    $b2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner2'")->fetchColumn();
    $b3 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner3'")->fetchColumn();
    $b4 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner4'")->fetchColumn();
    if ($b1) $banner1 = (strpos($b1, 'images/') === 0 || strpos($b1, 'uploads/') === 0) ? '../' . $b1 : $b1;
    if ($b2) $banner2 = (strpos($b2, 'images/') === 0 || strpos($b2, 'uploads/') === 0) ? '../' . $b2 : $b2;
    if ($b3) $banner3 = (strpos($b3, 'images/') === 0 || strpos($b3, 'uploads/') === 0) ? '../' . $b3 : $b3;
    if ($b4) $banner4 = (strpos($b4, 'images/') === 0 || strpos($b4, 'uploads/') === 0) ? '../' . $b4 : $b4;
} catch (Exception $e) {
    // Use fallbacks
}
?>

<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="modulepreload" as="script" href="../build/assets/app-CAiCLEjY.js"><link rel="stylesheet" href="../style.css?v=<?php echo filemtime(__DIR__.'/../style.css'); ?>"><script type="module" src="../build/assets/app-CAiCLEjY.js"></script>    <title>Actie, Onderzoek & Ontwerp</title>
    <meta name="description" content="SociaalAI helpt inwoners sterker te staan in een steeds digitalere wereld. We doen dit door Rotterdammers actief mee te laten denken, praten en beslissen over kunstmatige intelligentie.">
    <link rel="icon" type="image/png" href="../images/Pixels_icon.png">
    <link rel="stylesheet" href="../ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body class="page-actie bg-gradient-to-br from-[#00811F] to-[#b9eb34]">

<div class="banner-wrapper">
    <img src="<?php echo htmlspecialchars($banner1); ?>" alt="Banner 1" class="banner active w-full object-cover h-60 md:h-96">
    <img src="<?php echo htmlspecialchars($banner2); ?>" alt="Banner 2" class="banner w-full object-cover h-60 md:h-96">
    <?php if ($banner3): ?>
    <img src="<?php echo htmlspecialchars($banner3); ?>" alt="Banner 3" class="banner w-full object-cover h-60 md:h-96">
    <?php endif; ?>
    <?php if ($banner4): ?>
    <img src="<?php echo htmlspecialchars($banner4); ?>" alt="Banner 4" class="banner w-full object-cover h-60 md:h-96">
    <?php endif; ?>
</div>

<?php
$navPrefix = '../';
include __DIR__ . '/../navbar.php';
?>

<!-- Pagina content -->
<main class="watdoenwe-page">
    <?php
    // Fetch page blocks from database
    $pageBlocks = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE page_key = 'programma-actie' ORDER BY (sort_order IS NULL OR sort_order = 0) ASC, sort_order ASC, created_at ASC, id ASC");
        $stmt->execute();
        $pageBlocks = $stmt->fetchAll();
    } catch (Exception $e) {
        $pageBlocks = [];
    }

    $cardBlocks = array_values(array_filter($pageBlocks, function ($block) {
        $title = trim((string)($block['title'] ?? ''));
        return $title !== 'Actie, Onderzoek & Ontwerp';
    }));

    // Display custom cards from admin
    ?>

    <section class="text-block bg-white shadow-lg p-8 max-w-6xl mx-auto my-12">
        <div class="text-block-content">
            <h3 class="text-2xl font-semibold mb-4 text-gray-900">Actie, Onderzoek &amp; Ontwerp</h3>
            <div class="text-gray-700 leading-relaxed">We ontwikkelen samen met bewoners en organisaties innovatieve AI-toepassingen die bijdragen aan een betere samenleving.</div>
        </div>
    </section>

    <div id="watdoenwe-switch" style="scroll-margin-top: 110px;">
        <div class="watdoenwe-item">
            <a href="kennis.php#watdoenwe-switch"><h1>Kennis & Vaardigheden</h1></a>
        </div>
        <div class="watdoenwe-item">
            <a href="actie.php#watdoenwe-switch"><h1>Actie, Onderzoek & Ontwerp</h1></a>
        </div>
        <div class="watdoenwe-item">
            <a href="faciliteit.php#watdoenwe-switch"><h1>Faciliteiten</h1></a>
        </div>
    </div>

    <!-- Custom cards from database -->
    <?php if (!empty($cardBlocks)): ?>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 p-4">
        <?php foreach ($cardBlocks as $block): ?>
            <?php
            $metaArr = $block['meta'] ? json_decode($block['meta'], true) : [];
            $imageLink = trim((string)($metaArr['info_link'] ?? ''));
            $isExternalImageLink = preg_match('#^https?://#i', $imageLink) === 1;
            $imagePosition = $metaArr['image_position'] ?? 'normal';
            if (!in_array($imagePosition, ['normal', 'left', 'right'], true)) $imagePosition = 'normal';
            $greenText = trim((string)($metaArr['green_text'] ?? ($metaArr['green_heading'] ?? '')));
            $greenTextPosition = $metaArr['green_text_position'] ?? 'above';
            if (!in_array($greenTextPosition, ['above', 'below'], true)) $greenTextPosition = 'above';

            $flexDir = 'column';
            if ($imagePosition === 'left') {
                $flexDir = 'row';
            } elseif ($imagePosition === 'right') {
                $flexDir = 'row';
            }

            $hasImage = !empty($block['image']);
            $hasText = !empty($block['title']) || !empty($block['body']);
            $cardStyle = 'display: flex; flex-direction: ' . $flexDir . '; ';
            if ($imagePosition !== 'normal' && $hasText) {
                $cardStyle .= 'gap: 1rem; align-items: flex-start;';
            } else {
                $cardStyle .= 'gap: 0; justify-content: space-between; flex-direction: column;';
            }
            ?>
            <div class="bg-white p-4 shadow-lg" style="<?php echo $cardStyle; ?>">
                <?php if ($hasImage && $imagePosition === 'left'): ?>
                    <?php
                    $imagePath = trim((string)$block['image']);
                    if (strpos($imagePath, 'images/') === 0 || strpos($imagePath, 'uploads/') === 0) {
                        $imageSrc = '../' . $imagePath;
                    } elseif (strpos($imagePath, '../') === 0 || preg_match('#^https?://#i', $imagePath)) {
                        $imageSrc = $imagePath;
                    } else {
                        $imageSrc = '../uploads/' . $imagePath;
                    }
                    ?>
                    <div style="<?php echo $hasText ? 'flex-shrink: 0; width: 120px; min-width: 120px; max-width: 120px;' : 'width: 100%;'; ?>">
                        <?php if ($imageLink !== ''): ?>
                            <a href="<?php echo htmlspecialchars($imageLink); ?>"<?php echo $isExternalImageLink ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> class="block">
                                <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($block['title'] ?? ''); ?>" class="watdoenwe-image-side">
                            </a>
                        <?php else: ?>
                            <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($block['title'] ?? ''); ?>" class="watdoenwe-image-side">
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div style="<?php echo ($hasImage && $imagePosition !== 'normal') ? 'flex: 1; padding: 0 1.5rem;' : ''; ?>">
                <?php if ($greenText !== '' && $greenTextPosition === 'above'): ?>
                    <div class="green-highlight mb-3"><?php echo nl2br(htmlspecialchars($greenText)); ?></div>
                <?php endif; ?>
                <?php if (!empty($block['title'])): ?>
                    <h3 class="text-lg font-semibold mb-2"><?php echo renderEditorInline($block['title']); ?></h3>
                <?php endif; ?>
                <?php if (!empty($block['body'])): ?>
                    <div class="mb-2 text-gray-700 text-sm"><?php echo renderEditorBlock($block['body']); ?></div>
                <?php endif; ?>
                <?php if ($greenText !== '' && $greenTextPosition === 'below'): ?>
                    <div class="green-highlight mb-3"><?php echo nl2br(htmlspecialchars($greenText)); ?></div>
                <?php endif; ?>
                </div>

                <?php if ($hasImage && $imagePosition === 'right'): ?>
                    <?php
                    $imagePath = trim((string)$block['image']);
                    if (strpos($imagePath, 'images/') === 0 || strpos($imagePath, 'uploads/') === 0) {
                        $imageSrc = '../' . $imagePath;
                    } elseif (strpos($imagePath, '../') === 0 || preg_match('#^https?://#i', $imagePath)) {
                        $imageSrc = $imagePath;
                    } else {
                        $imageSrc = '../uploads/' . $imagePath;
                    }
                    ?>
                    <div style="<?php echo $hasText ? 'flex-shrink: 0; width: 120px; min-width: 120px; max-width: 120px;' : 'width: 100%;'; ?>">
                        <?php if ($imageLink !== ''): ?>
                            <a href="<?php echo htmlspecialchars($imageLink); ?>"<?php echo $isExternalImageLink ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> class="block">
                                <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($block['title'] ?? ''); ?>" class="watdoenwe-image-side">
                            </a>
                        <?php else: ?>
                            <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($block['title'] ?? ''); ?>" class="watdoenwe-image-side">
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($hasImage && $imagePosition === 'normal'): ?>
                    <?php
                    $imagePath = trim((string)$block['image']);
                    if (strpos($imagePath, 'images/') === 0 || strpos($imagePath, 'uploads/') === 0) {
                        $imageSrc = '../' . $imagePath;
                    } elseif (strpos($imagePath, '../') === 0 || preg_match('#^https?://#i', $imagePath)) {
                        $imageSrc = $imagePath;
                    } else {
                        $imageSrc = '../uploads/' . $imagePath;
                    }
                    ?>
                    <div style="<?php echo $hasText ? 'margin-top: auto; width: 100%; max-width: 560px;' : 'width: 100%;'; ?>">
                        <?php if ($imageLink !== ''): ?>
                            <a href="<?php echo htmlspecialchars($imageLink); ?>"<?php echo $isExternalImageLink ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> class="block">
                                <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($block['title'] ?? ''); ?>" class="watdoenwe-image-normal">
                            </a>
                        <?php else: ?>
                            <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($block['title'] ?? ''); ?>" class="watdoenwe-image-normal">
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/../footer.php'; ?>

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
            // focus eerste item voor keyboard users
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

        // klik op de knop: toggle
        toggle.addEventListener('click', function(e){
            e.preventDefault();
            toggleMenu();
        });

        // keyboard op de knop: Enter of Space opent/toggle
        toggle.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleMenu();
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (menu.classList.contains('hidden')) openMenu();
            }
        });

        // sluit op escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (!menu.classList.contains('hidden')) closeMenu();
            }
        });

        // klik buiten: sluit menu
        document.addEventListener('click', function(e) {
            const target = e.target;
            if (!menu.contains(target) && !toggle.contains(target)) {
                if (!menu.classList.contains('hidden')) closeMenu();
            }
        });

        // optioneel: sluit en navigeer op menuitem click (voor a tags standaard)
        const items = menu.querySelectorAll('[role="menuitem"]');
        items.forEach(item => {
            item.setAttribute('tabindex', '0'); // focusable
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
