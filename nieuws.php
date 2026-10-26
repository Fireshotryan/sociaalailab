<?php 
session_start();
require 'db.php';
require 'helpers.php';

// Get banners from settings
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
}

// LinkedIn profile URL for SociaalAI Lab
$linkedinProfileUrl = 'https://www.linkedin.com/company/sociaal-ai-lab-rotterdam/';
$linkedinRssUrl = 'https://rss.app/feeds/dV7LODC8P6clPQvr.xml'; 

// Optional LinkedIn API configuration via environment variables.
// LINKEDIN_ORG_ID example: 12345678
// LINKEDIN_ACCESS_TOKEN requires access to organization social content.
$linkedinOrgId = getenv('LINKEDIN_ORG_ID') ?: '';
$linkedinAccessToken = getenv('LINKEDIN_ACCESS_TOKEN') ?: '';
$isAdminViewer = !empty($_SESSION['can_access_admin']);

// Optional mock mode to test UI/flow without LinkedIn API credentials.
// Enable with LINKEDIN_MOCK_MODE=1 or with ?linkedin_mock=1 (admin only).
$isUsingLinkedInMock = (getenv('LINKEDIN_MOCK_MODE') === '1')
    || ($isAdminViewer && isset($_GET['linkedin_mock']) && $_GET['linkedin_mock'] === '1');
$currentPath = strtok($_SERVER['REQUEST_URI'] ?? '/nieuws.php', '?') ?: '/nieuws.php';
$enableMockUrl = $currentPath . '?linkedin_mock=1';
$disableMockUrl = $currentPath;

function getMockLatestLinkedInPost(string $profileUrl): array
{
    return [
        'url' => $profileUrl . 'posts/',
        'text' => 'Demo-modus: dit is een testpost om de weergave van de nieuwste LinkedIn update te controleren.',
        'urn' => 'urn:li:activity:mock',
        'embedUrl' => '',
        'isMock' => true,
    ];
}

/**
 * Fetch the most recent LinkedIn post for an organization.
 * Returns null when API credentials are missing or when the request fails.
 */
function fetchLatestLinkedInPost(string $orgId, string $accessToken, ?array &$debug = null): ?array
{
    if ($debug === null) {
        $debug = [];
    }

    $debug['credentialMissing'] = false;
    $debug['curlMissing'] = false;
    $debug['attempts'] = [];

    if ($orgId === '' || $accessToken === '') {
        $debug['credentialMissing'] = true;
        return null;
    }

    if (!function_exists('curl_init')) {
        $debug['curlMissing'] = true;
        return null;
    }

    $authorUrn = 'urn:li:organization:' . $orgId;
    $authorUrnList = rawurlencode('List(' . $authorUrn . ')');
    $endpoints = [
        'https://api.linkedin.com/v2/ugcPosts?q=authors&authors=' . $authorUrnList . '&sortBy=LAST_MODIFIED&count=1',
        'https://api.linkedin.com/v2/shares?q=owners&owners=' . $authorUrnList . '&sortBy=LAST_MODIFIED&count=1',
    ];

    foreach ($endpoints as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'X-Restli-Protocol-Version: 2.0.0',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $attemptInfo = [
            'endpoint' => $url,
            'httpCode' => $httpCode,
            'curlError' => $curlError,
            'message' => '',
        ];

        if ($httpCode < 200 || $httpCode >= 300 || !$response) {
            if ($response) {
                $errorData = json_decode($response, true);
                if (is_array($errorData) && isset($errorData['message']) && is_string($errorData['message'])) {
                    $attemptInfo['message'] = $errorData['message'];
                }
            }
            $debug['attempts'][] = $attemptInfo;
            continue;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['elements'][0]) || !is_array($data['elements'][0])) {
            $attemptInfo['message'] = 'Geen post-elementen gevonden in response.';
            $debug['attempts'][] = $attemptInfo;
            continue;
        }

        $post = $data['elements'][0];
        $urn = $post['id'] ?? '';

        if ($urn === '' && isset($post['specificContent']['com.linkedin.ugc.ShareContent'])) {
            // UGC responses may not include a plain id in some app scopes.
            $urn = $post['entityUrn'] ?? '';
        }

        if (!is_string($urn) || $urn === '') {
            $attemptInfo['message'] = 'Post URN ontbreekt in response.';
            $debug['attempts'][] = $attemptInfo;
            continue;
        }

        $postText = '';
        if (isset($post['specificContent']['com.linkedin.ugc.ShareContent']['shareCommentary']['text']) && is_string($post['specificContent']['com.linkedin.ugc.ShareContent']['shareCommentary']['text'])) {
            $postText = $post['specificContent']['com.linkedin.ugc.ShareContent']['shareCommentary']['text'];
        } elseif (isset($post['text']['text']) && is_string($post['text']['text'])) {
            $postText = $post['text']['text'];
        }

        $postUrl = 'https://www.linkedin.com/feed/update/' . $urn . '/';
        $embedUrl = 'https://www.linkedin.com/embed/feed/update/' . $urn;

        return [
            'url' => $postUrl,
            'text' => trim($postText),
            'urn' => $urn,
            'embedUrl' => $embedUrl,
        ];
    }

    return null;
}

$linkedinApiDebug = [];
/**
 * Haal alle posts op via een externe RSS feed (zoals RSS.app)
 */
function fetchAllPostsFromRss(string $url, int $limit = 1000): array
{
    if (empty($url)) {
        return [];
    }
    
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $content = @file_get_contents($url, false, $ctx);
    
    if (!$content) return [];
    
    $xml = @simplexml_load_string($content);
    if ($xml === false || !isset($xml->channel->item)) {
        return [];
    }
    
    $posts = [];
    $count = 0;
    foreach ($xml->channel->item as $item) {
        if ($count >= $limit) break;
        
        $description = (string)$item->description;
        $imageUrl = '';
        if (preg_match('/<img[^>]+src="([^">]+)"/', $description, $matches)) {
            $imageUrl = $matches[1];
        } else {
            $namespaces = $item->getNamespaces(true);
            if (isset($namespaces['media'])) {
                $media = $item->children($namespaces['media']);
                if (isset($media->content)) {
                    foreach($media->content as $mediaContent) {
                        if (isset($mediaContent->attributes()->url)) {
                            $imageUrl = (string)$mediaContent->attributes()->url;
                            break;
                        }
                    }
                }
            }
        }
        
        $text = strip_tags($description);
        $date = (string)$item->pubDate;
        $timestamp = strtotime($date) ?: 0;
        
        $posts[] = [
            'url' => (string)$item->link,
            'text' => trim($text),
            'title' => (string)$item->title,
            'image' => $imageUrl,
            'date' => $timestamp > 0 ? date('d-m-Y', $timestamp) : '',
            'timestamp' => $timestamp
        ];
        $count++;
    }
    
    usort($posts, function ($a, $b) {
        return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
    });

    return $posts;
}

// 1. Haal meerdere posts op via de RSS methode
$rssPosts = fetchAllPostsFromRss($linkedinRssUrl, 50);
$hasRssPosts = !empty($rssPosts);

$latestLinkedInPost = null;
if (!$hasRssPosts) {
    // 2. Fallback naar de oude API methode als RSS niet is ingesteld of mislukt
    $latestLinkedInPost = fetchLatestLinkedInPost($linkedinOrgId, $linkedinAccessToken, $linkedinApiDebug);

    if ($latestLinkedInPost === null && $isUsingLinkedInMock) {
        $latestLinkedInPost = getMockLatestLinkedInPost($linkedinProfileUrl);
        $linkedinApiDebug['mockMode'] = true;
    }
}

$latestLinkedInPostUrl = $latestLinkedInPost['url'] ?? ($linkedinProfileUrl . 'posts/');
$latestLinkedInPostText = $latestLinkedInPost['text'] ?? 'Open direct de nieuwste update van SociaalAI Lab op LinkedIn.';
$latestLinkedInExcerpt = strlen($latestLinkedInPostText) > 220 ? substr($latestLinkedInPostText, 0, 220) . '...' : $latestLinkedInPostText;
$hasLinkedInApiData = $latestLinkedInPost !== null;
$latestLinkedInEmbedUrl = $latestLinkedInPost['embedUrl'] ?? '';
$isMockPost = !empty($latestLinkedInPost['isMock']);

?>

<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="modulepreload" as="script" href="build/assets/app-CAiCLEjY.js"><link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>"><script type="module" src="build/assets/app-CAiCLEjY.js"></script>    <title>Nieuws & Updates</title>
    <meta name="description" content="Blijf op de hoogte van het laatste nieuws, updates en activiteiten van SociaalAI Lab.">
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
    <!-- Nieuws Header -->
    <section class="bg-white shadow-lg p-8 max-w-6xl mx-auto my-12">
        <div class="mb-8">
            <h1 class="text-4xl font-bold text-[#00811F] mb-2">
                <i class="fa-solid fa-newspaper mr-3"></i>Nieuws & Updates
            </h1>
            <p class="text-lg text-gray-600">
                Blijf op de hoogte van het laatste nieuws en activiteiten van SociaalAI Lab
            </p>
        </div>
    </section>

    <!-- LinkedIn Nieuws Feed -->
    <section class="max-w-6xl mx-auto px-8 py-8">
        <div class="bg-white rounded-lg shadow-lg p-8 mb-12">
            <div class="flex items-center gap-4 mb-6 pb-6 border-b-2 border-gray-200">
                <i class="fa-brands fa-linkedin text-4xl text-[#0A66C2]"></i>
                <div>
               
                     <h2 class="text-2xl font-bold text-gray-800" class="pink-text"><span>Nieuws</h2></span>
                        
                    <p class="text-gray-600">Volg ons op LinkedIn voor alle recente activiteiten</p>
                </div>
            </div>

            <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg p-8 text-center">
                <p class="text-gray-700 mb-6 text-lg">
                    Ontdek het meest recente nieuws en activiteiten van SociaalAI Lab Rotterdam
                </p>
                
                <div class="mb-8 relative group">
                    <?php if ($hasRssPosts): ?>
                        <style>
                            .scrollbar-hide::-webkit-scrollbar { display: none; }
                            .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
                            .carousel-arrow {
                                background: linear-gradient(135deg, #e3f0ff 60%, #b9ebff 100%);
                                border: 2px solid #0A66C2;
                                box-shadow: 0 4px 24px #0a66c22a;
                                color: #0A66C2;
                                width: 56px;
                                height: 56px;
                                border-radius: 50%;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                font-size: 2rem;
                                transition: background 0.2s, color 0.2s, box-shadow 0.2s;
                                z-index: 20;
                            }
                            .carousel-arrow:hover, .carousel-arrow:focus {
                                background: #0A66C2;
                                color: #fff;
                                box-shadow: 0 6px 32px #0a66c255;
                            }
                            @media (max-width: 768px) {
                                .carousel-arrow { width: 44px; height: 44px; font-size: 1.5rem; }
                            }
                            /* Nieuwskaart stijl */
                            .news-card {
                                font-family: Arial, Helvetica, sans-serif !important;
                                width: 320px;
                                min-width: 280px;
                                max-width: 340px;
                                background: #fff;
                                border-radius: 1rem;
                                box-shadow: 0 2px 12px #0001;
                                border: 1px solid #eee;
                                display: flex;
                                flex-direction: column;
                                margin: 0 12px;
                                overflow: hidden;
                                transition: box-shadow 0.2s;
                            }
                            .news-card:hover {
                                box-shadow: 0 6px 32px #0a66c233;
                            }
                            .news-card-img {
                                font-family: Arial, Helvetica, sans-serif !important;
                                width: 100%;
                                height: 180px;
                                object-fit: cover;
                                border-radius: 1rem 1rem 0 0;
                                border-bottom: 1px solid #eee;
                                background: #f6f6f6;
                            }
                            .news-card-content {
                                font-family: Arial, Helvetica, sans-serif !important;
                                padding: 1rem 1.2rem 0.5rem 1.2rem;
                                flex: 1 1 auto;
                                display: flex;
                                flex-direction: column;
                            }
                            .news-card-title {
                                font-family: Arial, Helvetica, sans-serif !important;
                                font-size: 1.1rem;
                                font-weight: bold;
                                margin: 0.5rem 0 0.25rem 0;
                                color: #222;
                            }
                            .news-card-meta {
                                font-family: Arial, Helvetica, sans-serif !important;
                                font-size: 0.85rem;
                                color: #888;
                                margin-bottom: 0.5rem;
                            }
                            .news-card-summary {
                                font-family: Arial, Helvetica, sans-serif !important;
                                font-size: 0.97rem;
                                color: #444;
                                margin-bottom: 1rem;
                                flex: 1 1 auto;
                            }
                            .news-card-link {
                                font-family: Arial, Helvetica, sans-serif !important;
                                display: inline-flex;
                                align-items: center;
                                color: #0A66C2;
                                font-weight: 600;
                                font-size: 1rem;
                                margin-bottom: 0.7rem;
                                text-decoration: none;
                                transition: color 0.2s;
                            }
                            .news-card-link:hover {
                                color: #004099;
                            }
                            @media (max-width: 900px) {
                                .news-card { width: 90vw; min-width: 0; max-width: 100vw; }
                                .news-card-img { height: 140px; }
                            }
                        </style>

                        <!-- Pijltjes naast de carrousel -->
                            <style>
                                .news-grid-wrap {
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    gap: 0;
                                    width: 100%;
                                    margin: 0 auto;
                                    max-width: 1300px;
                                }
                                .news-grid {
                                    display: grid;
                                    grid-template-columns: repeat(2, 1fr);
                                    gap: 1.2rem;
                                    justify-items: center;
                                    width: 100%;
                                    max-width: 1100px;
                                    margin: 0 auto;
                                }
                                .news-card {
                                    width: 98%;
                                    max-width: 520px;
                                    min-width: 0;
                                }
                                .news-card-img {
                                    height: 240px;
                                }
                                .carousel-arrow {
                                    margin: 0 !important;
                                }
                                @media (max-width: 900px) {
                                    .news-grid {
                                        grid-template-columns: 1fr;
                                        gap: 1rem;
                                    }
                                    .news-card-img {
                                        height: 160px;
                                    }
                                }
                            </style>
                            <div class="news-grid-wrap py-4 px-2">
                                <?php $newsCount = count($rssPosts); $newsPerPage = 2; $maxPage = (int)floor(($newsCount - 1) / $newsPerPage); ?>
                                <?php if ($newsCount > $newsPerPage): ?>
                                    <button id="prevBtn" class="carousel-arrow" title="Vorige" style="position: static; display:none;" onclick="newsPaginate(-1)">
                                        <i class="fa-solid fa-chevron-left"></i>
                                    </button>
                                <?php endif; ?>
                                <div class="news-grid" id="newsGrid">
                                    <?php foreach ($rssPosts as $i => $post): ?>
                                        <div class="news-card" data-news-index="<?php echo $i; ?>">
                                            <?php if (!empty($post['image'])): ?>
                                                <img src="<?php echo htmlspecialchars($post['image']); ?>" alt="<?php echo htmlspecialchars(trim((string)($post['title'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" class="news-card-img" loading="lazy">
                                            <?php endif; ?>
                                            <div class="news-card-content">
                                                <div class="news-card-title"><?php echo htmlspecialchars($post['title'] ?: 'SociaalAI Lab Update'); ?></div>
                                                <div class="news-card-meta">
                                                    <i class="fa-regular fa-calendar mr-1"></i><?php echo $post['date']; ?>
                                                </div>
                                                <div class="news-card-summary">
                                                    <?php 
                                                        $trimmedText = strlen($post['text']) > 180 ? substr($post['text'], 0, 180) . '...' : $post['text'];
                                                        echo nl2br(htmlspecialchars($trimmedText)); 
                                                    ?>
                                                </div>
                                                <a href="<?php echo htmlspecialchars($post['url']); ?>"
                                                   target="_blank"
                                                   rel="noopener"
                                                   class="news-card-link">
                                                    Lees meer <i class="fa-solid fa-arrow-right ml-2 text-xs"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($newsCount > $newsPerPage): ?>
                                    <button id="nextBtn" class="carousel-arrow" title="Volgende" style="position: static;" onclick="newsPaginate(1)">
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <script>
                            // Toon steeds 2 posts per pagina
                            let newsPage = 0;
                            const newsPerPage = 2;
                            function showNewsPage(page) {
                                const cards = document.querySelectorAll('.news-card');
                                for (let i = 0; i < cards.length; i++) {
                                    if (i >= page * newsPerPage && i < (page + 1) * newsPerPage) {
                                        cards[i].style.display = '';
                                    } else {
                                        cards[i].style.display = 'none';
                                    }
                                }
                                // Pijlen tonen/verbergen
                                const prevBtn = document.getElementById('prevBtn');
                                const nextBtn = document.getElementById('nextBtn');
                                if (prevBtn) prevBtn.style.display = (page === 0) ? 'none' : '';
                                if (nextBtn) nextBtn.style.display = ((page + 1) * newsPerPage) >= cards.length ? 'none' : '';
                            }
                            function newsPaginate(dir) {
                                const cards = document.querySelectorAll('.news-card');
                                const maxPage = Math.floor((cards.length - 1) / newsPerPage);
                                newsPage += dir;
                                if (newsPage < 0) newsPage = 0;
                                if (newsPage > maxPage) newsPage = maxPage;
                                showNewsPage(newsPage);
                            }
                            document.addEventListener('DOMContentLoaded', function() {
                                showNewsPage(0);
                            });
                            </script>
                            
                        </div>



                        <script>
                            document.addEventListener('DOMContentLoaded', () => {
                                const carousel = document.getElementById('carousel');
                                const prevBtn = document.getElementById('prevBtn');
                                const nextBtn = document.getElementById('nextBtn');
                                
                                if (carousel && prevBtn && nextBtn) {
                                    const updateButtons = () => {
                                        if (carousel.scrollLeft <= 5) {
                                            prevBtn.style.opacity = '0';
                                            prevBtn.style.pointerEvents = 'none';
                                        } else {
                                            prevBtn.style.opacity = '1';
                                            prevBtn.style.pointerEvents = 'auto';
                                        }
                                        
                                        if (carousel.scrollLeft + carousel.clientWidth >= carousel.scrollWidth - 5) {
                                            nextBtn.style.opacity = '0';
                                            nextBtn.style.pointerEvents = 'none';
                                        } else {
                                            nextBtn.style.opacity = '1';
                                            nextBtn.style.pointerEvents = 'auto';
                                        }
                                    };
                                    
                                    carousel.addEventListener('scroll', updateButtons);
                                    window.addEventListener('resize', updateButtons);
                                    setTimeout(updateButtons, 100);
                                }
                            });
                        </script>
                    <?php else: ?>
                        <!-- Fallback view if no RSS items -->
                        <div class="bg-white rounded-lg p-6 shadow-md text-left max-w-3xl mx-auto">
                            <div class="flex items-start gap-4 mb-5">
                                <i class="fa-brands fa-linkedin text-3xl text-[#0A66C2] mt-1"></i>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800 mb-2">Meest recente LinkedIn post</h3>
                                    <p class="text-gray-600 mb-4">
                                        <?php echo htmlspecialchars($latestLinkedInExcerpt); ?>
                                    </p>
                                    <?php if (isset($isMockPost, $isAdminViewer) && $isMockPost && $isAdminViewer): ?>
                                        <p class="text-xs text-amber-700 bg-amber-100 border border-amber-200 rounded px-2 py-1 inline-block mb-3">
                                            Testmodus actief (mock data)
                                        </p>
                                    <?php endif; ?>
                                    <a href="<?php echo htmlspecialchars($latestLinkedInPostUrl); ?>"
                                       target="_blank"
                                       rel="noopener"
                                       class="inline-flex items-center gap-2 bg-[#0A66C2] hover:bg-[#004099] text-white font-semibold py-2 px-4 rounded-lg transition-colors">
                                        <i class="fa-solid fa-up-right-from-square"></i>
                                        Bekijk nieuwste post
                                    </a>
                                </div>
                            </div>

                            <?php if (isset($hasLinkedInApiData, $latestLinkedInEmbedUrl) && $hasLinkedInApiData && $latestLinkedInEmbedUrl !== ''): ?>
                                <iframe
                                    src="<?php echo htmlspecialchars($latestLinkedInEmbedUrl); ?>"
                                    height="420"
                                    width="100%"
                                    frameborder="0"
                                    allowfullscreen=""
                                    title="Laatste LinkedIn post"
                                    class="rounded-lg border border-gray-200">
                                </iframe>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <a href="<?php echo htmlspecialchars($linkedinProfileUrl); ?>" 
                   target="_blank" 
                   rel="noopener"
                   class="inline-flex items-center gap-2 bg-[#0A66C2] hover:bg-[#004099] text-white font-bold py-3 px-6 rounded-lg transition-colors">
                    <i class="fa-brands fa-linkedin"></i>
                    Volg ons op LinkedIn
                </a>
            </div>
            
    </section>

<?php include __DIR__ . '/footer.php'; ?>

</body>
</html>
