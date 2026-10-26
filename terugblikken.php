<?php 
session_start();
require 'db.php';
require 'helpers.php';


// Tel het totaal aantal aankomende evenementen
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE approval_status = 'approved' AND COALESCE(end_date, date) >= CURDATE()");
$stmt->execute();
$totalUpcoming = $stmt->fetchColumn();

// Tel het totaal aantal terugblikken (afgelopen evenementen)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE approval_status = 'approved' AND COALESCE(end_date, date) < CURDATE()");
$stmt->execute();
$totalPast = $stmt->fetchColumn();


$itemsPerPage = 6;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}
$totalPages = max(1, (int)ceil($totalPast / $itemsPerPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $itemsPerPage;

// Sorteervolgorde ophalen
$sortOrder = isset($_GET['sort']) && $_GET['sort'] === 'asc' ? 'ASC' : 'DESC';


$banner1 = 'images/banner_website_01.jpg';
$banner2 = 'images/banner_website_02.jpg';
$banner3 = null;
$banner4 = null;

try {
    $banner1 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner1'")->fetchColumn() ?: $banner1;
    $banner2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner2'")->fetchColumn() ?: $banner2;
    $banner3 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner3'")->fetchColumn() ?: null;
    $banner4 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner4'")->fetchColumn() ?: null;

    $stmt = $pdo->prepare("SELECT * FROM pages WHERE page_key = 'terugblikken' ORDER BY (sort_order IS NULL OR sort_order = 0) ASC, sort_order ASC, created_at ASC, id ASC");
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

    $stmt = $pdo->prepare("SELECT * FROM events WHERE approval_status = 'approved' AND COALESCE(end_date, date) < CURDATE() ORDER BY date $sortOrder, time $sortOrder LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $events = $stmt->fetchAll();
} catch (Exception $e) {
    $pageBlocks = [];
    $events = [];
}
?>

<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="modulepreload" as="script" href="build/assets/app-CAiCLEjY.js"><link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>"><script type="module" src="build/assets/app-CAiCLEjY.js"></script>    <title>Terugblikken</title>
    <meta name="description" content="SociaalAI helpt inwoners sterker te staan in een steeds digitalere wereld. We doen dit door Rotterdammers actief mee te laten denken, praten en beslissen over kunstmatige intelligentie.">
    <link rel="icon" type="image/png" href="images/Pixels_icon.png">
    <link rel="stylesheet" href="ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body class="bg-gradient-to-br from-[#00811F] to-[#b9eb34]">

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
$navPrefix = '';
include __DIR__ . '/navbar.php';
?>

<main id="main-content" tabindex="-1" class="pt-8 sm:pt-12">
    <div id="agenda-terugblik-switch" class="mobile flex items-center justify-center gap-1" style="scroll-margin-top: 110px;">
        <div class="agenda bg-white p-6 shadow-lg max-w-xl mt-6 w-full border-r text-center">
            <a href="agenda.php#agenda-terugblik-switch">
                <h1 class="text-2xl text-[#000000] font-semibold">
                    Agenda (<?php echo $totalUpcoming; ?>)
                </h1>
            </a>
        </div>
        <div class="terugblik bg-white p-6 max-w-xl mt-6 w-full text-center border-r border-gray-500">
            <a href="terugblikken.php#agenda-terugblik-switch">
                <h1 class="text-2xl text-green-800 font-semibold">
                    Terugblik
                </h1>
            </a>
        </div>
    </div>

    <?php
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE page_key = 'terugblikken' ORDER BY (sort_order IS NULL OR sort_order = 0) ASC, sort_order ASC, created_at ASC, id ASC");
    $stmt->execute();
    $pageBlocks = $stmt->fetchAll();

    // Show 'Meer info' and partner for the most recent terugblik block
    if (!empty($pageBlocks)) {
        $mostRecent = $pageBlocks[0];
        $metaArr = $mostRecent['meta'] ? json_decode($mostRecent['meta'], true) : [];
        $infoLink = $mostRecent['info_link'] ?? ($metaArr['info_link'] ?? '');
        $partner = $metaArr['partner'] ?? '';
        ?>
            <?php if ($infoLink): ?>
                <a href="<?php echo htmlspecialchars($infoLink); ?>" target="_blank" class="inline-block bg-[#00811F] text-white px-6 py-2 rounded-lg font-semibold hover:bg-[#005c16] transition">Meer info</a>
            <?php endif; ?>
            <?php if ($partner): ?>
                <div class="mt-4 text-gray-700"><strong>Partner:</strong> <?php echo htmlspecialchars($partner); ?></div>
            <?php endif; ?>
        </section>
        <?php
    }

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
        <section class="bg-white shadow-lg px-3 sm:px-8 py-6 sm:py-8 max-w-6xl mx-auto mt-8 sm:mt-12 mb-6 sm:mb-12" style="<?php echo $sectionStyle; ?>">
            <?php if ($hasImage && $imagePosition === 'left'): ?>
                <?php
                $imageStyle = 'flex: 0 0 auto; max-width: 280px; width: 100%;';
                if (!$hasText) {
                    $imageStyle = 'width: 100%;';
                }
                ?>
                <div style="<?php echo $imageStyle; ?>">
                    <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars($block['title']); ?>" style="width: 100%; height: auto; object-fit: contain; border-radius: 0.5rem;">
                </div>
            <?php endif; ?>
            <?php if ($hasText): ?>
                <div style="<?php echo ($imagePosition !== 'normal' && $hasImage) ? 'flex: 1 1 auto; min-width: 0;' : ''; ?>">
                    <?php if ($greenText !== '' && $greenTextPosition === 'above'): ?>
                        <div class="green-highlight mb-3"><?php echo nl2br(htmlspecialchars($greenText)); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($block['title'])): ?>
                        <h3 class="text-xl sm:text-2xl font-semibold mb-4 text-gray-900"><?php echo renderEditorInline($block['title']); ?></h3>
                    <?php endif; ?>
                    <?php if (!empty($block['body'])): ?>
                        <div class="text-sm sm:text-base text-gray-700 leading-relaxed"><?php echo renderEditorBlock($block['body']); ?></div>
                    <?php endif; ?>
                <?php if (!empty($metaArr['date']) || !empty($metaArr['time'])): ?>
                    <div class="text-gray-600 text-xs sm:text-sm mt-2 flex flex-col sm:flex-row gap-2 sm:gap-4">
                        <?php if (!empty($metaArr['date'])): ?>
                            <span><strong>Datum:</strong> <?php echo htmlspecialchars(formatEventDateDisplay($metaArr['date'])); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($metaArr['time'])): ?>
                            <span><strong>Tijd:</strong> <?php echo htmlspecialchars(formatEventTimeDisplay($metaArr['time'])); ?> uur</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($greenText !== '' && $greenTextPosition === 'below'): ?>
                    <div class="green-highlight mb-3"><?php echo nl2br(htmlspecialchars($greenText)); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($hasImage && $imagePosition === 'right'): ?>
                <?php
                $imageStyle = 'flex: 0 0 auto; max-width: 280px; width: 100%;';
                if (!$hasText) {
                    $imageStyle = 'width: 100%;';
                }
                ?>
                <div style="<?php echo $imageStyle; ?>">
                    <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars($block['title']); ?>" style="width: 100%; height: auto; object-fit: contain; border-radius: 0.5rem;">
                </div>
            <?php endif; ?>
            <?php if ($hasImage && $imagePosition === 'normal'): ?>
                <div style="width: 100%; max-width: 600px;">
                    <img src="uploads/<?php echo htmlspecialchars($block['image']); ?>" alt="<?php echo htmlspecialchars($block['title']); ?>" style="width: 100%; height: auto; object-fit: contain; border-radius: 0.5rem;">
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>


    <div class="max-w-6xl mx-auto mb-4 flex justify-end px-4 sm:px-0">
        <div class="bg-white rounded-lg shadow px-4 py-2">
            <form method="get" action="terugblikken.php#agenda-terugblik-switch" class="flex flex-row gap-2 items-center m-0">
                <input type="hidden" name="page" value="<?php echo $currentPage; ?>">
                <label for="sort" class="font-semibold">Sorteer:</label>
                <select name="sort" id="sort" class="border rounded px-2 py-1" onchange="this.form.submit()">
                    <option value="desc" <?php if ($sortOrder === 'DESC') echo 'selected'; ?>>Nieuw naar oud</option>
                    <option value="asc" <?php if ($sortOrder === 'ASC') echo 'selected'; ?>>Oud naar nieuw</option>
                </select>
            </form>
        </div>
    </div>

    <?php if (empty($events)): ?>
        <section class="bg-white shadow-lg p-8 max-w-6xl mx-auto my-12 text-center">
            <p class="text-gray-700">Er zijn nog geen voorbije evenementen.</p>
        </section>
    <?php else: ?>
        <?php foreach ($events as $event): ?>
    <?php
    $dateDisplay = formatEventDateWithWeekdayDisplay($event['date']);
    $timeDisplay = $event['time'] ? formatEventTimeDisplay($event['time']) : '';
    $dateTs = strtotime((string)$event['date']);
    $dayMonth = $dateTs ? date('d.m', $dateTs) : $dateDisplay;
    $year = $dateTs ? date('Y', $dateTs) : '';
    $loc = $event['location'] ?: 'Rotterdam - Hillevliet 90';
    $mapsLocationUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode((string)$loc);
    $eventImageName = trim((string)($event['image'] ?? ''));
    $hasValidImage = $eventImageName !== '' && file_exists(__DIR__ . '/uploads/' . $eventImageName);
    ?>
    <section class="flex flex-col md:flex-row items-center gap-10 bg-white shadow-lg p-8 max-w-6xl mx-auto my-12">
        <div class="flex-1">
            <span class="inline-block text-white text-sm font-medium px-4 py-1 mb-4" style="background-color:#d12254;">Evenement</span>
            <h2 class="text-2xl md:text-3xl font-semibold mb-4 text-gray-900"><?php echo renderEditorInline($event['title']); ?></h2>
            <div class="space-y-4">
                <div class="flex items-center space-x-3">
                    <i class="fa-regular fa-calendar text-[#00811F] ml-[2px]  text-3xl"></i>
                    <p class="text-gray-700"><strong> Wanneer:</strong> <?php echo htmlspecialchars($dateDisplay); ?> <?php echo $timeDisplay ? '- ' . htmlspecialchars($timeDisplay) . ' uur' : ''; ?></p>
                </div>
                <div class="flex items-center space-x-3">
                    <i class="fa-solid fa-location-dot text-[#00811F] ml-1 text-3xl"></i>
                    <p class="text-gray-700 ml-1 "><strong>Waar:</strong> <a href="<?php echo htmlspecialchars($mapsLocationUrl); ?>" target="_blank" rel="noopener noreferrer" class="underline hover:text-[#00811F]"><?php echo htmlspecialchars($loc); ?></a></p>
                </div>
                <div class="flex mb-2 space-x-3">
                    <i class="fa-solid fa-bullseye text-[#00811F] text-3xl"></i>
                    <div class="text-gray-700 pb-1"><strong>Wat:</strong><div class="mt-1"><?php echo renderEditorBlock($event['description']); ?></div></div>
                </div>
                <?php if ($hasValidImage): ?>
                <div class="mobile-image mb-4">
                    <img src="uploads/<?php echo htmlspecialchars($eventImageName); ?>" alt="<?php echo htmlspecialchars(strip_tags((string)$event['title'])); ?>" class="image-template-photo" style="object-fit: contain;">
                </div>
                <?php endif; ?>
                <div class="mb-4 flex flex-wrap gap-3">
                    <a href="event-detail.php?id=<?php echo (int)$event['id']; ?>" class="inline-block bg-[#00811F] text-white px-6 py-2 rounded-lg font-semibold hover:bg-[#005c16] transition">Meer info</a>
                    <?php if (!empty($event['info_link'])): ?>
                        <a href="<?php echo htmlspecialchars($event['info_link']); ?>" target="_blank" rel="noopener noreferrer" class="inline-block bg-[#ce0245] text-white px-6 py-2 rounded-lg font-semibold hover:opacity-90 transition">Externe info</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($hasValidImage): ?>
        <div class="flex-1 desktop-image">
            <div class="image-template-wrap">
                <img src="uploads/<?php echo htmlspecialchars($eventImageName); ?>" alt="<?php echo htmlspecialchars(strip_tags((string)$event['title'])); ?>" class="image-template-photo" style="object-fit: contain;">
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

        <?php if ($totalPages > 1): ?>
            <nav class="max-w-6xl mx-auto my-10 flex items-center justify-center gap-2" aria-label="Paginering terugblikken">
                <?php if ($currentPage > 1): ?>
                    <a href="?page=<?php echo $currentPage - 1; ?>#agenda-terugblik-switch" class="px-4 py-2 bg-white border border-gray-300 rounded hover:bg-gray-100 transition">Vorige</a>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p === $currentPage): ?>
                        <span class="px-4 py-2 rounded bg-[#00811F] text-white font-semibold"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $p; ?>#agenda-terugblik-switch" class="px-4 py-2 bg-white border border-gray-300 rounded hover:bg-gray-100 transition"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($currentPage < $totalPages): ?>
                    <a href="?page=<?php echo $currentPage + 1; ?>#agenda-terugblik-switch" class="px-4 py-2 bg-white border border-gray-300 rounded hover:bg-gray-100 transition">Volgende</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>

<script>
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
