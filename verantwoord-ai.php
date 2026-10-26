<?php 
session_start();
require 'db.php';
require 'helpers.php';

$banner1 = 'images/banner_website_01.jpg';
$banner2 = 'images/banner_website_02.jpg';
$banner3 = null;
$banner4 = null;

try {
    $b1 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner1'")->fetchColumn();
    $b2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner2'")->fetchColumn();
    $b3 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner3'")->fetchColumn();
    $b4 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner4'")->fetchColumn();
    if ($b1) $banner1 = $b1;
    if ($b2) $banner2 = $b2;
    if ($b3) $banner3 = $b3;
    if ($b4) $banner4 = $b4;
} catch (Exception $e) {
}

try {
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE page_key = 'verantwoord-ai' ORDER BY (sort_order IS NULL OR sort_order = 0) ASC, sort_order ASC, created_at ASC, id ASC");
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
} catch (Exception $e) {
    $pageBlocks = [];
}
?>
<!doctype html>
<html lang="nl">
<head>
    <link rel="icon" type="image/png" href="images/Pixels_icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="modulepreload" as="script" href="build/assets/app-CAiCLEjY.js"><link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>"><script type="module" src="build/assets/app-CAiCLEjY.js"></script>    <title>Informatie SociaalAI Lab</title>
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
</div>

<?php
$navPrefix = '';
include __DIR__ . '/navbar.php';
?>

<main class="verantwoord-ai-page">
    <?php
    $totalBlocks = count($pageBlocks);
    $blockIndex = 0;
    $numberBlockIndex = 0;
    
    foreach ($pageBlocks as $block):
        $metaArr = $block['meta'] ? json_decode($block['meta'], true) : [];
        $hasImage = !empty($block['image']);
        $hasText = !empty($block['title']) || !empty($block['body']);
        
        // Bepaal of dit blok een nummer krijgt
        $isFirstBlock = ($blockIndex === 0);
        $isLastBlock = ($blockIndex === $totalBlocks - 1);
        $shouldShowNumber = !$isFirstBlock && !$isLastBlock;
        
        // Bepaal nummer positie (links/rechts afwisselen)
        $numberPosition = ($numberBlockIndex % 2 === 0) ? 'left' : 'right';
        
        $imagePosition = $metaArr['image_position'] ?? 'normal';
        if (!in_array($imagePosition, ['normal', 'left', 'right'], true)) $imagePosition = 'normal';
        $greenText = trim((string)($metaArr['green_text'] ?? ($metaArr['green_heading'] ?? '')));
        $greenTextPosition = $metaArr['green_text_position'] ?? 'above';
        if (!in_array($greenTextPosition, ['above', 'below'], true)) $greenTextPosition = 'above';
        
        // Layout bepalen
        $flexDir = 'column';
        $flexWrap = 'nowrap';
        if ($shouldShowNumber && $hasText) {
            $flexDir = 'row';
            $flexWrap = 'nowrap';
        } elseif ($imagePosition === 'left' && $hasText) {
            $flexDir = 'row';
            $flexWrap = 'nowrap';
        } elseif ($imagePosition === 'right' && $hasText) {
            $flexDir = 'row';
            $flexWrap = 'nowrap';
        }
        $sectionStyle = "display: flex; flex-direction: " . $flexDir . "; flex-wrap: " . $flexWrap . ";";
        if ($shouldShowNumber && $hasText) {
            $sectionStyle .= " gap: 2rem; align-items: flex-start;";
        } elseif ($imagePosition !== 'normal' && $hasText) {
            $sectionStyle .= " gap: 2rem; align-items: flex-start;";
        } else {
            $sectionStyle .= " gap: 1.5rem;";
        }
    ?>
        <?php if (!$hasText && $hasImage && !$shouldShowNumber): ?>
            <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars($block['title']); ?>" style="width: 100vw !important; max-width: 100vw !important; height: auto; display: block; margin-left: calc(-50vw + 50%); position: relative; margin-top: 3rem; margin-bottom: 3rem;">
        <?php else: ?>
        <section class="bg-white shadow-lg p-8 max-w-6xl mx-auto my-12" style="<?php echo $sectionStyle; ?>">
            <?php if ($shouldShowNumber && $numberPosition === 'left' && $hasText): ?>
                <div style="flex: 0 0 auto; width: 280px;">
                    <div style="font-size: 120px; font-weight: bold; color: #00811F; text-align: center; line-height: 1; display: flex; align-items: center; justify-content: center; min-height: 200px;">
                        <?php echo $numberBlockIndex + 1; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($imagePosition === 'left' && $hasImage && !$shouldShowNumber): ?>
                <div style="flex: 0 0 auto; max-width: 280px; width: 100%;">
                    <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars($block['title']); ?>" style="width: 100%; height: auto; border-radius: 0.5rem;">
                </div>
            <?php endif; ?>

            <?php if ($hasText): ?>
                <div style="<?php echo ($imagePosition !== 'normal' && $hasImage && !$shouldShowNumber) ? 'flex: 1 1 auto; min-width: 0;' : ''; ?>">
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

            <?php if ($imagePosition === 'right' && $hasImage && !$shouldShowNumber): ?>
                <div style="flex: 0 0 auto; max-width: 280px; width: 100%;">
                    <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars($block['title']); ?>" style="width: 100%; height: auto; border-radius: 0.5rem;">
                </div>
            <?php endif; ?>
            
            <?php if ($shouldShowNumber && $numberPosition === 'right' && $hasText): ?>
                <div style="flex: 0 0 auto; width: 280px;">
                    <div style="font-size: 120px; font-weight: bold; color: #00811F; text-align: center; line-height: 1; display: flex; align-items: center; justify-content: center; min-height: 200px;">
                        <?php echo $numberBlockIndex + 1; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($hasImage && $imagePosition === 'normal' && !$shouldShowNumber): ?>
                <div style="width: 100%;">
                    <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars($block['title']); ?>" style="width: 100%; height: auto; border-radius: 0.5rem;">
                </div>
            <?php endif; ?>

            <?php if ($isLastBlock): ?>
                <div class="w-full mt-6 pt-2 clear-both">
                    <a href="images/code-of-conduct.pdf" target="_blank" class="inline-block bg-[#00811F] text-white font-semibold px-6 py-3 rounded-lg shadow-md hover:bg-opacity-90 transition duration-200">
                        <i class="fa-solid fa-circle-info mr-2"></i> Meer info
                    </a>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        
        <?php
        // Verhoog tellers
        if ($shouldShowNumber) {
            $numberBlockIndex++;
        }
        $blockIndex++;
    endforeach; ?>
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
