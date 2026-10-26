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
} catch (Exception $e) {
    // Gebruik fallback banners.
}

$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$event = null;

if ($eventId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ? AND approval_status = ? LIMIT 1');
    $stmt->execute([$eventId, 'approved']);
    $event = $stmt->fetch();
}

// If event not found or not approved, redirect to events page
if (!$event) {
    header('Location: event.php');
    exit;
}

$isPastEvent = false;
$eventSummary = '';
$galleryImages = [];

if ($event) {
    $compareDate = !empty($event['end_date']) ? $event['end_date'] : $event['date'];
    $today = new DateTime('today');
    $end = DateTime::createFromFormat('Y-m-d', (string)$compareDate);
    if ($end instanceof DateTime) {
        $end->setTime(0, 0, 0);
        $isPastEvent = $end < $today;
    }

    if (!empty($event['event_gallery'])) {
        $decodedGallery = json_decode((string)$event['event_gallery'], true);
        if (is_array($decodedGallery)) {
            foreach ($decodedGallery as $fileName) {
                $fileName = trim((string)$fileName);
                if ($fileName !== '') {
                    $galleryImages[] = $fileName;
                }
            }
        }
    }

    $manualSummary = trim((string)($event['event_summary'] ?? ''));
    if ($manualSummary !== '') {
        $eventSummary = $manualSummary;
    }

}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="modulepreload" as="script" href="build/assets/app-CAiCLEjY.js"><link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>"><script type="module" src="build/assets/app-CAiCLEjY.js"></script>
    <title>Evenement detail</title>
    <meta name="description" content="Meer informatie over het evenement van SociaalAI Lab.">
    <link rel="icon" type="image/png" href="images/Pixels_icon.png">
    <link rel="stylesheet" href="ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .event-gallery-trigger {
            border: 0;
            background: transparent;
            padding: 0;
            margin: 0;
            width: 100%;
            cursor: zoom-in;
        }

        .event-main-image {
            max-width: 400px;
            max-height: 300px;
            width: auto;
            height: auto;
            object-fit: contain;
            display: block;
            margin-left: 0;
            border: none;
            box-shadow: none;
            border-radius: 0;
        }

        .event-gallery-image {
            width: 100%;
            height: auto;
            object-fit: contain;
            display: block;
        }

        .event-lightbox {
            position: fixed;
            inset: 0;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.86);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .event-lightbox.is-open {
            display: flex;
        }

        .event-lightbox-image {
            max-width: min(92vw, 1200px);
            max-height: 88vh;
            width: auto;
            height: auto;
            object-fit: contain;
        }

        .event-lightbox-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            border: 0;
            background: rgba(255, 255, 255, 0.18);
            color: #fff;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 9999px;
            font-size: 1.5rem;
            line-height: 1;
            cursor: pointer;
        }

        .event-lightbox-close:hover {
            background: rgba(255, 255, 255, 0.32);
        }

        .event-lightbox-open {
            overflow: hidden;
        }

        .event-description p {
            margin-bottom: 1em;
        }
        .event-description p:last-child {
            margin-bottom: 0;
        }
        .event-description ul,
        .event-description ol {
            margin-bottom: 1em;
            padding-left: 1.5em;
        }
        .event-description li {
            margin-bottom: 0.25em;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-[#00811F] to-[#b9eb34]">

<div class="banner-wrapper">
    <div class="banner banner-1 active">
        <img src="<?php echo htmlspecialchars($banner1); ?>" alt="Banner 1">
    </div>
    <div class="banner banner-2">
        <img src="<?php echo htmlspecialchars($banner2); ?>" alt="Banner 2">
    </div>
    <?php if ($banner3): ?>
    <div class="banner banner-3">
        <img src="<?php echo htmlspecialchars($banner3); ?>" alt="Banner 3">
    </div>
    <?php endif; ?>
    <?php if ($banner4): ?>
    <div class="banner banner-4">
        <img src="<?php echo htmlspecialchars($banner4); ?>" alt="Banner 4">
    </div>
    <?php endif; ?>
</div>

<?php
$navPrefix = '';
include __DIR__ . '/navbar.php';
?>

<main>
    <section class="bg-white shadow-lg p-8 max-w-4xl mx-auto my-12" tabindex="0">
        <?php if (!$event): ?>
            <h1 class="text-2xl font-semibold mb-3 text-gray-900">Evenement niet gevonden</h1>
            <p class="text-gray-700 mb-6">Dit evenement bestaat niet meer of de link is onjuist.</p>
            <a href="agenda.php#agenda-terugblik-switch" class="inline-flex items-center bg-[#00811F] text-white font-semibold px-6 py-3 rounded-md shadow hover:bg-[#006f19] transition">Terug naar agenda</a>
        <?php else: ?>
            <?php
                $dateDisplay = formatEventDateWithWeekdayDisplay($event['date']);
                $endDateDisplay = !empty($event['end_date']) ? formatEventDateDisplay($event['end_date']) : null;
                $timeDisplay = !empty($event['time']) ? formatEventTimeDisplay($event['time']) : '';
                $timeEndDisplay = !empty($event['time_end']) ? formatEventTimeDisplay($event['time_end']) : '';
                $location = !empty($event['location']) ? $event['location'] : 'Rotterdam - Hillevliet 90';
            ?>

            <span class="inline-block text-white text-sm font-medium px-4 py-1 mb-4" style="background-color:#ce0245;">Evenement info</span>
            
            <div class="space-y-6 mb-8">
                <div>
                    <h1 class="text-3xl md:text-4xl font-semibold mb-4 text-gray-900"><?php echo renderEditorInline($event['title']); ?></h1>

                    <div class="space-y-3">
                        <p class="text-gray-700"><strong><i class="fa fa-calendar-alt" aria-hidden="true"></i> Wanneer:</strong> <?php echo htmlspecialchars($dateDisplay); ?><?php if ($endDateDisplay) { echo ' t/m ' . htmlspecialchars($endDateDisplay); } ?></p>
                        <?php if ($timeDisplay || $timeEndDisplay): ?>
                            <p class="text-gray-700"><strong><i class="fa fa-clock" aria-hidden="true"></i> Tijd:</strong> <?php echo htmlspecialchars($timeDisplay); ?><?php if ($timeEndDisplay) { echo ' - ' . htmlspecialchars($timeEndDisplay); } ?></p>
                        <?php endif; ?>
                        <p class="text-gray-700"><strong><i class="fa fa-map-marker-alt" aria-hidden="true"></i> Locatie:</strong> <?php echo htmlspecialchars($location); ?></p>
                    </div>
                </div>

                <?php if (!empty($event['image'])): ?>
                    <div class="flex-shrink-0" style="width: 100%; max-width: 1500px; text-align: left;">
                        <img src="uploads/<?php echo htmlspecialchars($event['image']); ?>"
                             class="event-main-image"
                             alt="Event afbeelding">
                    </div>
                <?php endif; ?>
            </div>
                <div class="bg-gray-50 border border-gray-200 rounded-md p-6 mb-6">
                <h2 class="text-2xl font-semibold mb-3 text-gray-900"><?php echo $isPastEvent ? 'Samenvatting' : 'Meer info'; ?></h2>
                <div class="text-gray-700 leading-relaxed event-description">
                <?php if ($isPastEvent): ?>
                    <?php if ($eventSummary !== ''): ?>
                        <?php echo renderEditorBlock($eventSummary); ?>
                    <?php else: ?>
                        <p>Samenvatting volgt binnenkort.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (!empty($event['meer_info'])): ?>
                        <?php echo renderEditorBlock($event['meer_info']); ?>
                    <?php else: ?>
                        <p>Meer informatie over dit evenement volgt binnenkort.</p>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($galleryImages)): ?>
                <div class="bg-gray-50 border border-gray-200 rounded-md p-6 mb-6">
                    <h2 class="text-2xl font-semibold mb-4 text-gray-900">Foto's tijdens dit event</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                        <?php foreach ($galleryImages as $galleryImage): ?>
                            <button
                                type="button"
                                class="event-gallery-trigger"
                                data-gallery-image
                                data-full-src="uploads/<?php echo htmlspecialchars($galleryImage); ?>"
                                aria-label="Open foto in groot formaat"
                            >
                                <img src="uploads/<?php echo htmlspecialchars($galleryImage); ?>" alt="Foto van het event <?php echo htmlspecialchars((string)$event['title']); ?>" class="event-gallery-image">
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="flex flex-wrap gap-3">
                <a href="agenda.php#agenda-terugblik-switch" class="inline-flex items-center bg-[#00811F] text-white font-semibold px-6 py-3 rounded-md shadow hover:bg-[#006f19] transition">Terug naar agenda</a>
                <?php
                // Inschrijfknop tonen indien signup_embed of show_signup_button aanwezig is
                $signupEmbed = trim((string)($event['signup_embed'] ?? ''));
                if ($signupEmbed !== '') {
                    echo renderAanmelderEmbed($signupEmbed);
                }
                ?>
                <?php if (!empty($event['info_link'])): ?>
                    <a href="<?php echo htmlspecialchars($event['info_link']); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center bg-[#ce0245] text-white font-semibold px-6 py-3 rounded-md shadow hover:opacity-90 transition">Externe info</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<div id="event-lightbox" class="event-lightbox" aria-hidden="true">
    <button type="button" id="event-lightbox-close" class="event-lightbox-close" aria-label="Sluit foto">&times;</button>
    <img id="event-lightbox-image" class="event-lightbox-image" src="" alt="Vergrote eventfoto">
</div>

<?php include __DIR__ . '/footer.php'; ?>

<script>
    const banners = document.querySelectorAll('.banner');
    let current = 0;

    setInterval(() => {
      banners[current].classList.remove('active');
      current = (current + 1) % banners.length;
      banners[current].classList.add('active');
    }, 10000);

    (function() {
        const lightbox = document.getElementById('event-lightbox');
        const lightboxImage = document.getElementById('event-lightbox-image');
        const closeBtn = document.getElementById('event-lightbox-close');
        const triggers = document.querySelectorAll('[data-gallery-image]');

        if (!lightbox || !lightboxImage || !closeBtn || !triggers.length) return;

        function closeLightbox() {
            lightbox.classList.remove('is-open');
            lightbox.setAttribute('aria-hidden', 'true');
            lightboxImage.setAttribute('src', '');
                                                    document.body.classList.remove('event-lightbox-open');
        }

        triggers.forEach((trigger) => {
            trigger.addEventListener('click', function() {
                const src = this.getAttribute('data-full-src') || '';
                if (!src) return;
                lightboxImage.setAttribute('src', src);
                lightbox.classList.add('is-open');
                lightbox.setAttribute('aria-hidden', 'false');
                document.body.classList.add('event-lightbox-open');
            });
        });

        closeBtn.addEventListener('click', closeLightbox);
        lightbox.addEventListener('click', function(event) {
            if (event.target === lightbox) {
                closeLightbox();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && lightbox.classList.contains('is-open')) {
                closeLightbox();
            }
        });
    })();
</script>

</body>
</html>
