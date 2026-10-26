<?php 
session_start();
require 'db.php';
require 'helpers.php';

$banner1 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner1'")->fetchColumn() ?: 'images/banner_website_01.jpg';
$banner2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner2'")->fetchColumn() ?: 'images/banner_website_02.jpg';
$banner3 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner3'")->fetchColumn() ?: null;
$banner4 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner4'")->fetchColumn() ?: null;

$stmt = $pdo->prepare("SELECT * FROM pages WHERE page_key = 'over' ORDER BY (sort_order IS NULL OR sort_order = 0) ASC, sort_order ASC, created_at ASC, id ASC");
$stmt->execute();
$allBlocks = $stmt->fetchAll();

// Filter blocks to only show custom layout
$pageBlocks = [];
foreach ($allBlocks as $block) {
    $metaArr = $block['meta'] ? json_decode($block['meta'], true) : [];
    $layout = $metaArr['layout'] ?? 'custom';
    if ($layout === 'custom' || !in_array($layout, ['welcome', 'card', 'info', 'contact'], true)) {
        $pageBlocks[] = $block;
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="modulepreload" as="script" href="build/assets/app-CAiCLEjY.js"><link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>"><script type="module" src="build/assets/app-CAiCLEjY.js"></script>    <title>SociaalAI Lab</title>
    <meta name="description" content="SociaalAI helpt inwoners sterker te staan in een steeds digitalere wereld. We doen dit door Rotterdammers actief mee te laten denken, praten en beslissen over kunstmatige intelligentie.">
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

<main>
    <?php
    foreach ($pageBlocks as $block):
        $metaArr = $block['meta'] ? json_decode($block['meta'], true) : [];
        $hasImage = !empty($block['image']);
        $hasText = !empty($block['title']) || !empty($block['body']);
        $imagePosition = $metaArr['image_position'] ?? 'normal';
        if (!in_array($imagePosition, ['normal', 'left', 'right'], true)) $imagePosition = 'normal';
        $greenText = trim((string)($metaArr['green_text'] ?? ($metaArr['green_heading'] ?? '')));
        $greenTextPosition = $metaArr['green_text_position'] ?? 'above';
        if (!in_array($greenTextPosition, ['above', 'below'], true)) $greenTextPosition = 'above';
        
        $flexDir = 'column';
        $flexWrap = 'nowrap';
        if ($imagePosition === 'left' && $hasText) {
            $flexDir = 'row';
            $flexWrap = 'nowrap';
        } elseif ($imagePosition === 'right' && $hasText) {
            $flexDir = 'row';
            $flexWrap = 'nowrap';
        }
        $sectionStyle = "display: flex; flex-direction: " . $flexDir . "; flex-wrap: " . $flexWrap . ";";
        if ($imagePosition !== 'normal' && $hasText) {
            $sectionStyle .= " gap: 2rem; align-items: flex-start;";
        } else {
            $sectionStyle .= " gap: 1.5rem;";
        }
    ?>
        <section class="bg-white shadow-lg p-8 max-w-6xl mx-auto my-12" style="<?php echo $sectionStyle; ?>">
            <?php if ($hasImage && $imagePosition === 'left'): ?>
                <?php
                $imageStyle = '';
                if (!$hasText) {
                    $imageStyle = 'width: 100%;';
                } else {
                    $imageStyle = 'flex: 0 0 auto; max-width: 280px; width: 100%;';
                }
                ?>
                <div style="<?php echo $imageStyle; ?>">
                    <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars($block['title']); ?>" style="width: 100%; height: auto; border-radius: 0.5rem;">
                </div>
            <?php endif; ?>
            <?php if ($hasText): ?>
                <div style="<?php echo ($imagePosition !== 'normal' && $hasImage) ? 'flex: 1 1 auto; min-width: 0;' : ''; ?>">
                    <?php if ($greenText !== '' && $greenTextPosition === 'above'): ?>
                        <div class="green-highlight mb-3"><?php echo nl2br(htmlspecialchars($greenText)); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($block['title'])): ?>
                        <h3 class="text-2xl font-semibold mb-4 text-gray-900"><?php echo renderEditorInline($block['title']); ?></h3>
                    <?php endif; ?>
                    <?php if (!empty($block['body'])): ?>
                        <div class="text-gray-700 leading-relaxed"><?php echo renderEditorBlock($block['body']); ?></div>
                    <?php endif; ?>
                    <?php if ($greenText !== '' && $greenTextPosition === 'below'): ?>
                        <div class="green-highlight mb-3"><?php echo nl2br(htmlspecialchars($greenText)); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($hasImage && $imagePosition === 'right'): ?>
                <?php
                $imageStyle = '';
                if (!$hasText) {
                    $imageStyle = 'width: 100%;';
                } else {
                    $imageStyle = 'flex: 0 0 auto; max-width: 280px; width: 100%;';
                }
                ?>
                <div style="<?php echo $imageStyle; ?>">
                    <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars($block['title']); ?>" style="width: 100%; height: auto; border-radius: 0.5rem;">
                </div>
            <?php endif; ?>
            <?php if ($hasImage && $imagePosition === 'normal'): ?>
                <div style="width: 100%; max-width: 600px;">
                    <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars($block['title']); ?>" style="width: 100%; height: auto; border-radius: 0.5rem;">
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    
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