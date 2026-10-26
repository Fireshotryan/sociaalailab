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
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE page_key = 'contact' ORDER BY (sort_order IS NULL OR sort_order = 0) ASC, sort_order ASC, created_at ASC, id ASC");
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
    <link rel="modulepreload" as="script" href="build/assets/app-CAiCLEjY.js"><link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>"><script type="module" src="build/assets/app-CAiCLEjY.js"></script>    <title>contact</title>
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



<main id="main-content" tabindex="-1">
    <?php
    foreach ($pageBlocks as $block):
        $metaArr = $block['meta'] ? json_decode($block['meta'], true) : [];
        $hasImage = !empty($block['image']);
        $hasText = !empty($block['title']) || !empty($block['body']) || !empty($metaArr['address']) || !empty($metaArr['email']);
        $imagePosition = $metaArr['image_position'] ?? 'normal';
        if (!in_array($imagePosition, ['normal', 'left', 'right'], true)) $imagePosition = 'normal';
        $imageSrc = '';
        if ($hasImage) {
            $imagePath = trim((string)$block['image']);
            if (strpos($imagePath, 'images/') === 0 || strpos($imagePath, 'uploads/') === 0) {
                $imageSrc = $imagePath;
            } elseif (strpos($imagePath, '../') === 0 || preg_match('#^https?://#i', $imagePath)) {
                $imageSrc = $imagePath;
            } else {
                $imageSrc = 'uploads/' . $imagePath;
            }
        }
        
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
                        <img src="<?php echo htmlspecialchars($imageSrc); ?>" 
                             alt="<?php echo htmlspecialchars($block['title']); ?>" 
                             class="w-full h-64 object-cover rounded shadow-md">
                    </div>
                <?php elseif ($hasImage && !$hasText): ?>
                    <div style="width: 100%;">
                        <img src="<?php echo htmlspecialchars($imageSrc); ?>" 
                             alt="<?php echo htmlspecialchars($block['title']); ?>" 
                             class="w-full h-auto object-cover rounded shadow-md">
                    </div>
                <?php endif; ?>

                <?php if ($hasText): ?>
                <div class="mb-6" style="<?php echo ($hasImage && $imagePosition !== 'normal') ? 'flex: 1; padding: 0 1.5rem;' : ''; ?>">
                    <?php if (!empty($block['title'])): ?>
                        <h3 class="font-bold text-2xl mb-2"><?php echo renderEditorInline($block['title']); ?></h3>
                    <?php endif; ?>
                    <?php if (!empty($block['body'])): ?>
                        <div class="text-gray-700 leading-relaxed"><?php echo renderEditorBlock($block['body']); ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasImage && $imagePosition === 'right' && $hasText): ?>
                    <div style="flex: 0 0 50%; min-width: 0; max-width: 600px;">
                        <img src="<?php echo htmlspecialchars($imageSrc); ?>" 
                             alt="<?php echo htmlspecialchars($block['title']); ?>" 
                             class="w-full h-64 object-cover rounded shadow-md">
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($hasImage && $imagePosition === 'normal' && $hasText): ?>
                <div style="max-width: 600px;">
                    <img src="<?php echo htmlspecialchars($imageSrc); ?>" 
                         alt="<?php echo htmlspecialchars($block['title']); ?>" 
                         class="w-full h-auto object-cover rounded shadow-md">
                </div>
            <?php endif; ?>
            
            <?php if (!empty($metaArr['map_embed'])): ?>
                <div class="mt-4"><?php echo $metaArr['map_embed']; ?></div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <section class="bg-white shadow-lg p-8 max-w-6xl mx-auto my-12">
        <div class="container sociaalai-contact-wrap">
            <?php
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/');
            $nextUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/bedankt.php';
            ?>
            <h1 class="font-bold text-2xl mb-2">Contact</h1>
            <p class="sociaalai-contact-intro">Heb je een vraag of idee? Stuur ons dan een bericht, wij proberen zo spoedig te reageren.</p>
            <form class="sociaalai-contact-form" target="_blank" action="https://formsubmit.co/info@sociaalailab.nl" method="POST">
                <input type="hidden" name="_captcha" value="false">
                <input type="hidden" name="_next" value="<?php echo htmlspecialchars($nextUrl); ?>">
                <div class="form-group mb-4">
                    <div class="form-row grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="col">
                            <label for="contact-name" class="sociaalai-label">Naam</label>
                            <input id="contact-name" type="text" name="name" class="form-control sociaalai-input w-full border border-gray-300 rounded px-3 py-2" placeholder="Volledige naam" required>
                        </div>
                        <div class="col">
                            <label for="contact-email" class="sociaalai-label">E-mailadres</label>
                            <input id="contact-email" type="email" name="email" class="form-control sociaalai-input w-full border border-gray-300 rounded px-3 py-2" placeholder="jij@voorbeeld.nl" required>
                        </div>
                    </div>
                </div>
                <div class="form-group mb-4">
                    <label for="contact-message" class="sociaalai-label">Bericht</label>
                    <textarea id="contact-message" placeholder="Typ hier je bericht" class="form-control sociaalai-input sociaalai-textarea w-full border border-gray-300 rounded px-3 py-2" name="message" rows="6" required></textarea>
                </div>
                <button type="submit" class="sociaalai-submit-btn">Versturen</button>
            </form>
        </div>
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