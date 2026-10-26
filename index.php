<?php 
session_start();
require 'db.php';
require 'helpers.php';

$banner1 = 'images/banner_website_01.jpg';
$banner2 = 'images/banner_website_02.jpg';
$banner3 = null;
$banner4 = null;
$linkedinRssUrl = 'https://rss.app/feeds/dV7LODC8P6clPQvr.xml';
$festivalPopupImage = 'images/sociaal-ai-popup.jpg';
$festivalEvent = null;

function fetchHomepageLinkedInPosts(string $url, int $limit = 8): array
{
    if ($url === '') {
        return [];
    }

    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $content = @file_get_contents($url, false, $ctx);

    if (!$content) {
        return [];
    }

    $xml = @simplexml_load_string($content);
    if ($xml === false || !isset($xml->channel->item)) {
        return [];
    }

    $posts = [];
    foreach ($xml->channel->item as $item) {
        if (count($posts) >= $limit) {
            break;
        }

        $description = (string)$item->description;
        $imageUrl = '';
        if (preg_match('/<img[^>]+src="([^">]+)"/', $description, $matches)) {
            $imageUrl = $matches[1];
        } else {
            $namespaces = $item->getNamespaces(true);
            if (isset($namespaces['media'])) {
                $media = $item->children($namespaces['media']);
                if (isset($media->content)) {
                    foreach ($media->content as $mediaContent) {
                        if (isset($mediaContent->attributes()->url)) {
                            $imageUrl = (string)$mediaContent->attributes()->url;
                            break;
                        }
                    }
                }
            }
        }

        $timestamp = strtotime((string)$item->pubDate) ?: 0;
        $posts[] = [
            'url' => (string)$item->link,
            'text' => normalizeDisplayText(trim(strip_tags($description))),
            'title' => normalizeDisplayText((string)$item->title),
            'image' => $imageUrl,
            'date' => $timestamp > 0 ? date('d-m-Y', $timestamp) : '',
            'timestamp' => $timestamp,
        ];
    }

    usort($posts, function ($a, $b) {
        return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
    });

    return $posts;
}

$linkedinPosts = fetchHomepageLinkedInPosts($linkedinRssUrl, 8);

try {
    $b1 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner1'")->fetchColumn();
    $b2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner2'")->fetchColumn();
    $b3 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner3'")->fetchColumn();
    $b4 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner4'")->fetchColumn();
    if ($b1) $banner1 = $b1;
    if ($b2) $banner2 = $b2;
    if ($b3) $banner3 = $b3;
    if ($b4) $banner4 = $b4;

    $stmt = $pdo->prepare("SELECT * FROM pages WHERE page_key = 'index' ORDER BY (sort_order IS NULL OR sort_order = 0) ASC, sort_order ASC, created_at ASC, id ASC");
    $stmt->execute();
    $pageBlocks = $stmt->fetchAll();
    
    $welcomeBlock = null;
    $cardBlocks = [];
    $infoBlock = null;
    $contactBlock = null;
    $customBlocks = [];
    
    foreach ($pageBlocks as $block) {
        $metaArr = $block['meta'] ? json_decode($block['meta'], true) : [];
        $layout = $metaArr['layout'] ?? 'custom';
        
        if ($layout === 'welcome') {
            $welcomeBlock = $block;
        } elseif ($layout === 'card') {
            $cardBlocks[] = $block;
        } elseif ($layout === 'info') {
            $infoBlock = $block;
        } elseif ($layout === 'contact') {
            $contactBlock = $block;
        } else {
            $customBlocks[] = $block;
        }
    }
    
    $stmt = $pdo->prepare("SELECT * FROM events WHERE approval_status = 'approved' AND COALESCE(end_date, date) >= CURDATE() AND (show_on_homepage IS NULL OR show_on_homepage = 1) ORDER BY date, time LIMIT 8");
    $stmt->execute();
    $events = $stmt->fetchAll();
} catch (Exception $e) {
    $pageBlocks = [];
    $welcomeBlock = null;
    $cardBlocks = [];
    $infoBlock = null;
    $customBlocks = [];
    $events = [];
    $linkedinPosts = [];
}

try {
    $stmt = $pdo->prepare("SELECT id, title, date, end_date, time, time_end, location, event_summary, description FROM events WHERE approval_status = 'approved' AND title LIKE ? AND MONTH(date) = 11 AND DAY(date) = 24 AND (COALESCE(end_date, date) > CURDATE() OR (COALESCE(end_date, date) = CURDATE() AND (time_end IS NULL OR time_end >= CURTIME()))) ORDER BY date, id");
    $stmt->execute(['%Sociaal AI Lab Festival%']);
    foreach ($stmt->fetchAll() as $festivalCandidate) {
        $candidateTitle = strtolower(trim(preg_replace(
            '/\s+/',
            ' ',
            strip_tags(html_entity_decode((string)$festivalCandidate['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
        )));
        if ($candidateTitle === 'sociaal ai lab festival') {
            $festivalEvent = $festivalCandidate;
            $festivalTimezone = new DateTimeZone('Europe/Amsterdam');
            $festivalStartTime = trim((string)($festivalEvent['time'] ?? '')) ?: '00:00:00';
            $festivalStart = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $festivalEvent['date'] . ' ' . $festivalStartTime,
                $festivalTimezone
            );
            $festivalEnd = null;
            if (!empty($festivalEvent['time_end'])) {
                $festivalEndDate = !empty($festivalEvent['end_date']) ? $festivalEvent['end_date'] : $festivalEvent['date'];
                $festivalEnd = DateTimeImmutable::createFromFormat(
                    '!Y-m-d H:i:s',
                    $festivalEndDate . ' ' . $festivalEvent['time_end'],
                    $festivalTimezone
                );
            }
            if ($festivalStart) {
                $festivalEvent['start_timestamp'] = $festivalStart->getTimestamp();
                $festivalEvent['start_utc'] = $festivalStart->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
            }
            if (!$festivalEnd) {
                $festivalEndDate = !empty($festivalEvent['end_date']) ? $festivalEvent['end_date'] : $festivalEvent['date'];
                $festivalEnd = DateTimeImmutable::createFromFormat(
                    '!Y-m-d H:i:s',
                    $festivalEndDate . ' 23:59:59',
                    $festivalTimezone
                );
            }
            if ($festivalEnd) {
                $festivalEvent['end_timestamp'] = $festivalEnd->getTimestamp();
                $festivalEvent['end_utc'] = $festivalEnd->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
            }

            $festivalTeaser = trim(html_entity_decode(
                strip_tags((string)($festivalEvent['event_summary'] ?? '')),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ));
            if ($festivalTeaser === '') {
                $festivalTeaser = trim(html_entity_decode(
                    strip_tags((string)($festivalEvent['description'] ?? '')),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                ));
            }
            $festivalEvent['teaser'] = function_exists('mb_substr')
                ? mb_substr($festivalTeaser, 0, 180, 'UTF-8')
                : substr($festivalTeaser, 0, 180);
            $teaserLength = function_exists('mb_strlen')
                ? mb_strlen($festivalTeaser, 'UTF-8')
                : strlen($festivalTeaser);
            if ($teaserLength > 180) {
                $festivalEvent['teaser'] .= '…';
            }
            break;
        }
    }
} catch (Exception $e) {
    $festivalEvent = null;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
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

<main id="main-content" tabindex="-1">
    
    <?php if ($welcomeBlock): ?>
    <?php
    $welcomeMeta = $welcomeBlock['meta'] ? json_decode($welcomeBlock['meta'], true) : [];
    $welcomeGreenText = trim((string)($welcomeMeta['green_text'] ?? ($welcomeMeta['green_heading'] ?? '')));
    $welcomeGreenTextPosition = $welcomeMeta['green_text_position'] ?? 'above';
    if (!in_array($welcomeGreenTextPosition, ['above', 'below'], true)) $welcomeGreenTextPosition = 'above';
    ?>
    <section class="flex flex-col md:flex-row items-center gap-10 bg-white shadow-lg mt- p-8 max-w-6xl mx-auto my-12" tabindex="0">
        <div class="flex-1">
            <?php if ($welcomeGreenText !== '' && $welcomeGreenTextPosition === 'above'): ?>
                <div class="pink_text"><?php echo nl2br(htmlspecialchars($welcomeGreenText)); ?></div>
            <?php endif; ?>
            <h2 class="text-2xl md:text-3xl font-semibold mb-4 text-gray-900">
                <?php echo htmlspecialchars($welcomeBlock['title']); ?></h2>
            <div class="text-gray-700 leading-relaxed">
                <?php echo renderEditorBlock($welcomeBlock['body']); ?>
            </div>
            <?php if ($welcomeGreenText !== '' && $welcomeGreenTextPosition === 'below'): ?>
                <div class="pink_text"><?php echo nl2br(htmlspecialchars($welcomeGreenText)); ?></div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>
    
    <?php if (!empty($cardBlocks)): ?>
    <div class="flex flex-col flexrow justify-evenly w-full max-w-6xl mx-auto gap-8">
        <?php foreach ($cardBlocks as $block): ?>
        <?php
        $cardMeta = $block['meta'] ? json_decode($block['meta'], true) : [];
        $cardGreenText = trim((string)($cardMeta['green_text'] ?? ($cardMeta['green_heading'] ?? '')));
        $cardGreenTextPosition = $cardMeta['green_text_position'] ?? 'above';
        if (!in_array($cardGreenTextPosition, ['above', 'below'], true)) $cardGreenTextPosition = 'above';
        ?>
        <div class="space-y-6">
            <div class="bg-white shadow-lg pt-0 pb-6 mb-4 min-h-[220px] max-w-sm mx-auto" tabindex="0">
                <div class="flex flex-1 items-center justify-center">   
                    <?php if (!empty($block['image'])): ?>
                        <?php
                        $imagePath = trim((string)$block['image']);
                        // Bepaal het absolute pad naar de afbeelding
                        if (strpos($imagePath, 'images/') === 0 || strpos($imagePath, 'uploads/') === 0) {
                            // Is al een volledig pad
                            $imageSrc = $imagePath;
                        } elseif (preg_match('#^https?://#i', $imagePath)) {
                            // Externe URL
                            $imageSrc = $imagePath;
                        } else {
                            // Anders in uploads aanmen
                            $imageSrc = 'uploads/' . $imagePath;
                        }
                        ?>
                        <img src="<?php echo htmlspecialchars($imageSrc); ?>"
                             alt="<?php echo htmlspecialchars($block['title']); ?>"
                             class="card-icon w-24 md:w-32 lg:w-40 mx-auto">                    <?php endif; ?>
                </div>
                <?php if ($cardGreenText !== '' && $cardGreenTextPosition === 'above'): ?>
                    <div class="text-center mb-2"><div class="green-highlight"><?php echo nl2br(htmlspecialchars($cardGreenText)); ?></div></div>
                <?php endif; ?>
                <h3 class="text-xl text-center font-semibold"><?php echo htmlspecialchars($block['title']); ?></h3>
                <div class="text-center p-4">
                    <?php echo renderEditorBlock($block['body']); ?>
                </div>
                <?php if ($cardGreenText !== '' && $cardGreenTextPosition === 'below'): ?>
                    <div class="text-center mb-2"><div class="green-highlight"><?php echo nl2br(htmlspecialchars($cardGreenText)); ?></div></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($infoBlock): ?>
    <?php
    $infoMeta = $infoBlock['meta'] ? json_decode($infoBlock['meta'], true) : [];
    $infoGreenText = trim((string)($infoMeta['green_text'] ?? ($infoMeta['green_heading'] ?? '')));
    $infoGreenTextPosition = $infoMeta['green_text_position'] ?? 'above';
    if (!in_array($infoGreenTextPosition, ['above', 'below'], true)) $infoGreenTextPosition = 'above';
    ?>
    <section class="flex flex-col md:flex-row items-center gap-10 bg-white shadow-lg p-8 max-w-6xl mx-auto my-12" tabindex="0">
        <div class="flex-1">
            <?php if ($infoGreenText !== '' && $infoGreenTextPosition === 'above'): ?>
                <div class="green-highlight mb-3"><?php echo nl2br(htmlspecialchars($infoGreenText)); ?></div>
            <?php endif; ?>
            <?php if (!empty($infoBlock['title'])): ?>
                <h3 class="text-2xl font-semibold mb-4 text-gray-900"><?php echo htmlspecialchars($infoBlock['title']); ?></h3>
            <?php endif; ?>
            <div class="text-1xl md:text-1xl mb-4 text-gray-900">
                <?php echo renderEditorBlock($infoBlock['body']); ?>
            </div>
            <?php if ($infoGreenText !== '' && $infoGreenTextPosition === 'below'): ?>
                <div class="green-highlight mb-3"><?php echo nl2br(htmlspecialchars($infoGreenText)); ?></div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php
    foreach ($customBlocks as $block):
    ?>
        <section class="flex flex-col md:flex-row items-center gap-10 bg-white shadow-lg p-8 max-w-6xl mx-auto my-12" tabindex="0">
            <div class="flex-1">
                <h2 class="text-2xl md:text-3xl font-semibold mb-4 text-gray-900"><?php echo htmlspecialchars($block['title']); ?></h2>
                <div class="text-gray-700 leading-relaxed">
                    <?php echo renderEditorBlock($block['body']); ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
        
<?php if (!empty($events)): ?>
<section class="bg-white shadow-lg p-6 md:p-7 max-w-6xl mx-auto my-10" tabindex="0">
    <div class="flex items-center justify-between gap-4 mb-4 pb-4 border-b-2 border-gray-200">
        <h2 class="text-2xl md:text-3xl font-semibold text-gray-900">Aankomende events</h2>
        <?php if (count($events) > 1): ?>
        <div class="flex gap-2">
            <button type="button" class="homepage-carousel-arrow" data-carousel-prev="homepage-events" aria-label="Vorig event">
                <i class="fa-solid fa-chevron-left"></i>
            </button>
            <button type="button" class="homepage-carousel-arrow" data-carousel-next="homepage-events" aria-label="Volgend event">
                <i class="fa-solid fa-chevron-right"></i>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <div class="homepage-carousel" id="homepage-events">
<?php foreach ($events as $event): ?>
<?php
    $eventDateTs = strtotime((string)$event['date']);
    $eventDayMonth = $eventDateTs ? date('d.m', $eventDateTs) : '';
    $eventYear = $eventDateTs ? date('Y', $eventDateTs) : '';
    $eventImageName = trim((string)($event['image'] ?? ''));
    $hasValidImage = $eventImageName !== '' && file_exists(__DIR__ . '/uploads/' . $eventImageName);
?>
<section class="homepage-carousel-slide flex flex-col md:flex-row items-center gap-6 md:gap-8">
    <div class="flex-1">
        <span class="event-accent-badge">Evenement</span>
        <h2 class="text-2xl md:text-3xl font-semibold mb-4 text-gray-900"><?php echo htmlspecialchars($event['title']); ?></h2>
        <div class="space-y-3">
            <div class="flex items-center space-x-3">
                <i class="fa-regular fa-calendar text-[#00811F] ml-[2px] text-3xl"></i>
                <?php
                $dateDisplay = formatEventDateWithWeekdayDisplay($event['date']);
                $timeDisplay = !empty($event['time']) ? formatEventTimeDisplay($event['time']) : '';
                $endTimeDisplay = !empty($event['time_end']) ? formatEventTimeDisplay($event['time_end']) : '';
                ?>
                <p class="text-gray-700"><strong> Wanneer:</strong> <?php echo htmlspecialchars($dateDisplay); ?><?php if ($timeDisplay): ?> - <?php echo htmlspecialchars($timeDisplay); ?><?php if ($endTimeDisplay): ?> – <?php echo htmlspecialchars($endTimeDisplay); ?><?php endif; ?> uur<?php endif; ?></p>
            </div>
            <div class="flex items-center space-x-3">
                <i class="fa-solid fa-location-dot text-[#00811F] ml-1 text-3xl"></i>
                <?php $loc = $event['location'] ?: 'Rotterdam - Hillevliet 90'; ?>
                <p class="text-gray-700 ml-1"><strong>Waar:</strong> <a href="<?php echo googleMapsDirectionsUrl($loc); ?>" target="_blank" rel="noopener noreferrer" class="underline hover:text-[#00811F]"><?php echo htmlspecialchars($loc); ?></a></p>
            </div>
            <div class="flex mb-2 space-x-3">
                <i class="fa-solid fa-bullseye text-[#00811F] text-3xl"></i>
                <p class="text-gray-700 pb-1"><strong> Wat:</strong> <?php echo renderEditorBlock($event['description']); ?></p>
            </div>
            <?php if ($hasValidImage): ?>
            <div class="homepage-event-mobile-image">
                <div class="image-template-wrap">
                    <img src="uploads/<?php echo htmlspecialchars($eventImageName); ?>" alt="<?php echo htmlspecialchars(strip_tags((string)$event['title'])); ?>" class="image-template-photo">
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <?php $signupEmbed = trim((string)($event['signup_embed'] ?? '')); ?>
            <?php if ($signupEmbed !== ''): ?>
                <?php echo renderAanmelderEmbed($signupEmbed); ?>
            <?php endif; ?>
            <a href="event-detail.php?id=<?php echo (int)$event['id']; ?>" class="inline-flex items-center bg-[#00811F] text-white font-semibold px-6 py-3 rounded-md shadow hover:bg-[#006f19] transition">
                Meer info
            </a>
            <?php if (!empty($event['info_link'])): ?>
            <a href="<?php echo htmlspecialchars($event['info_link']); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center bg-[#00811F] text-white font-semibold px-6 py-3 rounded-md shadow hover:bg-[#006f19] transition">
                Externe info
            </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="flex-1<?php echo !$hasValidImage ? ' mobile-hide-no-image' : ''; ?>">
        <div class="image-template-wrap">
            <img src="<?php echo $hasValidImage ? 'uploads/' . htmlspecialchars($eventImageName) : 'images/event/Agenda_event_2_Studenten_en_bewoners_verkennen_de_sociale_invloed_van_AI.jpg'; ?>" alt="<?php echo htmlspecialchars(strip_tags((string)$event['title'])); ?>" class="image-template-photo">
            <!--
            <div class="image-template-badge">
                <span><?php echo htmlspecialchars($eventDayMonth); ?></span>
                <span><?php echo htmlspecialchars($eventYear); ?></span>
            </div>
            -->
            <!-- <span class="image-template-square image-template-square-left"></span>
            <span class="image-template-square image-template-square-right"></span> -->
        </div>
    </div>
</section>
<?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($linkedinPosts)): ?>
<section class="bg-white shadow-lg p-8 max-w-6xl mx-auto my-12" tabindex="0">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6 pb-6 border-b-2 border-gray-200">
        <div class="flex items-center gap-4">
            <i class="fa-brands fa-linkedin text-4xl text-[#0A66C2]"></i>
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Nieuws</h2>
                <p class="text-gray-600">Recente berichten van SociaalAI Lab Rotterdam</p>
            </div>
        </div>
        <div class="flex gap-2">
            <?php if (count($linkedinPosts) > 1): ?>
            <button type="button" class="homepage-carousel-arrow" data-news-prev aria-label="Vorige LinkedIn berichten">
                <i class="fa-solid fa-chevron-left"></i>
            </button>
            <button type="button" class="homepage-carousel-arrow" data-news-next aria-label="Volgende LinkedIn berichten">
                <i class="fa-solid fa-chevron-right"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="homepage-news-grid" id="homepage-linkedin-posts">
        <?php foreach ($linkedinPosts as $i => $post): ?>
        <article class="homepage-news-card" data-news-index="<?php echo (int)$i; ?>">
            <?php if (!empty($post['image'])): ?>
                <img src="<?php echo htmlspecialchars($post['image']); ?>" alt="LinkedIn bericht" class="homepage-news-card-img" loading="lazy">
            <?php endif; ?>
            <div class="homepage-news-card-content">
                <h3 class="homepage-news-card-title"><?php echo formatHomepageNewsText($post['title'] ?: 'SociaalAI Lab Update'); ?></h3>
                <?php if (!empty($post['date'])): ?>
                    <div class="homepage-news-card-meta"><i class="fa-regular fa-calendar mr-1"></i><?php echo htmlspecialchars($post['date']); ?></div>
                <?php endif; ?>
                <p class="homepage-news-card-summary">
                    <?php
                    $postText = (string)($post['text'] ?? '');
                    $trimmedText = editorPreviewText($postText, 180);
                    echo nl2br(formatHomepageNewsText($trimmedText));
                    ?>
                </p>
                <a href="<?php echo htmlspecialchars($post['url']); ?>" target="_blank" rel="noopener" class="homepage-news-card-link">
                    Lees meer <i class="fa-solid fa-arrow-right ml-2 text-xs"></i>
                </a>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($contactBlock): ?>
<a href="contact.php">
    <div class="flex items-center justify-center">
        <i class="text-[#cc0033] fa-3x fa-regular fa-envelope-open"></i>
    </div>
    <h2 class="text-center text-2xl md:text-xl font-bold text-white mb-2"><?php echo htmlspecialchars($contactBlock['title']); ?></h2>
    <div class="text-center text-white"><?php echo renderEditorBlock($contactBlock['body']); ?></div>
</a>
<?php endif; ?>

<?php if (!empty($_SESSION['can_access_admin'])): ?>
    <a href="admin.php" title="Voeg evenement toe" class="fixed bottom-6 right-6 bg-[#00811F] text-white rounded-full w-12 h-12 flex items-center justify-center text-3xl shadow-lg">+</a>
<?php endif; ?>

</main>

<?php if ($festivalEvent): ?>
<div
    class="festival-promo-modal"
    id="festival-promo-modal"
    data-start-timestamp="<?php echo (int)($festivalEvent['start_timestamp'] ?? 0); ?>"
    data-end-timestamp="<?php echo (int)($festivalEvent['end_timestamp'] ?? 0); ?>"
    data-start-utc="<?php echo htmlspecialchars($festivalEvent['start_utc'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
    data-end-utc="<?php echo htmlspecialchars($festivalEvent['end_utc'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
    data-event-date="<?php echo htmlspecialchars((string)$festivalEvent['date'], ENT_QUOTES, 'UTF-8'); ?>"
    data-title="<?php echo htmlspecialchars(strip_tags((string)$festivalEvent['title']), ENT_QUOTES, 'UTF-8'); ?>"
    data-location="<?php echo htmlspecialchars((string)($festivalEvent['location'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
    data-description="<?php echo htmlspecialchars($festivalEvent['teaser'], ENT_QUOTES, 'UTF-8'); ?>"
    hidden
>
    <section class="festival-promo-dialog" role="dialog" aria-modal="true" aria-labelledby="festival-promo-title">
        <button type="button" class="festival-promo-close" aria-label="Pop-up sluiten">&times;</button>
        <img class="festival-promo-image" src="<?php echo htmlspecialchars($festivalPopupImage); ?>" alt="Sociaal AI Lab Rotterdam Festival op dinsdag 24 november 2026, van 14:30 tot 19:30, Hillevliet 90 in Rotterdam.">
        <div class="festival-promo-content">
            <p class="festival-promo-date">
                📅 <?php echo htmlspecialchars(formatEventDateWithWeekdayDisplay($festivalEvent['date'])); ?>
            </p>
            <?php if (!empty($festivalEvent['time'])): ?>
                <p class="festival-promo-location">🕒 Inloop: <?php echo htmlspecialchars(formatEventTimeDisplay($festivalEvent['time'])); ?><?php if (!empty($festivalEvent['time_end'])): ?> – <?php echo htmlspecialchars(formatEventTimeDisplay($festivalEvent['time_end'])); ?> uur<?php else: ?> uur<?php endif; ?></p>
            <?php endif; ?>
            <?php if (!empty($festivalEvent['location'])): ?>
                <p class="festival-promo-location">📍 <?php echo htmlspecialchars((string)$festivalEvent['location']); ?></p>
            <?php endif; ?>
            <h2 id="festival-promo-title"><?php echo htmlspecialchars(strip_tags((string)$festivalEvent['title'])); ?></h2>
            <?php if ($festivalEvent['teaser'] !== ''): ?>
                <p class="festival-promo-teaser"><?php echo htmlspecialchars($festivalEvent['teaser']); ?></p>
            <?php endif; ?>
            <p class="festival-promo-countdown" id="festival-promo-countdown" aria-live="polite"></p>
            <a class="festival-promo-link" href="event-detail.php?id=<?php echo (int)$festivalEvent['id']; ?>">Bekijk het festival <span aria-hidden="true">→</span></a>
            <a class="festival-promo-calendar" href="#" id="festival-promo-calendar">+ Zet in agenda</a>
        </div>
    </section>
</div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>

<script>
    (function() {
        const toggle = document.getElementById('programma-toggle');
        const menu = document.getElementById('programma-menu');

        if (!toggle || !menu) return;

        function openMenu() {
            menu.classList.remove('hidden');
            toggle.setAttribute('aria-expanded', 'true');
            const first = menu.querySelector('[role="menuitem"]');
            if (first) first.focus();
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


    (function () {
        const modal = document.getElementById('festival-promo-modal');
        if (!modal) return;

        const closeButton = modal.querySelector('.festival-promo-close');
        const countdown = document.getElementById('festival-promo-countdown');
        const calendarLink = document.getElementById('festival-promo-calendar');
        const startTimestamp = Number(modal.dataset.startTimestamp) * 1000;
        const endTimestamp = Number(modal.dataset.endTimestamp) * 1000;
        const eventEndTimestamp = endTimestamp;
        const previousOverflow = document.body.style.overflow;
        const lastShownKey = 'sociaalAiFestivalPopupLastShown';
        const cooldownMs = 6 * 60 * 60 * 1000;
        let showTimer;
        let countdownInterval;
        let isVisible = false;

        function closeModal() {
            modal.hidden = true;
            document.body.style.overflow = previousOverflow;
            isVisible = false;
            document.removeEventListener('keydown', onKeyDown);
            window.clearInterval(countdownInterval);
        }

        function cancelPendingDisplay() {
            window.clearTimeout(showTimer);
            window.clearInterval(countdownInterval);
            if (isVisible) {
                modal.hidden = true;
                document.body.style.overflow = previousOverflow;
                isVisible = false;
            }
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') closeModal();
        }

        function updateCountdown() {
            const now = Date.now();
            if (now >= eventEndTimestamp) {
                modal.hidden = true;
                document.body.style.overflow = previousOverflow;
                window.clearInterval(countdownInterval);
                return false;
            }
            if (now >= startTimestamp) {
                countdown.textContent = 'Het festival is begonnen!';
                return true;
            }

            const remainingMs = startTimestamp - now;
            const remainingHours = Math.ceil(remainingMs / (60 * 60 * 1000));
            const todayInAmsterdam = new Intl.DateTimeFormat('en-CA', {
                timeZone: 'Europe/Amsterdam',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit'
            }).format(new Date(now));
            if (todayInAmsterdam === modal.dataset.eventDate) {
                countdown.textContent = 'Vandaag!';
            } else if (remainingMs < 24 * 60 * 60 * 1000) {
                countdown.textContent = 'Nog ' + remainingHours + ' uur';
            } else {
                const remainingDays = Math.ceil(remainingMs / (24 * 60 * 60 * 1000));
                countdown.textContent = 'Nog ' + remainingDays + (remainingDays === 1 ? ' dag' : ' dagen');
            }
            return true;
        }

        function escapeCalendarText(value) {
            return String(value || '')
                .replace(/\\/g, '\\\\')
                .replace(/\r?\n/g, '\\n')
                .replace(/,/g, '\\,')
                .replace(/;/g, '\\;');
        }

        const startUtc = modal.dataset.startUtc;
        const endUtc = modal.dataset.endUtc || (startUtc ? startUtc : '');
        if (startUtc && calendarLink) {
            const calendarDescription = escapeCalendarText(modal.dataset.description);
            const calendarData = [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//Sociaal AI Lab Rotterdam//Festival//NL',
                'BEGIN:VEVENT',
                'UID:sociaal-ai-lab-festival-' + startUtc + '@sociaalailab.nl',
                'DTSTAMP:' + new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, ''),
                'DTSTART:' + startUtc,
                'DTEND:' + endUtc,
                'SUMMARY:' + escapeCalendarText(modal.dataset.title),
                'LOCATION:' + escapeCalendarText(modal.dataset.location),
                'DESCRIPTION:' + calendarDescription,
                'END:VEVENT',
                'END:VCALENDAR'
            ].join('\r\n');
            const calendarBlob = new Blob([calendarData], { type: 'text/calendar;charset=utf-8' });
            calendarLink.href = URL.createObjectURL(calendarBlob);
            calendarLink.download = 'sociaal-ai-lab-festival.ics';
        } else if (calendarLink) {
            calendarLink.hidden = true;
        }

        if (!updateCountdown()) return;

        let lastShownAt = 0;
        try {
            lastShownAt = Number(window.localStorage.getItem(lastShownKey)) || 0;
        } catch (error) {
            // Continue without repeat suppression when browser storage is unavailable.
        }
        if (lastShownAt && Date.now() - lastShownAt < cooldownMs) return;

        function showModal() {
            if (!modal.isConnected || !updateCountdown()) return;

            try {
                window.localStorage.setItem(lastShownKey, String(Date.now()));
            } catch (error) {
                // The popup still works if browser storage is unavailable.
            }
            closeButton.addEventListener('click', closeModal);
            modal.addEventListener('click', function (event) {
                if (event.target === modal) closeModal();
            });
            document.addEventListener('keydown', onKeyDown);
            document.body.style.overflow = 'hidden';
            modal.hidden = false;
            isVisible = true;
            closeButton.focus();
            countdownInterval = window.setInterval(updateCountdown, 60 * 1000);
        }

        window.addEventListener('pagehide', cancelPendingDisplay, { once: true });
        showTimer = window.setTimeout(showModal, 2000);
    })();

    (function () {
        const eventCarousel = document.getElementById('homepage-events');
        const eventPrev = document.querySelector('[data-carousel-prev="homepage-events"]');
        const eventNext = document.querySelector('[data-carousel-next="homepage-events"]');

        if (eventCarousel && eventPrev && eventNext) {
            function updateEventButtons() {
                eventPrev.disabled = eventCarousel.scrollLeft <= 5;
                eventNext.disabled = eventCarousel.scrollLeft + eventCarousel.clientWidth >= eventCarousel.scrollWidth - 5;
            }

            function scrollEvents(direction) {
                eventCarousel.scrollBy({
                    left: direction * eventCarousel.clientWidth,
                    behavior: 'smooth'
                });
            }

            function scrollEventsAutomatically() {
                const isAtEnd = eventCarousel.scrollLeft + eventCarousel.clientWidth >= eventCarousel.scrollWidth - 5;
                eventCarousel.scrollTo({
                    left: isAtEnd ? 0 : eventCarousel.scrollLeft + eventCarousel.clientWidth,
                    behavior: 'smooth'
                });
            }

            eventPrev.addEventListener('click', function () {
                scrollEvents(-1);
            });

            eventNext.addEventListener('click', function () {
                scrollEvents(1);
            });

            eventCarousel.addEventListener('scroll', updateEventButtons);
            window.addEventListener('resize', updateEventButtons);
            updateEventButtons();
            setInterval(scrollEventsAutomatically, 10000);
        }

        const newsCards = Array.from(document.querySelectorAll('#homepage-linkedin-posts .homepage-news-card'));
        const newsPrev = document.querySelector('[data-news-prev]');
        const newsNext = document.querySelector('[data-news-next]');
        let newsPage = 0;

        function getNewsPerPage() {
            return window.matchMedia('(max-width: 900px)').matches ? 1 : 2;
        }

        function showNewsPage() {
            if (!newsCards.length) {
                return;
            }

            const perPage = getNewsPerPage();
            const maxPage = Math.max(0, Math.ceil(newsCards.length / perPage) - 1);
            newsPage = Math.max(0, Math.min(newsPage, maxPage));

            newsCards.forEach(function (card, index) {
                const isVisible = index >= newsPage * perPage && index < (newsPage + 1) * perPage;
                card.style.display = isVisible ? '' : 'none';
            });

            if (newsPrev) {
                newsPrev.disabled = newsPage === 0;
            }
            if (newsNext) {
                newsNext.disabled = newsPage >= maxPage;
            }
        }

        if (newsCards.length) {
            if (newsPrev) {
                newsPrev.addEventListener('click', function () {
                    newsPage -= 1;
                    showNewsPage();
                });
            }

            if (newsNext) {
                newsNext.addEventListener('click', function () {
                    newsPage += 1;
                    showNewsPage();
                });
            }

            window.addEventListener('resize', showNewsPage);
            showNewsPage();
        }
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
