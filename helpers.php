<?php
if (!function_exists('getPageImageAltText')) {
    function getPageImageAltText(array $block): string {
        $meta = json_decode((string)($block['meta'] ?? ''), true);
        if (is_array($meta) && array_key_exists('image_alt', $meta)) {
            return trim((string)$meta['image_alt']);
        }

        return trim(strip_tags((string)($block['title'] ?? '')));
    }
}

if (!function_exists('getSiteBannerAltText')) {
    function getSiteBannerAltText(PDO $pdo, int $bannerNumber, ?string $source): string {
        $key = 'banner' . $bannerNumber . '_alt';
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $savedAlt = $stmt->fetchColumn();
        if ($savedAlt !== false) {
            return trim((string)$savedAlt);
        }

        $normalizedSource = ltrim((string)$source, './');
        if (basename($normalizedSource) === 'banner_website_01.jpg') {
            return 'Sociaal AI Lab Rotterdam-logo naast een groep mensen tijdens een bijeenkomst.';
        }
        if (basename($normalizedSource) === 'banner_website_02.jpg') {
            return 'Een groep mensen werkt samen rond een laptop bij Sociaal AI Lab Rotterdam.';
        }

        return '';
    }
}

if (!function_exists('getEventImageAltText')) {
    function getEventImageAltText(array $event): string {
        if (isset($event['image_alt'])) {
            return trim((string)$event['image_alt']);
        }

        return trim(strip_tags((string)($event['title'] ?? '')));
    }
}

if (!function_exists('formatEventDateDisplay')) {
    function formatEventDateDisplay($dateValue) {
        if (empty($dateValue)) return '';
        $ts = strtotime((string)$dateValue);
        if ($ts === false) return (string)$dateValue;
        $monthsNl = [
            1 => 'januari', 2 => 'februari', 3 => 'maart', 4 => 'april', 5 => 'mei', 6 => 'juni',
            7 => 'juli', 8 => 'augustus', 9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december'
        ];
        $day = (int)date('j', $ts);
        $month = $monthsNl[(int)date('n', $ts)] ?? date('F', $ts);
        $year = date('Y', $ts);
        return $day . ' ' . $month . ' ' . $year;
    }
}

if (!function_exists('formatEventDateWithWeekdayDisplay')) {
    function formatEventDateWithWeekdayDisplay($dateValue) {
        if (empty($dateValue)) return '';

        $dateString = (string)$dateValue;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateString);
        if (!$date || $date->format('Y-m-d') !== $dateString) {
            return formatEventDateDisplay($dateString);
        }

        $weekdaysNl = [
            1 => 'maandag', 2 => 'dinsdag', 3 => 'woensdag', 4 => 'donderdag',
            5 => 'vrijdag', 6 => 'zaterdag', 7 => 'zondag',
        ];
        $weekday = $weekdaysNl[(int)$date->format('N')];

        return ucfirst($weekday) . ' ' . formatEventDateDisplay($dateString);
    }
}

if (!function_exists('formatEventTimeDisplay')) {
    function formatEventTimeDisplay($timeValue) {
        if (empty($timeValue)) return '';
        $ts = strtotime((string)$timeValue);
        if ($ts === false) return (string)$timeValue;
        return date('H:i', $ts);
    }
}

if (!function_exists('eventEditVersion')) {
    function eventEditVersion(array $event) {
        $versionFields = [
            'id', 'title', 'date', 'end_date', 'time', 'time_end', 'description', 'event_summary',
            'meer_info', 'info_link', 'image', 'image_alt', 'event_gallery', 'event_documents', 'location',
            'hardware_request', 'staff_present', 'target_audience', 'internal_notes',
            'show_signup_button', 'signup_embed', 'show_on_homepage',
        ];
        $snapshot = [];
        foreach ($versionFields as $field) {
            $value = $event[$field] ?? null;
            $snapshot[$field] = $value === null ? null : (string)$value;
        }

        if (empty($_SESSION['event_edit_version_key'])) {
            $_SESSION['event_edit_version_key'] = bin2hex(random_bytes(32));
        }

        $serialized = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return hash_hmac('sha256', $serialized, $_SESSION['event_edit_version_key']);
    }
}

if (!function_exists('beginVersionCheckedEventUpdate')) {
    function beginVersionCheckedEventUpdate(PDO $pdo, int $eventId, $submittedVersion, ?string $approvalStatus = null, ?string $owner = null) {
        $engineStmt = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $engineStmt->execute(['events']);
        if (strtoupper((string)$engineStmt->fetchColumn()) !== 'INNODB') {
            return false;
        }

        $sql = 'SELECT * FROM events WHERE id = ?';
        $parameters = [$eventId];
        if ($approvalStatus !== null) {
            $sql .= ' AND approval_status = ?';
            $parameters[] = $approvalStatus;
        }
        if ($owner !== null) {
            $sql .= ' AND created_by = ?';
            $parameters[] = $owner;
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql . ' FOR UPDATE');
        $stmt->execute($parameters);
        $event = $stmt->fetch();
        if (!$event || !is_string($submittedVersion) || !hash_equals(eventEditVersion($event), $submittedVersion)) {
            $pdo->rollBack();
            return null;
        }

        return $event;
    }
}

if (!function_exists('googleMapsDirectionsUrl')) {
    function googleMapsDirectionsUrl($destination) {
        if (empty($destination)) return '#';
        return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode((string)$destination);
    }
}

if (!function_exists('renderAanmelderEmbed')) {
    function renderAanmelderEmbed($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $href = null;
        
        // Check if it's a plain URL
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $href = $value;
        } else {
            // Extract URL from HTML anchor tag
            if (preg_match('~<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', $value, $matches)) {
                $href = trim((string)$matches[2]);
            }
        }
        
        if ($href !== null && $href !== '') {
            // Return as simple link button
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center bg-[#00811F] text-white font-semibold px-6 py-3 rounded-md shadow hover:bg-[#006f19] transition">Aanmelden voor dit evenement</a>';
        }
        
        return '';
    }
}

if (!function_exists('editorContainsHtmlTag')) {
    function editorContainsHtmlTag($value) {
        return preg_match('~<\s*/?\s*[a-z][^>]*>~i', (string)$value) === 1;
    }
}

if (!function_exists('sanitizeEditorHtml')) {
    function sanitizeEditorHtml($value, $mode = 'block') {
        $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (trim($value) === '') {
            return '';
        }

        $value = preg_replace('/\r\n?/', "\n", $value);
        $value = preg_replace('~<!--.*?-->~s', '', $value);
        $value = preg_replace('~<\s*(script|style|iframe|object|embed|form|input|button|textarea|select|option|meta|link)[^>]*>.*?<\s*/\s*\1\s*>~is', '', $value);
        $value = preg_replace('~<\s*(script|style|iframe|object|embed|form|input|button|textarea|select|option|meta|link)[^>]*/?\s*>~is', '', $value);

        $allowedTags = $mode === 'inline'
            ? '<strong><b><em><i><u><a><br>'
            : '<br><strong><b><em><i><u><ul><ol><li><a><p>';

        $value = strip_tags($value, $allowedTags);

        // Keep only safe href values on links and drop all other attributes.
        $value = preg_replace_callback('~<a\b([^>]*)>~i', function ($matches) {
            $attrs = $matches[1] ?? '';
            $href = '';

            if (preg_match('/href\s*=\s*(["\'])(.*?)\1/i', $attrs, $hrefMatch)) {
                $href = trim($hrefMatch[2]);
            } elseif (preg_match('/href\s*=\s*([^\s>]+)/i', $attrs, $hrefMatch)) {
                $href = trim($hrefMatch[1]);
            }

            if ($href !== '' && preg_match('~^(https?:|mailto:|tel:|/|#)~i', $href)) {
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            }

            return '<a>';
        }, $value);

        // Remove attributes from all non-link tags.
        $value = preg_replace('/<(?!\/?a\b)([a-z0-9]+)\b[^>]*>/i', '<$1>', $value);
        
        // Convert <p> tags with content into proper spacing (preserve <br> from TinyMCE)
        $value = preg_replace('~<p[^>]*>(.*?)</p>~is', '$1<br>', $value);
        // Remove empty paragraphs
        $value = preg_replace('~<p>\s*</p>~i', '', $value);
        // Remove excessive consecutive br tags (more than 2)
        $value = preg_replace('~(<br\s*/?>\s*){3,}~i', '<br><br>', $value);

        return trim($value);
    }
}

if (!function_exists('sanitizeEditorPlainText')) {
    function sanitizeEditorPlainText($value) {
        $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = preg_replace('/\r\n?/', "\n", $value);
        $value = preg_replace('/[ \t]+\n/', "\n", $value);
        $value = preg_replace('/\n{3,}/', "\n\n", $value);
        return trim($value);
    }
}

if (!function_exists('renderEditorInline')) {
    function renderEditorInline($value) {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }

        // Normalize line endings
        $value = preg_replace('/\r\n?/', "\n", $value);
        
        // First, replace entity-escaped br tags with actual newlines
        $value = str_replace('&lt;br&gt;', "\n", $value);
        $value = str_replace('&lt;br/&gt;', "\n", $value);
        $value = str_replace('&lt;br /&gt;', "\n", $value);
        $value = str_replace('&lt;BR&gt;', "\n", $value);
        
        // Decode any remaining entity-escaped HTML
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Check if it has HTML tags now
        $hasHtmlTags = editorContainsHtmlTag($value);
        
        // If it has HTML (like <br> or <strong> from TinyMCE), sanitize it
        if ($hasHtmlTags) {
            return sanitizeEditorHtml($value, 'inline');
        }

        // If it's plain text, just escape it (don't convert newlines to <br> for inline)
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('renderEditorBlock')) {
    function renderEditorBlock($value) {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }

        // Normalize line endings
        $value = preg_replace('/\r\n?/', "\n", $value);
        
        // Replace entity-escaped br tags with actual newlines
        // This converts &lt;br&gt; to \n so nl2br() can handle it properly
        $value = str_replace('&lt;br&gt;', "\n", $value);
        $value = str_replace('&lt;br/&gt;', "\n", $value);
        $value = str_replace('&lt;br /&gt;', "\n", $value);
        $value = str_replace('&lt;BR&gt;', "\n", $value);
        
        // Decode any remaining entity-escaped content
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // If it still has entities after first decode, decode again (double-escaped)
        if (strpos($decoded, '&lt;') !== false) {
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        
        // Now check if we have HTML tags
        $hasHtmlTags = editorContainsHtmlTag($decoded);
        
        // If it has HTML tags (like <br> or <p>), sanitize them
        if ($hasHtmlTags) {
            return sanitizeEditorHtml($decoded, 'block');
        }

        // Plain text: escape it and convert newlines to <br>
        $escaped = htmlspecialchars($decoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return nl2br($escaped);
    }
}

if (!function_exists('editorPreviewText')) {
    function editorPreviewText($value, $maxWidth = 150) {
        $plain = sanitizeEditorPlainText($value);

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($plain, 0, (int)$maxWidth, '...');
        }

        if (strlen($plain) <= $maxWidth) {
            return $plain;
        }

        return substr($plain, 0, max(0, (int)$maxWidth - 3)) . '...';
    }
}

if (!function_exists('eventShortSummary')) {
    function eventShortSummary($value, $maxChars = 180) {
        $text = sanitizeEditorPlainText($value);
        if ($text === '') {
            return '';
        }

        $maxChars = max(80, (int)$maxChars);
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($sentences)) {
            return editorPreviewText($text, $maxChars);
        }

        $summary = '';
        foreach ($sentences as $sentence) {
            $candidate = trim($summary === '' ? $sentence : $summary . ' ' . $sentence);

            if (function_exists('mb_strlen')) {
                if (mb_strlen($candidate) <= $maxChars) {
                    $summary = $candidate;
                    continue;
                }
            } else {
                if (strlen($candidate) <= $maxChars) {
                    $summary = $candidate;
                    continue;
                }
            }

            break;
        }

        if ($summary === '') {
            return editorPreviewText($text, $maxChars);
        }

        return $summary;
    }
}

if (!function_exists('eventNarrativeSummary')) {
    function eventNarrativeSummary($title, $description, $date = null, $location = null, $maxChars = 280) {
        $descriptionText = sanitizeEditorPlainText($description);
        if ($descriptionText === '') {
            return '';
        }

        $titleText = sanitizeEditorPlainText($title);
        $textLower = function_exists('mb_strtolower')
            ? mb_strtolower($descriptionText, 'UTF-8')
            : strtolower($descriptionText);

        $partnerName = '';
        if (preg_match('/(?:samen met|in samenwerking met|partner(?:s)?(?:chap)? met)\s+([^\.,;\n]+)/iu', $descriptionText, $match)) {
            $partnerName = trim((string)$match[1]);
        }

        $summaryLead = 'Tijdens dit evenement ';
        if ($partnerName !== '') {
            $summaryLead .= 'werkte het SociaalAI Lab samen met ' . $partnerName;
        } elseif ($titleText !== '') {
            $summaryLead .= 'stond ' . $titleText . ' centraal';
        } else {
            $summaryLead .= 'brachten we mensen samen rondom AI in de praktijk';
        }
        $summaryLead .= '. ';

        $happened = eventShortSummary($descriptionText, 520);
        if ($happened !== '') {
            $happened = rtrim($happened);
            if (!preg_match('/[\.\!\?]$/u', $happened)) {
                $happened .= '.';
            }
            $happened .= ' ';
        }

        $learningParts = [];
        if (preg_match('/workshop|sessie|oefening/i', $textLower)) {
            $learningParts[] = 'praktische vaardigheden om AI bewuster te gebruiken';
        }
        if (preg_match('/discussie|gesprek|dialoog|vragen/i', $textLower)) {
            $learningParts[] = 'ruimte om vragen te stellen en ervaringen uit te wisselen';
        }
        if (preg_match('/inzicht|conclusie|resultaat|opgehaald|geleerd/i', $textLower)) {
            $learningParts[] = 'concrete inzichten die direct toepasbaar zijn in het dagelijks leven';
        }

        if (!empty($learningParts)) {
            $learning = 'Wat we hebben geleerd voor mensen: ' . implode(', ', array_unique($learningParts)) . '.';
        } else {
            $learning = 'Wat we hebben geleerd voor mensen: duidelijke uitleg, meer grip op AI en praktische handvatten voor dagelijkse situaties.';
        }

        $summary = $summaryLead . $happened . $learning;
        $maxChars = max(260, (int)$maxChars);

        return editorPreviewText($summary, $maxChars);
    }
}

if (!function_exists('normalizeDisplayText')) {
    function normalizeDisplayText($value) {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }

        if (preg_match('//u', $value) !== 1 && function_exists('iconv')) {
            $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
            if ($converted !== false && $converted !== '') {
                $value = $converted;
            }
        }

        if (function_exists('iconv') && preg_match('/(?:Ã.|Â.|â€|ðŸ)/u', $value)) {
            $converted = @iconv('UTF-8', 'Windows-1252', $value);
            if ($converted !== false && preg_match('//u', $converted) === 1) {
                $value = $converted;
            }
        }

        $value = strtr($value, [
            'Ã¶' => 'ö',
            'Ã«' => 'ë',
            'Ã¼' => 'ü',
            'Ã¯' => 'ï',
            'Ã©' => 'é',
            'Ã±' => 'ñ',
            'Ã§' => 'ç',
            'Ã¨' => 'è',
            'Ãª' => 'ê',
            'Ã¡' => 'á',
            'Ã³' => 'ó',
            'Ã–' => 'Ö',
            'Ã‹' => 'Ë',
            'Ã‰' => 'É',
            'Ã‘' => 'Ñ',
            'Ã‡' => 'Ç',
            'â€™' => '’',
            'â€œ' => '“',
            'â€\x9d' => '”',
            'â€“' => '–',
            'â€”' => '—',
            'Â ' => ' ',
        ]);

        return $value;
    }
}

if (!function_exists('formatHomepageNewsText')) {
    function formatHomepageNewsText(string $text): string {
        if (!class_exists(Normalizer::class) || !class_exists(IntlChar::class)) {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $html = '';
        $inBold = false;
        $isMathematicalBold = static function (string $character): bool {
            $codePoint = IntlChar::ord($character);
            return ($codePoint >= 0x1D400 && $codePoint <= 0x1D433)
                || ($codePoint >= 0x1D468 && $codePoint <= 0x1D49B)
                || ($codePoint >= 0x1D4D0 && $codePoint <= 0x1D503)
                || ($codePoint >= 0x1D56C && $codePoint <= 0x1D59F)
                || ($codePoint >= 0x1D5D4 && $codePoint <= 0x1D607)
                || ($codePoint >= 0x1D6A8 && $codePoint <= 0x1D6E1)
                || ($codePoint >= 0x1D71C && $codePoint <= 0x1D755)
                || ($codePoint >= 0x1D7CE && $codePoint <= 0x1D7D7)
                || ($codePoint >= 0x1D7EC && $codePoint <= 0x1D7F5);
        };

        foreach ($characters as $index => $character) {
            $isBold = $isMathematicalBold($character);
            if (!$isBold && $inBold && isset($characters[$index + 1])
                && preg_match('/^[\s\p{P}]$/u', $character) === 1
                && $isMathematicalBold($characters[$index + 1])) {
                $isBold = true;
            }

            if ($isBold !== $inBold) {
                $html .= $isBold ? '<strong>' : '</strong>';
                $inBold = $isBold;
            }

            $displayCharacter = $isMathematicalBold($character)
                ? (Normalizer::normalize($character, Normalizer::FORM_KC) ?: $character)
                : $character;
            $html .= htmlspecialchars($displayCharacter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if ($inBold) {
            $html .= '</strong>';
        }

        return $html;
    }
}

?>
