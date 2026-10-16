<?php
session_start();
require 'db.php';
require 'helpers.php';

// Security check: redirect to login if not logged in
if (!($_SESSION['can_access_admin'] ?? false)) {
    header('Location: login.php');
    exit;
}

$rolePermissions = [
    'administrator' => ['create_users', 'edit_users', 'delete_users', 'manage_banners', 'manage_events', 'manage_pages', 'delete_events', 'delete_pages', 'optimize_images', 'approve_content', 'access_booking', 'view_audit'],
    'content_manager' => ['manage_banners', 'manage_events', 'manage_pages', 'delete_events', 'delete_pages', 'optimize_images', 'approve_content', 'access_booking'],
    'onderzoeker' => ['access_booking', 'create_events', 'view_feedback', 'view_pages'],
    'viewer' => [],
];

function normalizeAccountPermissions($value)
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return null;
    }

    return array_values(array_unique(array_filter(array_map('strval', $decoded))));
}

function permissionsForRole($role, $rolePermissions)
{
    return $rolePermissions[$role] ?? [];
}

$sessionRole = trim((string)($_SESSION['role'] ?? ''));
if ($sessionRole === '') {
    $sessionRole = (isset($_SESSION['admin']) && (int)$_SESSION['admin'] === 1) ? 'administrator' : 'viewer';
    $_SESSION['role'] = $sessionRole;
}

// Simple permission helpers (fallback if missing earlier)
$hasPermission = function ($permission) use ($sessionRole, $rolePermissions) {
    if (!is_string($permission) || $permission === '') return false;
    $perms = $rolePermissions[$sessionRole] ?? [];
    return in_array($permission, $perms, true);
};

$isOnderzoeker = ($sessionRole === 'onderzoeker');

// Migration: map old roles to new roles
$hasAnyPermission = function ($permissions) use (&$hasPermission) {
    foreach ($permissions as $permission) {
        if ($hasPermission($permission)) {
            return true;
        }
    }
    return false;
};

$hasRole = function ($role) use (&$sessionRole) {
    return $sessionRole === $role;
};

$adminPermissionOptions = [
    'manage_banners' => 'Banners aanpassen',
    'manage_events' => 'Agenda beheren',
    'delete_events' => 'Agenda-items verwijderen',
    'manage_pages' => 'Pagina\'s beheren',
    'delete_pages' => 'Pagina\'s verwijderen',
    'create_users' => 'Gebruikers maken',
    'edit_users' => 'Gebruikers bewerken',
    'delete_users' => 'Gebruikers verwijderen',
    'access_booking' => 'Booking pagina',
    'optimize_images' => 'Afbeeldingen optimaliseren',
    'approve_content' => 'Inhoud goedkeuren',
    'create_events' => 'Agendapunten aanmaken',
    'view_feedback' => 'Feedback bekijken',
];
$allowedPermissionKeys = array_keys($adminPermissionOptions);

$collectPostedPermissions = function ($field) use (&$allowedPermissionKeys) {
    $posted = $_POST[$field] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }

    return array_values(array_intersect($allowedPermissionKeys, array_map('strval', $posted)));
};

$message = '';
// Helper voor upload
function handleUpload($fileField)
{
    if (empty($_FILES[$fileField]['name'])) return null;
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    // Max 10MB voor uploads in het hele admin panel
    if (!in_array($_FILES[$fileField]['type'], $allowed) || $_FILES[$fileField]['size'] > 10 * 1024 * 1024) {
        return ['error' => '<div style="background:#fff3cd;color:#856404;border:1.5px solid #ffeeba;padding:18px 20px;border-radius:10px;font-size:1.13rem;font-weight:600;max-width:480px;margin:18px auto;text-align:center;box-shadow:0 2px 12px rgba(255,193,7,0.08);">
        <span style="font-size:1.5em;vertical-align:middle;">⚠️</span><br>
        Je afbeelding is groter dan 10MB.<br>
        Bestand te groot — maak het kleiner.<br>
        <span style=\'font-size:0.97em;font-weight:400;\'>(Alleen JPG/PNG tot 10MB toegestaan)</span>
        </div>'];
    }
    if (!is_dir(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads', 0755, true);
    $ext = pathinfo($_FILES[$fileField]['name'], PATHINFO_EXTENSION);
    $imageName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = __DIR__ . '/uploads/' . $imageName;
    if (!move_uploaded_file($_FILES[$fileField]['tmp_name'], $dest)) {
        return ['error' => 'Kon afbeelding niet opslaan.'];
    }
    return ['name' => $imageName];
}

function handleMultiUpload($fileField)
{
    if (empty($_FILES[$fileField]) || !isset($_FILES[$fileField]['name']) || !is_array($_FILES[$fileField]['name'])) {
        return ['names' => []];
    }

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $saved = [];

    if (!is_dir(__DIR__ . '/uploads')) {
        mkdir(__DIR__ . '/uploads', 0755, true);
    }

    $count = count($_FILES[$fileField]['name']);
    for ($i = 0; $i < $count; $i++) {
        $name = $_FILES[$fileField]['name'][$i] ?? '';
        if ($name === '') {
            continue;
        }

        $tmp = $_FILES[$fileField]['tmp_name'][$i] ?? '';
        $type = $_FILES[$fileField]['type'][$i] ?? '';
        $size = (int)($_FILES[$fileField]['size'][$i] ?? 0);
        $error = (int)($_FILES[$fileField]['error'][$i] ?? UPLOAD_ERR_OK);

        if ($error !== UPLOAD_ERR_OK) {
            foreach ($saved as $fileName) {
                $path = __DIR__ . '/uploads/' . $fileName;
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
            return ['error' => 'Een van de galerijfoto\'s kon niet worden geupload.'];
        }

        // Max 10MB per foto, zelfde melding als enkele upload
        if (!in_array($type, $allowed, true) || $size > 10 * 1024 * 1024) {
            foreach ($saved as $fileName) {
                $path = __DIR__ . '/uploads/' . $fileName;
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
            return ['error' => '<div style="background:#fff3cd;color:#856404;border:1.5px solid #ffeeba;padding:18px 20px;border-radius:10px;font-size:1.13rem;font-weight:600;max-width:480px;margin:18px auto;text-align:center;box-shadow:0 2px 12px rgba(255,193,7,0.08);">
            <span style="font-size:1.5em;vertical-align:middle;">⚠️</span><br>
            Je afbeelding is groter dan 10MB.<br>
            Bestand te groot — maak het kleiner.<br>
            <span style=\'font-size:0.97em;font-weight:400;\'>(Alleen JPG/PNG tot 10MB toegestaan)</span>
            </div>'];
        }

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $imageName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = __DIR__ . '/uploads/' . $imageName;
        if (!move_uploaded_file($tmp, $dest)) {
            foreach ($saved as $fileName) {
                $path = __DIR__ . '/uploads/' . $fileName;
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
            return ['error' => 'Kon een galerijafbeelding niet opslaan.'];
        }

        $saved[] = $imageName;
    }

    return ['names' => $saved];
}

function handleDocumentUpload($fileField)
{
    if (empty($_FILES[$fileField]) || !isset($_FILES[$fileField]['name']) || !is_array($_FILES[$fileField]['name'])) {
        return ['names' => []];
    }

    $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
    $saved = [];

    if (!is_dir(__DIR__ . '/uploads')) {
        mkdir(__DIR__ . '/uploads', 0755, true);
    }

    $count = count($_FILES[$fileField]['name']);
    for ($i = 0; $i < $count; $i++) {
        $name = $_FILES[$fileField]['name'][$i] ?? '';
        if ($name === '') {
            continue;
        }

        $tmp = $_FILES[$fileField]['tmp_name'][$i] ?? '';
        $size = (int)($_FILES[$fileField]['size'][$i] ?? 0);
        $error = (int)($_FILES[$fileField]['error'][$i] ?? UPLOAD_ERR_OK);

        if ($error !== UPLOAD_ERR_OK) {
            foreach ($saved as $fileName) {
                $path = __DIR__ . '/uploads/' . $fileName;
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
            return ['error' => 'Een van de documenten kon niet worden geupload.'];
        }

        if ($size > 10 * 1024 * 1024) {
            foreach ($saved as $fileName) {
                $path = __DIR__ . '/uploads/' . $fileName;
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
            return ['error' => 'Document is groter dan 10MB.'];
        }

        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            foreach ($saved as $fileName) {
                $path = __DIR__ . '/uploads/' . $fileName;
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
            return ['error' => 'Alleen PDF, Office-bestanden of TXT zijn toegestaan voor documenten.'];
        }

        $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($name, PATHINFO_FILENAME));
        if ($safeBase === '' || $safeBase === null) {
            $safeBase = 'document';
        }

        $storedName = time() . '_' . bin2hex(random_bytes(6)) . '_' . $safeBase . '.' . $ext;
        $dest = __DIR__ . '/uploads/' . $storedName;
        if (!move_uploaded_file($tmp, $dest)) {
            foreach ($saved as $fileName) {
                $path = __DIR__ . '/uploads/' . $fileName;
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
            return ['error' => 'Kon een document niet opslaan.'];
        }

        $saved[] = $storedName;
    }

    return ['names' => $saved];
}

function cleanupNewEventUploads(array $fileNames)
{
    foreach (array_unique(array_filter($fileNames, 'is_string')) as $fileName) {
        if ($fileName !== basename($fileName)) {
            continue;
        }
        $path = __DIR__ . '/uploads/' . $fileName;
        if (is_file($path)) {
            unlink($path);
        }
    }
}

// Image optimizer function
function optimizeImage($imagePath, $quality = 85)
{
    if (!file_exists($imagePath)) {
        return ['error' => 'Afbeelding niet gevonden.'];
    }

    $originalSize = filesize($imagePath);
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $imagePath);
    finfo_close($finfo);

    $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

    // Check if GD library is available
    if (!extension_loaded('gd')) {
        return ['error' => 'GD-bibliotheek niet geïnstalleerd op server.', 'size_original' => $originalSize];
    }

    try {
        $image = null;

        // Load image based on type
        if (in_array($mimeType, ['image/jpeg', 'image/jpg'])) {
            $image = imagecreatefromjpeg($imagePath);
        } elseif ($mimeType === 'image/png') {
            $image = imagecreatefrompng($imagePath);
        } elseif ($mimeType === 'image/gif') {
            $image = imagecreatefromgif($imagePath);
        } elseif ($mimeType === 'image/webp') {
            $image = imagecreatefromwebp($imagePath);
        }

        if (!$image) {
            return ['error' => 'Kon afbeelding niet laden.', 'size_original' => $originalSize];
        }

        // Save optimized JPEG (best compatibility)
        $optimizedPath = str_replace('.' . $ext, '_optimized.jpg', $imagePath);
        imagejpeg($image, $optimizedPath, $quality);
        imagedestroy($image);

        $newSize = filesize($optimizedPath);
        $saved = $originalSize - $newSize;
        $savedPercent = $originalSize > 0 ? round(($saved / $originalSize) * 100, 1) : 0;

        // Replace original with optimized
        @unlink($imagePath);
        rename($optimizedPath, $imagePath);

        return [
            'success' => true,
            'size_original' => $originalSize,
            'size_optimized' => $newSize,
            'saved_bytes' => $saved,
            'saved_percent' => $savedPercent,
            'format' => 'JPEG'
        ];
    } catch (Exception $e) {
        return ['error' => 'Fout bij optimalisatie: ' . $e->getMessage(), 'size_original' => $originalSize];
    }
}

function decodeEventGallery($value)
{
    if (!is_string($value) || $value === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = [];
    foreach ($decoded as $fileName) {
        $fileName = trim((string)$fileName);
        if ($fileName !== '') {
            $items[] = $fileName;
        }
    }

    return $items;
}

function decodeEventDocuments($value)
{
    if (!is_string($value) || $value === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = [];
    foreach ($decoded as $fileName) {
        $fileName = trim((string)$fileName);
        if ($fileName !== '') {
            $items[] = $fileName;
        }
    }

    return $items;
}

function sanitizeEditorText($value)
{
    return sanitizeEditorPlainText($value);
}

function sanitizeEditorInlineInput($value)
{
    return sanitizeEditorHtml($value, 'inline');
}

function sanitizeEditorBlockInput($value)
{
    return sanitizeEditorHtml($value, 'block');
}

// Fetch current banners
$banner1 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner1'")->fetchColumn() ?: 'images/banner_website_01.jpg';
$banner2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner2'")->fetchColumn() ?: 'images/banner_website_02.jpg';
$banner3 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner3'")->fetchColumn() ?: null;
$banner4 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'banner4'")->fetchColumn() ?: null;

// Current user email
$currentUser = $_SESSION['user'] ?? null;

// Verwerk POST acties
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $actionPermissionMap = [
        'create' => ($sessionRole === 'onderzoeker' ? 'create_events' : 'manage_events'),
        'update' => 'manage_events',  // Onderzoekers kunnen NIET updaten
        'delete' => 'delete_events',
        'delete_bulk_events' => 'delete_events',
        'reorder_event' => 'manage_events',
        'delete_bulk_pages' => 'delete_pages',
        'update_banner' => 'manage_banners',
        'reset_banners' => 'manage_banners',
        'create_user' => 'create_users',
        'update_user' => 'edit_users',
        'update_user_permissions' => 'edit_users',
        'delete_user' => 'delete_users',
        'optimize_image' => 'optimize_images',
        'approve_item' => 'approve_content',
        'reject_item' => 'approve_content',
    ];

    if ($action !== '' && isset($actionPermissionMap[$action]) && !$hasPermission($actionPermissionMap[$action])) {
        $message = 'Je hebt geen rechten om deze actie uit te voeren.';
        $action = '';
    }

    if ($action === 'create') {
        $title = sanitizeEditorInlineInput($_POST['title'] ?? '');
        $date = $_POST['date'] ?? '';
        $end_date = isset($_POST['add_end_date']) && !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $time = $_POST['time'] ?? null;
        $time_end = isset($_POST['add_end_time']) && !empty($_POST['time_end']) ? $_POST['time_end'] : null;
        $description = sanitizeEditorBlockInput($_POST['description'] ?? '');
        $eventSummary = sanitizeEditorBlockInput($_POST['event_summary'] ?? '');
        $meerInfo = sanitizeEditorBlockInput($_POST['meer_info'] ?? '');
        $location = sanitizeEditorPlainText($_POST['location'] ?? '');
        $hardwareRequest = sanitizeEditorBlockInput($_POST['hardware_request'] ?? '');
        $staffPresent = sanitizeEditorBlockInput($_POST['staff_present'] ?? '');
        $targetAudience = sanitizeEditorPlainText($_POST['target_audience'] ?? '');
        $internalNotes = sanitizeEditorBlockInput($_POST['internal_notes'] ?? '');
        $showSignupButton = isset($_POST['show_signup_button']) ? 1 : 0;
        $signupEmbed = trim((string)($_POST['signup_embed'] ?? ''));
        $showOnHomepage = isset($_POST['show_on_homepage']) ? 1 : 0;
        $infoLink = trim((string)($_POST['info_link'] ?? ''));

        // Approval status based on role
        $approvalStatus = ($sessionRole === 'onderzoeker') ? 'pending' : 'approved';

        if ($date === '') {
            $message = 'Datum is verplicht.';
        } else {
            if ($title === '') {
                $title = ' ';
            }
            $upload = handleUpload('image');
            if (isset($upload['error'])) {
                $message = $upload['error'];
            } elseif (empty($upload['name'])) {
                $message = 'Een afbeelding is verplicht voor een evenement.';
            } else {
                $imageName = $upload['name'] ?? null;
                $galleryUpload = handleMultiUpload('gallery_images');
                if (isset($galleryUpload['error'])) {
                    if (!empty($imageName) && file_exists(__DIR__ . '/uploads/' . $imageName)) {
                        @unlink(__DIR__ . '/uploads/' . $imageName);
                    }
                    $message = $galleryUpload['error'];
                } else {
                    $documentUpload = handleDocumentUpload('event_documents');
                    if (isset($documentUpload['error'])) {
                        if (!empty($imageName) && file_exists(__DIR__ . '/uploads/' . $imageName)) {
                            @unlink(__DIR__ . '/uploads/' . $imageName);
                        }
                        foreach ($galleryUpload['names'] ?? [] as $galleryFile) {
                            $galleryPath = __DIR__ . '/uploads/' . $galleryFile;
                            if (file_exists($galleryPath)) {
                                @unlink($galleryPath);
                            }
                        }
                        $message = $documentUpload['error'];
                    } else {
                        $galleryJson = !empty($galleryUpload['names'])
                            ? json_encode(array_values($galleryUpload['names']), JSON_UNESCAPED_SLASHES)
                            : null;
                        $documentsJson = !empty($documentUpload['names'])
                            ? json_encode(array_values($documentUpload['names']), JSON_UNESCAPED_SLASHES)
                            : null;

                        // voeg updated_at, updated_by, en approval gegevens toe bij insert
                        $stmt = $pdo->prepare('INSERT INTO events (title, date, end_date, time, time_end, description, event_summary, meer_info, info_link, image, event_gallery, event_documents, location, hardware_request, staff_present, target_audience, internal_notes, show_signup_button, signup_embed, show_on_homepage, updated_at, updated_by, approval_status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)');
                        $stmt->execute([$title, $date, $end_date, $time ?: null, $time_end ?: null, $description, $eventSummary ?: null, $meerInfo ?: null, $infoLink ?: null, $imageName, $galleryJson, $documentsJson, $location ?: null, $hardwareRequest ?: null, $staffPresent ?: null, $targetAudience ?: null, $internalNotes ?: null, $showSignupButton, $signupEmbed ?: null, $showOnHomepage, $currentUser, $approvalStatus, $currentUser]);
                        $eventId = $pdo->lastInsertId();
                        // Audit log: event created
                        audit_log($pdo, 'create', 'events', $eventId, 'title: ' . $title, $currentUser);
                        header('Location: admin.php?page=agenda&ok=create');
                        exit;
                    }
                }
            }
        }
    } elseif ($action === 'update' && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $title = sanitizeEditorInlineInput($_POST['title'] ?? '');
        $date = $_POST['date'] ?? '';
        $end_date = isset($_POST['add_end_date']) && !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $time = $_POST['time'] ?? null;
        $time_end = isset($_POST['add_end_time']) && !empty($_POST['time_end']) ? $_POST['time_end'] : null;
        $description = sanitizeEditorBlockInput($_POST['description'] ?? '');
        $eventSummary = sanitizeEditorBlockInput($_POST['event_summary'] ?? '');
        $meerInfo = sanitizeEditorBlockInput($_POST['meer_info'] ?? '');
        $location = sanitizeEditorPlainText($_POST['location'] ?? '');
        $hardwareRequest = sanitizeEditorBlockInput($_POST['hardware_request'] ?? '');
        $staffPresent = sanitizeEditorBlockInput($_POST['staff_present'] ?? '');
        $targetAudience = sanitizeEditorPlainText($_POST['target_audience'] ?? '');
        $internalNotes = sanitizeEditorBlockInput($_POST['internal_notes'] ?? '');
        $showSignupButton = isset($_POST['show_signup_button']) ? 1 : 0;
        $signupEmbed = trim((string)($_POST['signup_embed'] ?? ''));
        $showOnHomepage = isset($_POST['show_on_homepage']) ? 1 : 0;
        $infoLink = trim((string)($_POST['info_link'] ?? ''));
        $removeImage = isset($_POST['remove_image']) ? 1 : 0;

        if ($date === '') {
            $message = 'Datum is verplicht.';
        } else {
            if ($title === '') {
                $title = ' ';
            }
            // check existing image
            $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            $oldImage = $row ? $row['image'] : null;
            $oldGallery = decodeEventGallery($row['event_gallery'] ?? null);
            $oldDocuments = decodeEventDocuments($row['event_documents'] ?? null);
            $removeGallery = $_POST['remove_gallery_images'] ?? [];
            if (!is_array($removeGallery)) {
                $removeGallery = [];
            }
            $removeGallery = array_values(array_intersect($oldGallery, array_map('strval', $removeGallery)));
            $keptGallery = array_values(array_diff($oldGallery, $removeGallery));
            $removeDocuments = $_POST['remove_event_documents'] ?? [];
            if (!is_array($removeDocuments)) {
                $removeDocuments = [];
            }
            $removeDocuments = array_values(array_intersect($oldDocuments, array_map('strval', $removeDocuments)));
            $keptDocuments = array_values(array_diff($oldDocuments, $removeDocuments));

            $upload = handleUpload('image');
            if (isset($upload['error'])) {
                $message = $upload['error'];
            } elseif (empty($upload['name']) && ($removeImage || empty($oldImage))) {
                $message = 'Een afbeelding is verplicht voor een evenement.';
            } else {
                $galleryUpload = handleMultiUpload('gallery_images');
                if (isset($galleryUpload['error'])) {
                    if (!empty($upload['name']) && file_exists(__DIR__ . '/uploads/' . $upload['name'])) {
                        @unlink(__DIR__ . '/uploads/' . $upload['name']);
                    }
                    $message = $galleryUpload['error'];
                } else {
                    $documentUpload = handleDocumentUpload('event_documents');
                    if (isset($documentUpload['error'])) {
                        if (!empty($upload['name']) && file_exists(__DIR__ . '/uploads/' . $upload['name'])) {
                            @unlink(__DIR__ . '/uploads/' . $upload['name']);
                        }
                        foreach ($galleryUpload['names'] ?? [] as $galleryFile) {
                            $galleryPath = __DIR__ . '/uploads/' . $galleryFile;
                            if (file_exists($galleryPath)) {
                                @unlink($galleryPath);
                            }
                        }
                        $message = $documentUpload['error'];
                    } else {
                        $imageName = $upload['name'] ?? ($removeImage ? null : $oldImage);

                        $galleryNames = array_values(array_merge($keptGallery, $galleryUpload['names'] ?? []));
                        $galleryJson = !empty($galleryNames)
                            ? json_encode($galleryNames, JSON_UNESCAPED_SLASHES)
                            : null;
                        $documentNames = array_values(array_merge($keptDocuments, $documentUpload['names'] ?? []));
                        $documentsJson = !empty($documentNames)
                            ? json_encode($documentNames, JSON_UNESCAPED_SLASHES)
                            : null;

                        $newUploads = array_merge(
                            [$upload['name'] ?? null],
                            $galleryUpload['names'] ?? [],
                            $documentUpload['names'] ?? []
                        );
                        $engineStmt = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                        $engineStmt->execute(['events']);
                        if (strtoupper((string)$engineStmt->fetchColumn()) !== 'INNODB') {
                            cleanupNewEventUploads($newUploads);
                            $message = 'Opslaan is geblokkeerd omdat veilige versiecontrole niet beschikbaar is. Neem contact op met de websitebeheerder.';
                        } else {
                            $submittedVersion = $_POST['edit_version'] ?? '';
                            $pdo->beginTransaction();
                            try {
                                $currentStmt = $pdo->prepare('SELECT * FROM events WHERE id = ? FOR UPDATE');
                                $currentStmt->execute([$id]);
                                $currentEvent = $currentStmt->fetch();

                                if (!$currentEvent) {
                                    $pdo->rollBack();
                                    cleanupNewEventUploads($newUploads);
                                    $message = 'Dit kalenderitem bestaat niet meer. Vernieuw de pagina en probeer opnieuw.';
                                } elseif (!is_string($submittedVersion) || !hash_equals(eventEditVersion($currentEvent), $submittedVersion)) {
                                    $pdo->rollBack();
                                    cleanupNewEventUploads($newUploads);
                                    audit_log($pdo, 'conflict', 'events', $id, 'path=agenda_edit; reason=stale_form', $currentUser);
                                    $message = 'Dit kalenderitem is sinds het openen gewijzigd door een andere beheerder. Je wijzigingen zijn niet opgeslagen. Herlaad het item om de nieuwste gegevens te bekijken en probeer daarna opnieuw.';
                                } else {
                                    $updatedEventValues = [
                                        'title' => $title,
                                        'date' => $date,
                                        'end_date' => $end_date,
                                        'time' => $time ?: null,
                                        'time_end' => $time_end ?: null,
                                        'description' => $description,
                                        'event_summary' => $eventSummary ?: null,
                                        'meer_info' => $meerInfo ?: null,
                                        'info_link' => $infoLink ?: null,
                                        'image' => $imageName,
                                        'event_gallery' => $galleryJson,
                                        'event_documents' => $documentsJson,
                                        'location' => $location ?: null,
                                        'hardware_request' => $hardwareRequest ?: null,
                                        'staff_present' => $staffPresent ?: null,
                                        'target_audience' => $targetAudience ?: null,
                                        'internal_notes' => $internalNotes ?: null,
                                        'show_signup_button' => $showSignupButton,
                                        'signup_embed' => $signupEmbed ?: null,
                                        'show_on_homepage' => $showOnHomepage,
                                    ];
                                    $changedFields = [];
                                    foreach ($updatedEventValues as $field => $value) {
                                        $previousValue = $currentEvent[$field] ?? null;
                                        if ($previousValue === null && ($value === null || $value === '')) {
                                            continue;
                                        }
                                        if ($value === null && $previousValue === '') {
                                            continue;
                                        }
                                        if ((string)$previousValue !== (string)$value) {
                                            $changedFields[] = $field;
                                        }
                                    }

                                    $stmt = $pdo->prepare('UPDATE events SET title=?, date=?, end_date=?, time=?, time_end=?, description=?, event_summary=?, meer_info=?, info_link=?, image=?, event_gallery=?, event_documents=?, location=?, hardware_request=?, staff_present=?, target_audience=?, internal_notes=?, show_signup_button=?, signup_embed=?, show_on_homepage=?, updated_at=NOW(), updated_by=? WHERE id=?');
                                    $stmt->execute([$title, $date, $end_date, $time ?: null, $time_end ?: null, $description, $eventSummary ?: null, $meerInfo ?: null, $infoLink ?: null, $imageName, $galleryJson, $documentsJson, $location ?: null, $hardwareRequest ?: null, $staffPresent ?: null, $targetAudience ?: null, $internalNotes ?: null, $showSignupButton, $signupEmbed ?: null, $showOnHomepage, $currentUser, $id]);
                                    audit_log($pdo, 'update', 'events', $id, 'path=agenda_edit; fields=' . implode(',', $changedFields), $currentUser);
                                    $pdo->commit();

                                    // Remove old files only after the database update commits.
                                    if (!empty($upload['name']) && $oldImage && file_exists(__DIR__ . '/uploads/' . $oldImage)) {
                                        unlink(__DIR__ . '/uploads/' . $oldImage);
                                    }
                                    if ($removeImage && $oldImage && file_exists(__DIR__ . '/uploads/' . $oldImage)) {
                                        unlink(__DIR__ . '/uploads/' . $oldImage);
                                    }
                                    foreach (array_merge($removeGallery, $removeDocuments) as $removedFile) {
                                        $removedPath = __DIR__ . '/uploads/' . $removedFile;
                                        if (is_file($removedPath)) {
                                            unlink($removedPath);
                                        }
                                    }
                                    header('Location: admin.php?page=agenda&ok=update');
                                    exit;
                                }
                            } catch (Exception $error) {
                                if ($pdo->inTransaction()) {
                                    $pdo->rollBack();
                                }
                                cleanupNewEventUploads($newUploads);
                                throw $error;
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'delete' && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        // haal image op en delete
        $stmt = $pdo->prepare('SELECT image, event_gallery, event_documents FROM events WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            if ($row['image'] && file_exists(__DIR__ . '/uploads/' . $row['image'])) {
                @unlink(__DIR__ . '/uploads/' . $row['image']);
            }
            foreach (decodeEventGallery($row['event_gallery'] ?? null) as $galleryFile) {
                $galleryPath = __DIR__ . '/uploads/' . $galleryFile;
                if (file_exists($galleryPath)) {
                    @unlink($galleryPath);
                }
            }
            foreach (decodeEventDocuments($row['event_documents'] ?? null) as $documentFile) {
                $documentPath = __DIR__ . '/uploads/' . $documentFile;
                if (file_exists($documentPath)) {
                    @unlink($documentPath);
                }
            }
            $stmt = $pdo->prepare('DELETE FROM events WHERE id = ?');
            $stmt->execute([$id]);
            // Audit log: event deleted
            audit_log($pdo, 'delete', 'events', $id, null, $currentUser);
            header('Location: admin.php?page=agenda&ok=delete');
            exit;
        } else {
            $message = 'Evenement niet gevonden.';
        }
    } elseif ($action === 'delete_bulk_events' && !empty($_POST['event_ids'])) {
        $ids = $_POST['event_ids'];
        if (!is_array($ids)) $ids = [$ids];
        $ids = array_map('intval', $ids);

        $deletedCount = 0;
        foreach ($ids as $id) {
            $stmt = $pdo->prepare('SELECT image, event_gallery, event_documents FROM events WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row && $row['image'] && file_exists(__DIR__ . '/uploads/' . $row['image'])) {
                @unlink(__DIR__ . '/uploads/' . $row['image']);
            }
            if ($row) {
                foreach (decodeEventGallery($row['event_gallery'] ?? null) as $galleryFile) {
                    $galleryPath = __DIR__ . '/uploads/' . $galleryFile;
                    if (file_exists($galleryPath)) {
                        @unlink($galleryPath);
                    }
                }
            }
            if ($row) {
                foreach (decodeEventDocuments($row['event_documents'] ?? null) as $documentFile) {
                    $documentPath = __DIR__ . '/uploads/' . $documentFile;
                    if (file_exists($documentPath)) {
                        @unlink($documentPath);
                    }
                }
            }
            $stmt = $pdo->prepare('DELETE FROM events WHERE id = ?');
            $result = $stmt->execute([$id]);
            if ($result) {
                $deletedCount++;
                audit_log($pdo, 'delete', 'events', $id, null, $currentUser);
            }
        }
        if ($deletedCount > 0) {
            header('Location: admin.php?page=agenda&ok=delete');
        } else {
            $message = 'Geen items verwijderd.';
        }
        exit;
    } elseif ($action === 'reorder_event') {
        $id = (int)($_POST['id'] ?? 0);
        $direction = $_POST['direction'] ?? '';

        if ($id && ($direction === 'up' || $direction === 'down')) {
            $pdo->beginTransaction();
            try {
                // Normaliseer sort_order als nodig
                $stmt = $pdo->prepare('SELECT id, sort_order, created_at FROM events ORDER BY created_at ASC, id ASC');
                $stmt->execute();
                $items = $stmt->fetchAll();

                $needsNormalize = false;
                foreach ($items as $item) {
                    if (empty($item['sort_order'])) {
                        $needsNormalize = true;
                        break;
                    }
                }

                if ($needsNormalize) {
                    $update = $pdo->prepare('UPDATE events SET sort_order = ? WHERE id = ?');
                    $pos = 1;
                    foreach ($items as $item) {
                        $update->execute([$pos, $item['id']]);
                        $pos++;
                    }
                }

                // Haal gesorteerde events op
                $stmt = $pdo->prepare('SELECT id, sort_order FROM events ORDER BY sort_order ASC, id ASC');
                $stmt->execute();
                $ordered = $stmt->fetchAll();

                // Zoek index van huidige event
                $index = null;
                foreach ($ordered as $i => $item) {
                    if ((int)$item['id'] === $id) {
                        $index = $i;
                        break;
                    }
                }

                // Wissel met vorige/volgende event
                if ($index !== null) {
                    $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
                    if (isset($ordered[$swapIndex])) {
                        $current = $ordered[$index];
                        $swap = $ordered[$swapIndex];
                        $update = $pdo->prepare('UPDATE events SET sort_order = ? WHERE id = ?');
                        $update->execute([$swap['sort_order'], $current['id']]);
                        $update->execute([$current['sort_order'], $swap['id']]);
                    }
                }

                audit_log($pdo, 'update', 'events', $id, 'reorder ' . $direction, $currentUser);
                $pdo->commit();
                header('Location: admin.php?page=agenda');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = 'Volgorde bijwerken mislukt.';
            }
        }
    } elseif ($action === 'delete_bulk_pages' && !empty($_POST['page_ids'])) {
        $ids = $_POST['page_ids'];
        if (!is_array($ids)) $ids = [$ids];
        $ids = array_map('intval', $ids);

        $deletedCount = 0;
        foreach ($ids as $id) {
            $stmt = $pdo->prepare('SELECT image FROM pages WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row && $row['image'] && file_exists(__DIR__ . '/uploads/' . $row['image'])) {
                @unlink(__DIR__ . '/uploads/' . $row['image']);
            }
            $stmt = $pdo->prepare('DELETE FROM pages WHERE id = ?');
            $result = $stmt->execute([$id]);
            if ($result) {
                $deletedCount++;
                audit_log($pdo, 'delete', 'pages', $id, null, $currentUser);
            }
        }
        if ($deletedCount > 0) {
            header('Location: admin.php?page=' . urlencode($_POST['page_key'] ?? 'agenda') . '&ok=delete');
        } else {
            $message = 'Geen items verwijderd.';
        }
        exit;
    } elseif ($action === 'update_banner') {
        $defaultBanner1 = 'images/banner_website_01.jpg';
        $defaultBanner2 = 'images/banner_website_02.jpg';
        
        // Handle banner 1
        $removeBanner1 = isset($_POST['remove_banner1']) ? 1 : 0;
        if ($removeBanner1) {
            if (strpos((string)$banner1, 'uploads/') === 0) {
                $oldPath = __DIR__ . '/' . $banner1;
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'banner1'")->execute([$defaultBanner1]);
            $banner1 = $defaultBanner1;
            audit_log($pdo, 'update', 'settings', null, 'banner1 reset to default', $currentUser);
        }

        $banner1_file = handleUpload('banner1');
        if (!isset($banner1_file['error']) && !empty($banner1_file['name'])) {
            $path = 'uploads/' . $banner1_file['name'];
            $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'banner1'")->execute([$path]);
            $banner1 = $path;
            audit_log($pdo, 'update', 'settings', null, 'banner1 set to ' . $path, $currentUser);
        }

        // Handle banner 2
        $removeBanner2 = isset($_POST['remove_banner2']) ? 1 : 0;
        if ($removeBanner2) {
            if (strpos((string)$banner2, 'uploads/') === 0) {
                $oldPath = __DIR__ . '/' . $banner2;
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'banner2'")->execute([$defaultBanner2]);
            $banner2 = $defaultBanner2;
            audit_log($pdo, 'update', 'settings', null, 'banner2 reset to default', $currentUser);
        }

        $banner2_file = handleUpload('banner2');
        if (!isset($banner2_file['error']) && !empty($banner2_file['name'])) {
            $path = 'uploads/' . $banner2_file['name'];
            $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'banner2'")->execute([$path]);
            $banner2 = $path;
            audit_log($pdo, 'update', 'settings', null, 'banner2 set to ' . $path, $currentUser);
        }

        // Handle banner 3
        $removeBanner3 = isset($_POST['remove_banner3']) ? 1 : 0;
        if ($removeBanner3) {
            if ($banner3 && strpos((string)$banner3, 'uploads/') === 0) {
                $oldPath = __DIR__ . '/' . $banner3;
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $pdo->prepare("DELETE FROM settings WHERE setting_key = 'banner3'")->execute();
            $banner3 = null;
            audit_log($pdo, 'update', 'settings', null, 'banner3 removed', $currentUser);
        }

        $banner3_file = handleUpload('banner3');
        if (!isset($banner3_file['error']) && !empty($banner3_file['name'])) {
            $path = 'uploads/' . $banner3_file['name'];
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('banner3', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$path, $path]);
            $banner3 = $path;
            audit_log($pdo, 'update', 'settings', null, 'banner3 set to ' . $path, $currentUser);
        }

        // Handle banner 4
        $removeBanner4 = isset($_POST['remove_banner4']) ? 1 : 0;
        if ($removeBanner4) {
            if ($banner4 && strpos((string)$banner4, 'uploads/') === 0) {
                $oldPath = __DIR__ . '/' . $banner4;
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $pdo->prepare("DELETE FROM settings WHERE setting_key = 'banner4'")->execute();
            $banner4 = null;
            audit_log($pdo, 'update', 'settings', null, 'banner4 removed', $currentUser);
        }

        $banner4_file = handleUpload('banner4');
        if (!isset($banner4_file['error']) && !empty($banner4_file['name'])) {
            $path = 'uploads/' . $banner4_file['name'];
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('banner4', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$path, $path]);
            $banner4 = $path;
            audit_log($pdo, 'update', 'settings', null, 'banner4 set to ' . $path, $currentUser);
        }

        $message = 'Banners bijgewerkt.';
    } elseif ($action === 'reset_banners') {
        $defaultBanner1 = 'images/banner_website_01.jpg';
        $defaultBanner2 = 'images/banner_website_02.jpg';
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'banner1'")->execute([$defaultBanner1]);
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'banner2'")->execute([$defaultBanner2]);
        $pdo->prepare("DELETE FROM settings WHERE setting_key = 'banner3'")->execute();
        $pdo->prepare("DELETE FROM settings WHERE setting_key = 'banner4'")->execute();
        audit_log($pdo, 'update', 'settings', null, 'banners reset to default', $currentUser);
        $banner1 = $defaultBanner1;
        $banner2 = $defaultBanner2;
        $banner3 = null;
        $banner4 = null;
        $message = 'Banners zijn teruggezet naar de standaard waarden.';
    } elseif ($action === 'create_user') {
        $newEmail = trim((string)($_POST['new_email'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');
        $newFirstName = trim((string)($_POST['new_first_name'] ?? ''));
        $newLastName = trim((string)($_POST['new_last_name'] ?? ''));
        $newRole = trim((string)($_POST['new_role'] ?? 'viewer'));
        $allowedRoles = ['administrator', 'content_manager', 'editor', 'viewer'];

        // Auto-set permissions based on role
        $newPermissions = permissionsForRole($newRole, $rolePermissions);

        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Vul een geldig e-mailadres in.';
        } elseif ($newPassword === '') {
            $message = 'Wachtwoord is verplicht.';
        } elseif (!in_array($newRole, $allowedRoles, true)) {
            $message = 'Ongeldige rol gekozen.';
        } else {
            $existsStmt = $pdo->prepare('SELECT id FROM accounts WHERE email = ?');
            $existsStmt->execute([$newEmail]);
            if ($existsStmt->fetch()) {
                $message = 'Er bestaat al een gebruiker met dit e-mailadres.';
            } else {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $adminFlag = !empty($newPermissions) ? 1 : 0;
                $permissionsJson = json_encode($newPermissions, JSON_UNESCAPED_SLASHES);
                $insertStmt = $pdo->prepare('INSERT INTO accounts (email, wachtwoord, first_name, last_name, admin, role, permissions) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $insertStmt->execute([
                    $newEmail,
                    $hashedPassword,
                    $newFirstName !== '' ? $newFirstName : null,
                    $newLastName !== '' ? $newLastName : null,
                    $adminFlag,
                    $newRole,
                    $permissionsJson,
                ]);

                audit_log($pdo, 'create', 'accounts', $newEmail, 'new user with role ' . $newRole . ' and permissions ' . implode(', ', $newPermissions), $currentUser);
                $message = 'Nieuwe gebruiker toegevoegd.';
            }
        }
    } elseif ($action === 'update_user_permissions') {
        $targetEmail = trim((string)($_POST['target_email'] ?? ''));
        $newRole = trim((string)($_POST['role'] ?? 'viewer'));
        $allowedRoles = ['administrator', 'content_manager', 'editor', 'viewer'];

        // Auto-set permissions based on role only
        $newPermissions = permissionsForRole($newRole, $rolePermissions);

        if ($targetEmail === '' || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Ongeldig e-mailadres.';
        } elseif (!in_array($newRole, $allowedRoles, true)) {
            $message = 'Ongeldige rol gekozen.';
        } else {
            $adminFlag = !empty($newPermissions) ? 1 : 0;
            $permissionsJson = json_encode($newPermissions, JSON_UNESCAPED_SLASHES);
            $stmt = $pdo->prepare('UPDATE accounts SET role = ?, admin = ?, permissions = ? WHERE email = ?');
            $stmt->execute([$newRole, $adminFlag, $permissionsJson, $targetEmail]);

            if ($targetEmail === ($_SESSION['email'] ?? '')) {
                $_SESSION['role'] = $newRole;
                $_SESSION['admin'] = $adminFlag;
                $_SESSION['permissions'] = $permissionsJson;
                $_SESSION['can_access_admin'] = !empty($newPermissions);
            }

            audit_log($pdo, 'update', 'accounts', $targetEmail, 'role set to ' . $newRole . ' with permissions ' . implode(', ', $newPermissions), $currentUser);
            $message = 'Gebruikersrol bijgewerkt.';
        }
    } elseif ($action === 'update_user') {
        $targetEmailOld = trim((string)($_POST['target_email_old'] ?? ''));
        $targetEmailNew = trim((string)($_POST['new_email'] ?? ''));
        $newFirstName = trim((string)($_POST['new_first_name'] ?? ''));
        $newLastName = trim((string)($_POST['new_last_name'] ?? ''));
        $newRole = trim((string)($_POST['new_role'] ?? 'viewer'));
        $newPassword = (string)($_POST['new_password'] ?? '');
        $allowedRoles = ['administrator', 'content_manager', 'editor', 'viewer'];

        // Auto-set permissions based on role only
        $newPermissions = permissionsForRole($newRole, $rolePermissions);

        if ($targetEmailOld === '' || !filter_var($targetEmailOld, FILTER_VALIDATE_EMAIL)) {
            $message = 'Ongeldig oud e-mailadres.';
        } elseif ($targetEmailNew === '' || !filter_var($targetEmailNew, FILTER_VALIDATE_EMAIL)) {
            $message = 'Ongeldig nieuw e-mailadres.';
        } elseif (!in_array($newRole, $allowedRoles, true)) {
            $message = 'Ongeldige rol gekozen.';
        } else {
            // Check if new email is already in use (but not by the user being edited)
            $existsStmt = $pdo->prepare('SELECT id FROM accounts WHERE email = ? AND email != ?');
            $existsStmt->execute([$targetEmailNew, $targetEmailOld]);
            if ($existsStmt->fetch()) {
                $message = 'Dit e-mailadres is al in gebruik.';
            } else {
                // If password is provided, hash it; otherwise keep the old one
                $updateFields = ['first_name' => $newFirstName !== '' ? $newFirstName : null, 'last_name' => $newLastName !== '' ? $newLastName : null, 'email' => $targetEmailNew, 'role' => $newRole, 'admin' => !empty($newPermissions) ? 1 : 0, 'permissions' => json_encode($newPermissions, JSON_UNESCAPED_SLASHES)];

                if ($newPassword !== '') {
                    $updateFields['wachtwoord'] = password_hash($newPassword, PASSWORD_DEFAULT);
                }

                // Build update query
                $updateClauses = [];
                $params = [];
                foreach ($updateFields as $field => $value) {
                    $updateClauses[] = $field . ' = ?';
                    $params[] = $value;
                }
                $params[] = $targetEmailOld; // WHERE clause

                $updateSql = 'UPDATE accounts SET ' . implode(', ', $updateClauses) . ' WHERE email = ?';
                $stmt = $pdo->prepare($updateSql);
                $stmt->execute($params);

                // Update session if editing own account
                if ($targetEmailOld === ($_SESSION['email'] ?? '')) {
                    $_SESSION['email'] = $targetEmailNew;
                    $_SESSION['first_name'] = $newFirstName;
                    $_SESSION['last_name'] = $newLastName;
                    $_SESSION['role'] = $newRole;
                    $_SESSION['admin'] = !empty($newPermissions) ? 1 : 0;
                    $_SESSION['permissions'] = json_encode($newPermissions, JSON_UNESCAPED_SLASHES);
                    $_SESSION['can_access_admin'] = !empty($newPermissions);
                }

                audit_log($pdo, 'update', 'accounts', $targetEmailOld, 'email changed to ' . $targetEmailNew . ', role set to ' . $newRole, $currentUser);
                $message = 'Gebruiker bijgewerkt.';
            }
        }
    } elseif ($action === 'delete_user') {
        $targetEmail = trim((string)($_POST['target_email'] ?? ''));

        if ($targetEmail === '' || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Ongeldig e-mailadres.';
        } elseif ($targetEmail === ($_SESSION['email'] ?? '')) {
            $message = 'Je kunt je eigen account niet verwijderen.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM accounts WHERE email = ?');
            $stmt->execute([$targetEmail]);
            audit_log($pdo, 'delete', 'accounts', $targetEmail, null, $currentUser);
            $message = 'Gebruiker verwijderd.';
        }
    } elseif ($action === 'optimize_image') {
        $imagePath = trim((string)($_POST['image_path'] ?? ''));

        // Validate the image path to prevent directory traversal
        $uploadDir = __DIR__ . '/uploads/';
        $fullPath = realpath($uploadDir . $imagePath);

        if (!$fullPath || strpos($fullPath, realpath($uploadDir)) !== 0) {
            $message = 'Ongeldig pad voor afbeelding.';
        } else if (!file_exists($fullPath)) {
            $message = 'Afbeelding niet gevonden.';
        } else {
            $quality = intval($_POST['quality'] ?? 85);
            $quality = max(60, min(95, $quality)); // Limit between 60-95

            $result = optimizeImage($fullPath, $quality);

            if (isset($result['error'])) {
                $message = $result['error'];
            } else {
                $saved_mb = number_format($result['saved_bytes'] / 1024 / 1024, 2, '.', '');
                $message = sprintf(
                    'Afbeelding geoptimaliseerd! %s MB bespaard (%d%%).',
                    $saved_mb,
                    $result['saved_percent']
                );
                $_SESSION['optimize_success'] = true;
                $_SESSION['optimize_data'] = $result;
            }
        }
    } elseif ($action === 'get_approval_item' && !empty($_POST['item_id'])) {
        // Return approval item as JSON - ALL fields
        header('Content-Type: application/json');
        $itemId = intval($_POST['item_id']);

        try {
            $stmt = $pdo->prepare(
                'SELECT id, title, date, end_date, time, time_end, description, location, image, info_link, event_gallery,
                        created_by, target_audience, internal_notes, approval_status, approval_feedback,
                    meer_info, event_summary, signup_embed, show_signup_button, show_on_homepage,
                    event_documents,
                    hardware_request, staff_present
                 FROM events WHERE id = ? AND approval_status = ?'
            );
            $stmt->execute([$itemId, 'pending']);
            $item = $stmt->fetch();

            if ($item) {
                $item['edit_version'] = eventEditVersion($item);
                echo json_encode([
                    'success' => true,
                    'item' => $item
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Item not found'
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    } elseif ($action === 'approve_with_review' && !empty($_POST['item_id'])) {
        // Approve with edits from modal - update ALL fields
        $itemId = intval($_POST['item_id']);
        $title = sanitizeEditorBlockInput($_POST['approval_title'] ?? '');
        $date = sanitizeEditorBlockInput($_POST['approval_date'] ?? '');
        $end_date = sanitizeEditorBlockInput($_POST['approval_end_date'] ?? '');
        $time = sanitizeEditorBlockInput($_POST['approval_time'] ?? '');
        $time_end = sanitizeEditorBlockInput($_POST['approval_time_end'] ?? '');
        $location = sanitizeEditorBlockInput($_POST['approval_location'] ?? '');
        $hardware_request = sanitizeEditorBlockInput($_POST['approval_hardware_request'] ?? '');
        $staff_present = sanitizeEditorBlockInput($_POST['approval_staff_present'] ?? '');
        $description = sanitizeEditorBlockInput($_POST['approval_description'] ?? '');
        $meer_info = sanitizeEditorBlockInput($_POST['approval_meer_info'] ?? '');
        $event_summary = sanitizeEditorBlockInput($_POST['approval_event_summary'] ?? '');
        $target_audience = sanitizeEditorBlockInput($_POST['approval_target_audience'] ?? '');
        $internal_notes = sanitizeEditorBlockInput($_POST['approval_internal_notes'] ?? '');
        $signup_embed = sanitizeEditorBlockInput($_POST['approval_signup_embed'] ?? '');
        $show_signup_button = !empty($_POST['approval_show_signup_button']) ? '1' : '0';
        $show_on_homepage = !empty($_POST['approval_show_on_homepage']) ? '1' : '0';
        $feedback = sanitizeEditorBlockInput($_POST['approval_feedback'] ?? '');

        try {
            $event = beginVersionCheckedEventUpdate(
                $pdo,
                $itemId,
                $_POST['approval_edit_version'] ?? '',
                'pending'
            );

            if ($event === false) {
                $message = 'Opslaan is geblokkeerd omdat veilige versiecontrole niet beschikbaar is. Neem contact op met de websitebeheerder.';
            } elseif (!$event) {
                audit_log($pdo, 'conflict', 'events', $itemId, 'path=approval_review; reason=stale_form', $currentUser);
                $message = 'Dit kalenderitem is gewijzigd, verwijderd of al beoordeeld. Herlaad de pagina voordat je het opnieuw bewerkt.';
            } else {
                // Update with edited data - ALL fields
                $stmt = $pdo->prepare(
                    'UPDATE events SET
                        title = ?, date = ?, end_date = ?, time = ?, time_end = ?,
                        location = ?, hardware_request = ?, staff_present = ?, description = ?, meer_info = ?, event_summary = ?,
                        target_audience = ?, internal_notes = ?, signup_embed = ?,
                        show_signup_button = ?, show_on_homepage = ?,
                        approval_status = ?, approved_by = ?, approval_feedback = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $title,
                    $date,
                    $end_date ?? null,
                    $time ?? null,
                    $time_end ?? null,
                    $location,
                    $hardware_request ?: null,
                    $staff_present ?: null,
                    $description,
                    $meer_info ?? null,
                    $event_summary ?? null,
                    $target_audience ?? null,
                    $internal_notes ?? null,
                    $signup_embed ?? null,
                    $show_signup_button,
                    $show_on_homepage,
                    'approved',
                    $currentUser,
                    $feedback ?: null,
                    $itemId
                ]);
                audit_log($pdo, 'approve', 'events', $itemId, 'path=approval_review; status=approved; fields=event_content', $currentUser);
                $pdo->commit();
                header('Location: admin.php?page=goedkeuren&ok=approve');
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = 'Fout bij goedkeuren: ' . $e->getMessage();
        }
    } elseif ($action === 'approve_item' && !empty($_POST['item_id'])) {
        $itemId = intval($_POST['item_id']);
        $stmt = $pdo->prepare('SELECT id FROM events WHERE id = ?');
        $stmt->execute([$itemId]);
        $event = $stmt->fetch();

        if ($event) {
            $stmt = $pdo->prepare(
                'UPDATE events SET approval_status = ?, approved_by = ?, approval_feedback = NULL 
                 WHERE id = ?'
            );
            $stmt->execute(['approved', $currentUser, $itemId]);
            audit_log($pdo, 'approve', 'events', $itemId, 'Status changed to approved', $currentUser);
            header('Location: admin.php?page=goedkeuren&ok=approve');
            exit;
        } else {
            $message = 'Item niet gevonden.';
        }
    } elseif ($action === 'reject_item' && !empty($_POST['item_id'])) {
        // Reject with possible edits - also save edited fields for resubmission
        $itemId = intval($_POST['item_id']);
        $title = sanitizeEditorBlockInput($_POST['approval_title'] ?? '');
        $date = sanitizeEditorBlockInput($_POST['approval_date'] ?? '');
        $end_date = sanitizeEditorBlockInput($_POST['approval_end_date'] ?? '');
        $time = sanitizeEditorBlockInput($_POST['approval_time'] ?? '');
        $time_end = sanitizeEditorBlockInput($_POST['approval_time_end'] ?? '');
        $location = sanitizeEditorBlockInput($_POST['approval_location'] ?? '');
        $hardware_request = sanitizeEditorBlockInput($_POST['approval_hardware_request'] ?? '');
        $staff_present = sanitizeEditorBlockInput($_POST['approval_staff_present'] ?? '');
        $description = sanitizeEditorBlockInput($_POST['approval_description'] ?? '');
        $meer_info = sanitizeEditorBlockInput($_POST['approval_meer_info'] ?? '');
        $event_summary = sanitizeEditorBlockInput($_POST['approval_event_summary'] ?? '');
        $target_audience = sanitizeEditorBlockInput($_POST['approval_target_audience'] ?? '');
        $internal_notes = sanitizeEditorBlockInput($_POST['approval_internal_notes'] ?? '');
        $signup_embed = sanitizeEditorBlockInput($_POST['approval_signup_embed'] ?? '');
        $show_signup_button = !empty($_POST['approval_show_signup_button']) ? '1' : '0';
        $show_on_homepage = !empty($_POST['approval_show_on_homepage']) ? '1' : '0';
        $feedback = sanitizeEditorBlockInput($_POST['approval_feedback'] ?? '');

        try {
            $event = beginVersionCheckedEventUpdate(
                $pdo,
                $itemId,
                $_POST['approval_edit_version'] ?? '',
                'pending'
            );

            if ($event === false) {
                $message = 'Opslaan is geblokkeerd omdat veilige versiecontrole niet beschikbaar is. Neem contact op met de websitebeheerder.';
            } elseif (!$event) {
                audit_log($pdo, 'conflict', 'events', $itemId, 'path=approval_reject; reason=stale_form', $currentUser);
                $message = 'Dit kalenderitem is gewijzigd, verwijderd of al beoordeeld. Herlaad de pagina voordat je het opnieuw bewerkt.';
            } else {
                // Update status to rejected, but save edited fields for onderzoeker to see edits + feedback
                $stmt = $pdo->prepare(
                    'UPDATE events SET 
                        title = ?, date = ?, end_date = ?, time = ?, time_end = ?, 
                        location = ?, hardware_request = ?, staff_present = ?, description = ?, meer_info = ?, event_summary = ?,
                        target_audience = ?, internal_notes = ?, signup_embed = ?,
                        show_signup_button = ?, show_on_homepage = ?,
                        approval_status = ?, approved_by = ?, approval_feedback = ? 
                     WHERE id = ?'
                );
                $stmt->execute([
                    $title,
                    $date,
                    $end_date ?: null,
                    $time ?: null,
                    $time_end ?: null,
                    $location,
                    $hardware_request ?: null,
                    $staff_present ?: null,
                    $description,
                    $meer_info ?: null,
                    $event_summary ?: null,
                    $target_audience ?: null,
                    $internal_notes ?: null,
                    $signup_embed ?: null,
                    $show_signup_button,
                    $show_on_homepage,
                    'rejected',
                    $currentUser,
                    $feedback,
                    $itemId
                ]);
                audit_log($pdo, 'reject', 'events', $itemId, 'path=approval_reject; status=rejected; fields=event_content', $currentUser);
                $pdo->commit();
                
                // Send rejection email to requester
                if (!empty($event['created_by'])) {
                    require_once 'email_config.php';
                    $emailBody = "Hallo,\n\nJe aanvraag voor het event \"" . htmlspecialchars($event['title']) . "\" is helaas afgewezen.\n\n" .
                                "Feedback van de review team:\n" . $feedback . "\n\n" .
                                "Je kunt je aanvraag aanpassen en opnieuw indienen.\n\n" .
                                "Met vriendelijke groet,\nSociaalAI Lab Team";
                    sendEmail($event['created_by'], "Aanvraag afgewezen: " . $event['title'], $emailBody);
                }
                
                header('Location: admin.php?page=goedkeuren&ok=reject');
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = 'Fout bij afkeuren: ' . $e->getMessage();
        }
    } elseif ($action === 'get_request_details' && !empty($_POST['request_id'])) {
        // Return request details as JSON for editing - ALL fields including feedback
        header('Content-Type: application/json');
        $requestId = intval($_POST['request_id']);

        try {
            $stmt = $pdo->prepare(
                'SELECT id, title, date, end_date, time, time_end, description, location, image, info_link, event_gallery,
                        approval_status, approval_feedback, meer_info, event_summary, target_audience,
                    internal_notes, signup_embed, show_signup_button, show_on_homepage,
                    event_documents,
                    hardware_request, staff_present
                 FROM events WHERE id = ? AND created_by = ?'
            );
            $stmt->execute([$requestId, $currentUser]);
            $item = $stmt->fetch();

            if ($item) {
                $item['edit_version'] = eventEditVersion($item);
                echo json_encode([
                    'success' => true,
                    'item' => $item
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Aanvraag niet gevonden'
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    } elseif ($action === 'resubmit_request' && !empty($_POST['request_id'])) {
        // Handle resubmission of rejected request - update ALL fields
        $requestId = intval($_POST['request_id']);
        $title = sanitizeEditorBlockInput($_POST['edit_title'] ?? '');
        $date = sanitizeEditorBlockInput($_POST['edit_date'] ?? '');
        $end_date = sanitizeEditorBlockInput($_POST['edit_end_date'] ?? '');
        $time = sanitizeEditorBlockInput($_POST['edit_time'] ?? '');
        $time_end = sanitizeEditorBlockInput($_POST['edit_time_end'] ?? '');
        $location = sanitizeEditorBlockInput($_POST['edit_location'] ?? '');
        $hardware_request = sanitizeEditorBlockInput($_POST['edit_hardware_request'] ?? '');
        $staff_present = sanitizeEditorBlockInput($_POST['edit_staff_present'] ?? '');
        $description = sanitizeEditorBlockInput($_POST['edit_description'] ?? '');
        $meer_info = sanitizeEditorBlockInput($_POST['edit_meer_info'] ?? '');
        $event_summary = sanitizeEditorBlockInput($_POST['edit_event_summary'] ?? '');
        $target_audience = sanitizeEditorBlockInput($_POST['edit_target_audience'] ?? '');
        $internal_notes = sanitizeEditorBlockInput($_POST['edit_internal_notes'] ?? '');
        $signup_embed = sanitizeEditorBlockInput($_POST['edit_signup_embed'] ?? '');
        $show_signup_button = !empty($_POST['edit_show_signup_button']) ? '1' : '0';
        $show_on_homepage = !empty($_POST['edit_show_on_homepage']) ? '1' : '0';

        // Verify ownership
        $stmt = $pdo->prepare('SELECT id, event_documents FROM events WHERE id = ? AND created_by = ?');
        $stmt->execute([$requestId, $currentUser]);
        $event = $stmt->fetch();

        if ($event) {
            try {
                $oldDocuments = decodeEventDocuments($event['event_documents'] ?? null);
                $removeDocuments = $_POST['edit_remove_event_documents'] ?? [];
                if (!is_array($removeDocuments)) {
                    $removeDocuments = [];
                }
                $removeDocuments = array_values(array_intersect($oldDocuments, array_map('strval', $removeDocuments)));
                $keptDocuments = array_values(array_diff($oldDocuments, $removeDocuments));

                $documentUpload = handleDocumentUpload('edit_event_documents');
                if (isset($documentUpload['error'])) {
                    $message = $documentUpload['error'];
                } else {
                    $documentNames = array_values(array_merge($keptDocuments, $documentUpload['names'] ?? []));
                    $documentsJson = !empty($documentNames)
                        ? json_encode($documentNames, JSON_UNESCAPED_SLASHES)
                        : null;

                    $event = beginVersionCheckedEventUpdate(
                        $pdo,
                        $requestId,
                        $_POST['edit_version'] ?? '',
                        'rejected',
                        $currentUser
                    );
                    if ($event === false) {
                        cleanupNewEventUploads($documentUpload['names'] ?? []);
                        $message = 'Opnieuw indienen is geblokkeerd omdat veilige versiecontrole niet beschikbaar is. Neem contact op met de websitebeheerder.';
                    } elseif (!$event) {
                        cleanupNewEventUploads($documentUpload['names'] ?? []);
                        audit_log($pdo, 'conflict', 'events', $requestId, 'path=request_resubmit; reason=stale_form', $currentUser);
                        $message = 'Deze aanvraag is sinds het openen gewijzigd. Je wijzigingen zijn niet opgeslagen. Herlaad de aanvraag en probeer opnieuw.';
                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE events SET
                                title = ?, date = ?, end_date = ?, time = ?, time_end = ?,
                                location = ?, hardware_request = ?, staff_present = ?, description = ?, meer_info = ?, event_summary = ?,
                                target_audience = ?, internal_notes = ?, signup_embed = ?, event_documents = ?,
                                show_signup_button = ?, show_on_homepage = ?,
                                approval_status = "pending", approved_by = NULL, approval_feedback = NULL
                             WHERE id = ?'
                        );
                        $stmt->execute([
                            $title, $date, $end_date ?: null, $time ?: null, $time_end ?: null,
                            $location, $hardware_request ?: null, $staff_present ?: null, $description, $meer_info ?: null, $event_summary ?: null,
                            $target_audience ?: null, $internal_notes ?: null, $signup_embed ?: null, $documentsJson,
                            $show_signup_button, $show_on_homepage,
                            $requestId
                        ]);
                        audit_log($pdo, 'resubmit', 'events', $requestId, 'path=request_resubmit; status=pending; fields=event_content', $currentUser);
                        $pdo->commit();
                        foreach ($removeDocuments as $documentFile) {
                            $documentPath = __DIR__ . '/uploads/' . $documentFile;
                            if (is_file($documentPath)) {
                                unlink($documentPath);
                            }
                        }
                        header('Location: admin.php?page=aanvragen&ok=resubmit');
                        exit;
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = 'Fout bij opnieuw indienen: ' . $e->getMessage();
            }
        } else {
            $message = 'Aanvraag niet gevonden of geen toestemming.';
        }
    }
}

// Handle page management (create_page, update_page, delete_page)
$pageAction = $_POST['page_action'] ?? '';
$pageActionPermissionMap = [
    'create_page' => 'manage_pages',
    'update_page' => 'manage_pages',
    'reorder_page' => 'manage_pages',
    'delete_page' => 'delete_pages',
];
if ($pageAction !== '' && isset($pageActionPermissionMap[$pageAction]) && !$hasPermission($pageActionPermissionMap[$pageAction])) {
    $message = 'Je hebt geen rechten om deze pagina-actie uit te voeren.';
    $pageAction = '';
}
if ($pageAction === 'create_page') {
    $pageKey = $_POST['page_key'] ?? '';
    $title = sanitizeEditorInlineInput($_POST['title'] ?? '');
    $body = sanitizeEditorBlockInput($_POST['body'] ?? '');
    $greenText = sanitizeEditorPlainText($_POST['green_text'] ?? '');
    $greenTextPosition = $_POST['green_text_position'] ?? 'above';
    if (!in_array($greenTextPosition, ['above', 'below'], true)) {
        $greenTextPosition = 'above';
    }
    $insertPosition = $_POST['insert_position'] ?? 'bottom';
    $imagePosition = $_POST['image_position'] ?? 'normal';
    if (!in_array($imagePosition, ['normal', 'left', 'right'], true)) {
        $imagePosition = 'normal';
    }

    // Optional URL used by cards/pages that need click-through behavior.
    $infoLink = $_POST['info_link'] ?? '';

    if ($title === '') {
        $title = ' ';
    }

    if (empty($pageKey)) {
        $message = 'Page key is verplicht.';
    } else {
        $layout = $_POST['layout'] ?? 'custom';
        if (!in_array($layout, ['welcome', 'card', 'info', 'contact', 'custom'], true)) {
            $layout = 'custom';
        }
        $meta = [
            'layout' => $layout,
            'image_position' => $imagePosition,
        ];
        if ($greenText !== '') {
            $meta['green_text'] = $greenText;
            $meta['green_text_position'] = $greenTextPosition;
        }

        if ($infoLink !== '') {
            $meta['info_link'] = $infoLink;
        }

        $roleField = trim((string)($_POST['role'] ?? ''));
        if ($roleField !== '') {
            $meta['role'] = $roleField;
        }

        // Extra velden voor contact pagina
        if ($pageKey === 'contact') {
            if (!empty($_POST['address'])) $meta['address'] = $_POST['address'];
            if (!empty($_POST['email'])) $meta['email'] = $_POST['email'];
            if (!empty($_POST['map_embed'])) $meta['map_embed'] = $_POST['map_embed'];
        }

        // Change 'page_image' to 'image' to match the form field name
        $upload = handleUpload('image');
        $imageName = $upload['name'] ?? null;

        if ($insertPosition === 'top') {
            $shiftStmt = $pdo->prepare('UPDATE pages SET sort_order = COALESCE(sort_order, 0) + 1 WHERE page_key = ?');
            $shiftStmt->execute([$pageKey]);
            $nextOrder = 1;
        } else {
            $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM pages WHERE page_key = ?');
            $maxStmt->execute([$pageKey]);
            $nextOrder = (int)$maxStmt->fetchColumn() + 1;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO pages (page_key, title, body, image, meta, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );

        $stmt->execute([
            $pageKey,
            $title,
            $body,
            $imageName,
            json_encode($meta),
            $nextOrder
        ]);

        audit_log($pdo, 'create', 'pages', $pdo->lastInsertId(), 'page_key: ' . $pageKey, $currentUser);

        header('Location: admin.php?page=' . urlencode($pageKey) . '&ok=create');
        exit;
    }
} elseif ($pageAction === 'update_page') {

    $id = (int)($_POST['id'] ?? 0);
    $pageKey = $_POST['page_key'] ?? '';
    $title = sanitizeEditorInlineInput($_POST['title'] ?? '');
    $body = sanitizeEditorBlockInput($_POST['body'] ?? '');
    $greenText = sanitizeEditorPlainText($_POST['green_text'] ?? '');
    $greenTextPosition = $_POST['green_text_position'] ?? 'above';
    if (!in_array($greenTextPosition, ['above', 'below'], true)) {
        $greenTextPosition = 'above';
    }
    $removeImage = isset($_POST['remove_image']) ? 1 : 0;

    if ($title === '') {
        $title = ' ';
    }

    if ($id) {

        $stmt = $pdo->prepare('SELECT image, meta FROM pages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $oldImage = $row ? $row['image'] : null;
        $imagePosition = $_POST['image_position'] ?? 'normal';
        if (!in_array($imagePosition, ['normal', 'left', 'right'], true)) {
            $imagePosition = 'normal';
        }

        $layout = $_POST['layout'] ?? 'custom';
        if (!in_array($layout, ['welcome', 'card', 'info', 'contact', 'custom'], true)) {
            $layout = 'custom';
        }
        $meta = [];
        if (!empty($row['meta'])) {
            $decodedMeta = json_decode($row['meta'], true);
            if (is_array($decodedMeta)) {
                $meta = $decodedMeta;
            }
        }
        $meta['layout'] = $layout;
        $meta['image_position'] = $imagePosition;
        if ($greenText !== '') {
            $meta['green_text'] = $greenText;
            $meta['green_text_position'] = $greenTextPosition;
        } else {
            unset($meta['green_text']);
            unset($meta['green_text_position']);
        }

        // Optional URL used by cards/pages that need click-through behavior.
        $infoLink = $_POST['info_link'] ?? '';
        if ($infoLink !== '') {
            $meta['info_link'] = $infoLink;
        } else {
            unset($meta['info_link']);
        }

        $roleField = trim((string)($_POST['role'] ?? ''));
        if ($roleField !== '') {
            $meta['role'] = $roleField;
        } else {
            unset($meta['role']);
        }

        if ($pageKey === 'contact') {
            if (!empty($_POST['address'])) $meta['address'] = $_POST['address'];
            if (!empty($_POST['email'])) $meta['email'] = $_POST['email'];
            if (!empty($_POST['map_embed'])) $meta['map_embed'] = $_POST['map_embed'];
        }

        // Change 'page_image' to 'image' to match the form field name
        $upload = handleUpload('image');
        if ($removeImage) {
            $imageName = null;
        } else {
            $imageName = $upload['name'] ?? $oldImage;
        }

        $stmt = $pdo->prepare(
            'UPDATE pages
             SET title=?, body=?, image=?, meta=?, updated_at=NOW()
             WHERE id=?'
        );

        $stmt->execute([
            $title,
            $body,
            $imageName,
            json_encode($meta),
            $id
        ]);

        if (!empty($upload['name']) && $oldImage && file_exists(__DIR__ . '/uploads/' . $oldImage)) {
            @unlink(__DIR__ . '/uploads/' . $oldImage);
        }
        if ($removeImage && $oldImage && file_exists(__DIR__ . '/uploads/' . $oldImage)) {
            @unlink(__DIR__ . '/uploads/' . $oldImage);
        }

        audit_log($pdo, 'update', 'pages', $id, 'page_key: ' . $pageKey, $currentUser);

        header('Location: admin.php?page=' . urlencode($pageKey) . '&ok=update');
        exit;
    }
} elseif ($pageAction === 'reorder_page') {

    $id = (int)($_POST['id'] ?? 0);
    $pageKey = $_POST['page_key'] ?? '';
    $direction = $_POST['direction'] ?? '';

    if ($id && $pageKey && ($direction === 'up' || $direction === 'down')) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, sort_order, created_at FROM pages WHERE page_key = ? ORDER BY created_at ASC, id ASC');
            $stmt->execute([$pageKey]);
            $items = $stmt->fetchAll();

            $needsNormalize = false;
            foreach ($items as $item) {
                if (empty($item['sort_order'])) {
                    $needsNormalize = true;
                    break;
                }
            }

            if ($needsNormalize) {
                $update = $pdo->prepare('UPDATE pages SET sort_order = ? WHERE id = ?');
                $pos = 1;
                foreach ($items as $item) {
                    $update->execute([$pos, $item['id']]);
                    $pos++;
                }
            }

            $stmt = $pdo->prepare('SELECT id, sort_order FROM pages WHERE page_key = ? ORDER BY sort_order ASC, id ASC');
            $stmt->execute([$pageKey]);
            $ordered = $stmt->fetchAll();

            $index = null;
            foreach ($ordered as $i => $item) {
                if ((int)$item['id'] === $id) {
                    $index = $i;
                    break;
                }
            }

            if ($index !== null) {
                $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
                if (isset($ordered[$swapIndex])) {
                    $current = $ordered[$index];
                    $swap = $ordered[$swapIndex];
                    $update = $pdo->prepare('UPDATE pages SET sort_order = ? WHERE id = ?');
                    $update->execute([$swap['sort_order'], $current['id']]);
                    $update->execute([$current['sort_order'], $swap['id']]);
                }
            }

            audit_log($pdo, 'update', 'pages', $id, 'reorder ' . $pageKey . ' ' . $direction, $currentUser);
            $pdo->commit();
            header('Location: admin.php?page=' . urlencode($pageKey));
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = 'Volgorde bijwerken mislukt.';
        }
    }
} elseif ($pageAction === 'delete_page') {

    $id = (int)($_POST['id'] ?? 0);

    if ($id) {

        $stmt = $pdo->prepare('SELECT image FROM pages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if ($row && $row['image'] && file_exists(__DIR__ . '/uploads/' . $row['image'])) {
            @unlink(__DIR__ . '/uploads/' . $row['image']);
        }

        $stmt = $pdo->prepare('DELETE FROM pages WHERE id = ?');
        $stmt->execute([$id]);

        audit_log($pdo, 'delete', 'pages', $id, null, $currentUser);

        $pageKey = $_POST['page_key'] ?? 'index';
        header('Location: admin.php?page=' . urlencode($pageKey) . '&ok=delete');
        exit;
    }
}

// Edit modus
$editEvent = null;
if (!empty($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$id]);
    $editEvent = $stmt->fetch();
    if ($editEvent) {
        $editEvent['edit_version'] = eventEditVersion($editEvent);
    }
}

// Edit page mode
$editPage = null;
$editPageInfoLink = '';
if (!empty($_GET['edit_page'])) {
    $id = (int)$_GET['edit_page'];
    $stmt = $pdo->prepare('SELECT * FROM pages WHERE id = ?');
    $stmt->execute([$id]);
    $editPage = $stmt->fetch();
    if ($editPage && $editPage['page_key'] === 'terugblikken') {
        $metaArr = $editPage['meta'] ? json_decode($editPage['meta'], true) : [];
        $editPageInfoLink = $metaArr['info_link'] ?? '';
    }
}

// Haal events voor overzicht (datum-gestuurd)
// Toekomstige events eerst (gesorteerd op datum), daarna voorbije events
$stmt = $pdo->prepare('SELECT * FROM events ORDER BY CASE WHEN date >= CURDATE() THEN 0 ELSE 1 END, date ASC, time ASC, created_at ASC, id ASC');
$stmt->execute();
$events = $stmt->fetchAll();

// Shared hardware catalog used for booking-like hardware selection in event forms
$eventHardwareOptions = [
    ['id' => 1, 'name' => 'PC (Workstation 9950X3D RTX5090 96GB RAM)', 'quantity' => 1],
    ['id' => 2, 'name' => 'PC (Framework Max+ 395 128GB)', 'quantity' => 4],
    ['id' => 3, 'name' => 'Robohond Unitree Go2 X', 'quantity' => 1],
    ['id' => 4, 'name' => 'Robohond Unitree Go2 Pro', 'quantity' => 1],
    ['id' => 5, 'name' => 'Tablet 8,7" Samsung Galaxy Tab A11', 'quantity' => 1],
    ['id' => 6, 'name' => 'Tablet 11" Samsung Galaxy Tab A11', 'quantity' => 1],
    ['id' => 7, 'name' => 'VR bril Oculus Quest 3 512GB', 'quantity' => 2],
    ['id' => 8, 'name' => 'accu+zonnepaneel', 'quantity' => 1],
    ['id' => 9, 'name' => 'labkar buiten', 'quantity' => 1],
    ['id' => 10, 'name' => 'Wijkbot kar + afstandbediening', 'quantity' => 1],
    ['id' => 11, 'name' => 'labkar binnen', 'quantity' => 3],
    ['id' => 12, 'name' => 'speaker/microfoon Jabra Speak 2 75', 'quantity' => 1],
    ['id' => 13, 'name' => 'speaker/microfoon Jabra Speak 2 55', 'quantity' => 2],
    ['id' => 14, 'name' => 'Draadloze microfoon set van 2', 'quantity' => 1],
    ['id' => 15, 'name' => 'WiFi Router ASUS TUF BE9400', 'quantity' => 1],
    ['id' => 16, 'name' => 'laptop + muis + AC adapter', 'quantity' => 10],
    ['id' => 17, 'name' => 'toetsenbord', 'quantity' => 3],
    ['id' => 18, 'name' => 'muis', 'quantity' => 3],
    ['id' => 19, 'name' => 'Raspberry Pi + AC adapter + HDMI kabel', 'quantity' => 6],
    ['id' => 20, 'name' => 'Scherm 50" TCL 50Q6C', 'quantity' => 4],
    ['id' => 21, 'name' => 'Scherm 27" Philips 27E2N2500 + beugel', 'quantity' => 6],
    ['id' => 22, 'name' => 'Kensington Combinatie Ultra Laptop Slot x10', 'quantity' => 10],
    ['id' => 23, 'name' => 'HDMI 8k kabel 2m', 'quantity' => 6],
    ['id' => 24, 'name' => 'HDMI 8k kabel 5m', 'quantity' => 1],
    ['id' => 25, 'name' => 'Banner Sociaalailab', 'quantity' => 1],
    ['id' => 26, 'name' => 'Verwijsstandaard Sociaalailab', 'quantity' => 2],
];
?>
<?php
// Welke admin pagina tonen (standaard dashboard)
$page = $_GET['page'] ?? 'dashboard';

$allowedPagesByPermission = [
    'banner' => 'manage_banners',
    'agenda' => ['manage_events', 'delete_events', 'create_events'],
    'goedkeuren' => 'approve_content',
    'audit' => 'view_audit',
    'users' => ['create_users', 'edit_users', 'delete_users'],
    'index' => ['manage_pages', 'delete_pages', 'view_pages'],
    'evenementen' => ['manage_pages', 'delete_pages', 'view_pages'],
    'terugblikken' => ['manage_pages', 'delete_pages', 'view_pages'],
    'over' => ['manage_pages', 'delete_pages', 'view_pages'],
    'wie-zijn-we' => ['manage_pages', 'delete_pages', 'view_pages'],
    'ons-team' => ['manage_pages', 'delete_pages', 'view_pages'],
    'verantwoord-ai' => ['manage_pages', 'delete_pages', 'view_pages'],
    'contact' => ['manage_pages', 'delete_pages', 'view_pages'],
    'programma-kennis' => ['manage_pages', 'delete_pages', 'view_pages'],
    'programma-actie' => ['manage_pages', 'delete_pages', 'view_pages'],
    'programma-faciliteit' => ['manage_pages', 'delete_pages', 'view_pages'],
];

$canViewPage = function ($candidatePage) use (&$allowedPagesByPermission, &$hasPermission, &$hasAnyPermission) {
    if (!isset($allowedPagesByPermission[$candidatePage])) {
        return true;
    }

    $candidatePermission = $allowedPagesByPermission[$candidatePage];
    if (is_array($candidatePermission) ? $hasAnyPermission($candidatePermission) : $hasPermission($candidatePermission)) {
        return true;
    }

    return false;
};

if (!$canViewPage($page)) {
    $fallbackPage = null;
    foreach ($allowedPagesByPermission as $candidatePage => $candidatePermission) {
        if ($canViewPage($candidatePage)) {
            $fallbackPage = $candidatePage;
            break;
        }
    }

    if ($fallbackPage === null) {
        $message = 'Je account heeft momenteel geen toegewezen beheerrechten.';
    } else {
        $page = $fallbackPage;
        $message = 'Je hebt geen toegang tot de gevraagde beheersectie.';
    }
}

$canDeleteEvents = $hasPermission('delete_events');
$canDeletePages = $hasPermission('delete_pages');
$canManageEvents = $hasPermission('manage_events');
$canCreateEvents = $hasPermission('create_events');
$canManagePages = $hasPermission('manage_pages');
$isEditorReadOnlyPage = false;

$pageItems = [];
if ($page !== 'banner' && $page !== 'agenda' && $page !== 'goedkeuren' && $page !== 'audit' && $page !== 'users') {
    $stmt = $pdo->prepare(
        'SELECT * FROM pages WHERE page_key = ?
         ORDER BY (sort_order IS NULL OR sort_order = 0) ASC, sort_order ASC, created_at ASC, id ASC'
    );
    $stmt->execute([$page]);
    $pageItems = $stmt->fetchAll();
}

$auditLogs = [];
$auditActions = [];
$auditTables = [];
$auditTotal = 0;
$auditPage = max(1, (int)($_GET['audit_page'] ?? 1));
$auditPerPageOptions = [25, 50, 100, 200];
$auditPerPage = (int)($_GET['audit_per_page'] ?? 50);
if (!in_array($auditPerPage, $auditPerPageOptions, true)) {
    $auditPerPage = 50;
}
$auditTotalPages = 1;
$auditActionFilter = trim((string)($_GET['audit_action'] ?? ''));
$auditTableFilter = trim((string)($_GET['audit_table'] ?? ''));
$userAccounts = [];

if ($page === 'audit') {
    try {
        $auditActions = $pdo->query("SELECT DISTINCT action FROM audit_logs WHERE action IS NOT NULL AND action <> '' ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
        $auditTables = $pdo->query("SELECT DISTINCT table_name FROM audit_logs WHERE table_name IS NOT NULL AND table_name <> '' ORDER BY table_name ASC")->fetchAll(PDO::FETCH_COLUMN);

        $whereParts = [];
        $params = [];

        if ($auditActionFilter !== '') {
            $whereParts[] = 'action = ?';
            $params[] = $auditActionFilter;
        }
        if ($auditTableFilter !== '') {
            $whereParts[] = 'table_name = ?';
            $params[] = $auditTableFilter;
        }

        $whereSql = $whereParts ? (' WHERE ' . implode(' AND ', $whereParts)) : '';

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs' . $whereSql);
        $countStmt->execute($params);
        $auditTotal = (int)$countStmt->fetchColumn();
        $auditTotalPages = max(1, (int)ceil($auditTotal / $auditPerPage));
        if ($auditPage > $auditTotalPages) {
            $auditPage = $auditTotalPages;
        }
        $offset = ($auditPage - 1) * $auditPerPage;

        $listSql = 'SELECT id, action, table_name, record_id, details, performed_by, created_at FROM audit_logs'
            . $whereSql
            . ' ORDER BY created_at DESC, id DESC'
            . ' LIMIT ' . (int)$auditPerPage . ' OFFSET ' . (int)$offset;

        $listStmt = $pdo->prepare($listSql);
        $listStmt->execute($params);
        $auditLogs = $listStmt->fetchAll();
    } catch (Exception $e) {
        $message = 'Auditlogboek kon niet worden geladen.';
    }
}

if ($page === 'users') {
    try {
        $stmt = $pdo->query('SELECT email, first_name, last_name, admin, role, permissions FROM accounts ORDER BY email ASC');
        $userAccounts = $stmt->fetchAll();
    } catch (Exception $e) {
        $message = 'Gebruikers konden niet worden geladen.';
    }
}
?>
<!doctype html>
<html lang="nl">

<head>
    <script src="admin-upload-widget.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin - SociaalAI Lab</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__ . '/style.css'); ?>">
    <link rel="stylesheet" href="admin-styles.css?v=<?php echo filemtime(__DIR__ . '/admin-styles.css'); ?>">
    <link rel="stylesheet" href="ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="icon" type="image/png" href="images/Pixels_icon.png">
    <script src="custom.js"></script>
    <!-- CKEditor 5 CDN (FREE, UNLIMITED) -->
    <script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js"></script>
    <style>
        .btn-readonly-disabled {
            opacity: 0.55;
            cursor: not-allowed !important;
            pointer-events: none !important;
            filter: grayscale(0.2);
        }

        /* Approval modal lock: keep modal centered and block nav/sidebar interaction */
        body.approval-modal-open {
            overflow: hidden;
        }

        body.approval-modal-open .admin-header,
        body.approval-modal-open .sidebar,
        body.approval-modal-open .sidebar-toggle-btn {
            pointer-events: none !important;
            user-select: none !important;
        }

        #approval-modal {
            position: fixed !important;
            inset: 0 !important;
            z-index: 20000 !important;
            align-items: center !important;
            justify-content: center !important;
            pointer-events: auto !important;
        }

        #approval-modal>div {
            margin: 0 auto;
            transform-origin: center center;
        }

        #approval-modal .ck-editor__editable {
            min-height: 120px;
        }

        .ck-editor__editable {
            min-height: 100px;
        }

        .ck-editor__editable {
            min-height: 100px;
        }

        /* Dashboard & Admin UI polish */
        body.admin-page {
            background: #f7fafc;
            color: #0f172a;
        }

        .admin-header .admin-header-brand {
            display: inline-flex;
            gap: 12px;
            align-items: center;
        }

        .admin-header .admin-header-brand h1 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .card {
            background: #ffffff;
            border-radius: 10px;
            padding: 18px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
        }

        .card h3 {
            margin-top: 0;
        }

        .btn-primary {
            background: #0b6fbf;
            color: #fff;
            border: 0;
            padding: 8px 12px;
            border-radius: 8px;
        }

        .btn-site {
            background: var(--color-rdm);
            color: #fff;
            border: 0;
            padding: 8px 12px;
            border-radius: 8px;
        }

        .btn-secondary {
            background: #ffffff;
            color: #0f172a;
            border: 1px solid rgba(15, 23, 42, 0.08);
            padding: 7px 10px;
            border-radius: 8px;
        }

        .btn {
            font-weight: 600;
        }

        .admin-layout-grid {
            gap: 24px;
        }

        /* Make main column full width when viewing dashboard */
        body.dashboard-view .lg\:col-span-3 {
            grid-column: 1 / -1;
        }

        /* Chart card title style */
        .card canvas {
            background: #fff;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // CKEditor 5 base configuration
            const ckConfig = {
                toolbar: {
                    items: ['undo', 'redo', '|', 'bold', 'italic', 'underline', '|', 'bulletedList', 'numberedList', '|', 'link']
                },
                link: {
                    addTargetToExternalLinks: true
                }
            };

            // CKEditor for title fields - compact
            document.querySelectorAll('textarea[name="title"]').forEach(textarea => {
                ClassicEditor.create(textarea, {
                    toolbar: {
                        items: ['undo', 'redo', '|', 'bold', 'italic'],
                        shouldNotGroupWhenFull: true
                    }
                }).catch(error => console.error('CKEditor error:', error));
            });

            // CKEditor for content fields - larger with more options
            // EXCLUDE approval modal textarea (initialized manually when modal opens)
            document.querySelectorAll('textarea:not([name="title"]):not([data-no-auto-init])').forEach(textarea => {
                ClassicEditor.create(textarea, {
                    ...ckConfig,
                    toolbar: {
                        items: ['undo', 'redo', '|', 'bold', 'italic', 'underline', '|', 'bulletedList', 'numberedList', '|', 'link', 'insertTable', '|', 'blockQuote']
                    }
                }).catch(error => console.error('CKEditor error:', error));
            });
        });
    </script>
</head>

<body class="admin-page <?php echo $page === 'dashboard' ? 'dashboard-view' : ''; ?>">
    <!-- Sidebar Toggle Button -->
    <button class="sidebar-toggle-btn" id="sidebarToggle">
        <i class="fa-solid fa-bars"></i>
    </button>
    <nav class="admin-header text-white p-4 sticky top-0 z-50">
        <div class="admin-header-inner flex justify-end items-center px-8">
            <div class="admin-header-brand">
                <i class="fa-solid fa-gears text-2xl"></i>
                <h1 class="text-2xl font-bold">Admin Panel</h1>
            </div>
            <div class="admin-header-actions flex items-center gap-3 flex-nowrap">
                <?php if ($hasPermission('view_audit')): ?>
                    <a href="admin.php?page=audit" class="btn <?php echo $page === 'audit' ? 'btn-primary' : 'btn-secondary'; ?> text-sm">
                        <i class="fa-solid fa-clipboard-list"></i> Auditlogboek
                    </a>
                <?php endif; ?>
                <?php if ($hasAnyPermission(['manage_events', 'view_audit', 'access_booking'])): ?>
                    <a href="admin.php?page=dashboard" class="btn <?php echo $page === 'dashboard' ? 'btn-site' : 'btn-secondary'; ?> text-sm">
                        <i class="fa-solid fa-chart-pie"></i> Dashboard
                    </a>
                <?php endif; ?>
                <?php if (!empty($_SESSION['can_access_admin'])): ?>
                    <a href="admin.php?page=agenda" class="btn btn-secondary text-sm">
                        <i class="fa-solid fa-toolbox"></i> Admin Panel
                    </a>
                <?php endif; ?>
                <?php if ($hasPermission('access_booking')): ?>
                    <a href="booking.php" class="btn btn-secondary text-sm">
                        <i class="fa-solid fa-calendar-check"></i> Booking
                    </a>
                <?php endif; ?>
                <a href="index.php" class="btn btn-secondary text-sm">
                    <i class="fa-solid fa-arrow-left"></i> Terug naar site
                </a>
                <a href="logout.php" class="btn btn-secondary text-sm">
                    <i class="fa-solid fa-sign-out-alt"></i> Uitloggen
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-6">
        <?php if (!empty($message)): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-exclamation-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="admin-layout-grid grid grid-cols-1 lg:grid-cols-4 gap-6">
            <!-- Sidebar -->
            <?php if ($page !== 'dashboard'): ?>
                <aside class="sidebar">
                    <div class="sidebar-header">
                        <i class="fa-solid fa-bars"></i> Navigatie
                    </div>
                    <nav class="divide-y">
                        <div class="px-4 py-2 bg-gray-100 text-sm font-semibold text-gray-700">Beheer</div>
                        <?php if ($hasPermission('manage_banners')): ?>
                            <a href="admin.php?page=banner" class="sidebar-link <?php echo $page === 'banner' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-image"></i> Banners
                            </a>
                        <?php endif; ?>
                        <?php if ($hasAnyPermission(['manage_events', 'delete_events', 'create_events'])): ?>
                            <a href="admin.php?page=agenda" class="sidebar-link <?php echo $page === 'agenda' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-calendar"></i> Agenda
                            </a>
                        <?php endif; ?>
                        <?php /* Dashboard link removed from sidebar per request */ ?>
                        <?php if ($hasPermission('approve_content')): ?>
                            <a href="admin.php?page=goedkeuren" class="sidebar-link <?php echo $page === 'goedkeuren' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-check-circle"></i> Goedkeuren
                            </a>
                        <?php endif; ?>
                        <?php if ($hasRole('onderzoeker')): ?>
                            <a href="admin.php?page=aanvragen" class="sidebar-link <?php echo $page === 'aanvragen' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-paper-plane"></i> Mijn Aanvragen
                            </a>
                        <?php endif; ?>
                        <?php if ($hasAnyPermission(['create_users', 'edit_users', 'delete_users'])): ?>
                            <a href="admin.php?page=users" class="sidebar-link <?php echo $page === 'users' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-users"></i> Gebruikers & Rechten
                            </a>
                        <?php endif; ?>
                        <!-- Image Converter removed -->

                        <?php if ($hasAnyPermission(['manage_pages', 'delete_pages', 'view_pages'])): ?>
                            <div class="px-4 py-2 bg-gray-100 text-sm font-semibold text-gray-700">Pagina's</div>
                            <a href="admin.php?page=index" class="sidebar-link <?php echo $page === 'index' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-house"></i> Homepage
                            </a>
                            <a href="admin.php?page=evenementen" class="sidebar-link <?php echo $page === 'evenementen' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-calendar-check"></i> Evenementen
                            </a>
                            <a href="admin.php?page=terugblikken" class="sidebar-link <?php echo $page === 'terugblikken' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-history"></i> Terugblikken
                            </a>
                            <a href="admin.php?page=over" class="sidebar-link <?php echo $page === 'over' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-info-circle"></i> Voor wie?
                            </a>
                            <a href="admin.php?page=wie-zijn-we" class="sidebar-link <?php echo $page === 'wie-zijn-we' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-people-group"></i> Wie zijn we?
                            </a>
                            <a href="admin.php?page=ons-team" class="sidebar-link <?php echo $page === 'ons-team' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-users"></i> Ons team
                            </a>
                            <a href="admin.php?page=verantwoord-ai" class="sidebar-link <?php echo $page === 'verantwoord-ai' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-shield"></i> Verantwoord AI
                            </a>
                            <a href="admin.php?page=contact" class="sidebar-link <?php echo $page === 'contact' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-envelope"></i> Contact
                            </a>

                            <div class="px-4 py-2 bg-gray-100 text-sm font-semibold text-gray-700">Wat doen we</div>
                            <a href="admin.php?page=programma-kennis" class="sidebar-link <?php echo $page === 'programma-kennis' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-brain"></i> Kennis & Vaardigheden
                            </a>
                            <a href="admin.php?page=programma-actie" class="sidebar-link <?php echo $page === 'programma-actie' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-rocket"></i> Actie, onderzoek & ontwerp
                            </a>
                            <a href="admin.php?page=programma-faciliteit" class="sidebar-link <?php echo $page === 'programma-faciliteit' ? 'active' : ''; ?>">
                                <i class="fa-solid fa-building"></i> Faciliteit van het Lab
                            </a>
                        <?php endif; ?>
                    </nav>
                </aside>
            <?php endif; ?>

            <div class="lg:col-span-3 <?php echo $isEditorReadOnlyPage ? 'admin-readonly-scope' : ''; ?>">

                <?php if ($isEditorReadOnlyPage): ?>
                    <div class="alert alert-error" style="background:#f3f4f6; color:#1f2937; border-left-color:#9ca3af;">
                        <i class="fa-solid fa-eye"></i> Alleen-lezen modus: is dit een fout? Neem contact op met een beheerder om je rechten te controleren.
                    </div>
                <?php endif; ?>

                <?php if ($isOnderzoeker): ?>
                    <div class="alert alert-info" style="background:#dbeafe; color:#1e3a8a; border-left-color:#3b82f6; border-left-width:4px; border-radius:4px;">
                        <i class="fa-solid fa-info-circle"></i> <strong>Modus Onderzoeker:</strong> Je kan inhoud bekijken en wijzigen, maar alle wijzigingen moeten worden goedgekeurd door een beheerder of content manager voordat ze live gaan.
                    </div>
                <?php endif; ?>

                <?php if ($page === 'agenda'): ?>
                    <div class="card p-6">
                        <div class="flex items-center gap-2 mb-4 pb-4 border-b-2 border-gray-200">
                            <i class="fa-solid fa-calendar text-2xl text-[#00811F]"></i>
                            <h2 class="text-2xl font-bold">Agenda Beheer</h2>
                        </div>

                        <?php if ($editEvent && $canManageEvents): ?>
                            <a href="admin.php?page=agenda" class="btn btn-secondary mb-6">
                                <i class="fa-solid fa-arrow-left"></i> Terug
                            </a>
                        <?php endif; ?>

                        <?php if ($editEvent): ?>
                            <form method="POST" enctype="multipart/form-data" class="bg-white p-6 shadow-md space-y-4 mb-6 js-content-preview-form">
                                <h3 class="font-semibold text-lg">Bewerk Evenement</h3>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?php echo (int)$editEvent['id']; ?>">
                                <input type="hidden" name="edit_version" value="<?php echo htmlspecialchars($editEvent['edit_version'], ENT_QUOTES, 'UTF-8'); ?>">

                                <div>
                                    <label class="form-label">Titel</label>
                                    <textarea name="title" rows="1" class="form-textarea"><?php echo htmlspecialchars($editEvent['title'] ?? ''); ?></textarea>
                                </div>

                                <div class="grid grid-cols-3 gap-4">
                                    <div>
                                        <label class="form-label">Wanneer (datum)</label>
                                        <input type="date" name="date" required class="form-input" value="<?php echo htmlspecialchars($editEvent['date']); ?>" />
                                        <div class="mt-2">
                                            <label class="form-checkbox">
                                                <input type="checkbox" id="add-end-date-edit" name="add_end_date" <?php echo !empty($editEvent['end_date']) ? 'checked' : ''; ?> />
                                                Einddatum toevoegen
                                            </label>
                                            <div id="end-date-container-edit" class="admin-mt-8 <?php echo empty($editEvent['end_date']) ? 'admin-hidden' : ''; ?>">
                                                <label class="form-label">Einddatum <span class="text-xs text-gray-500">(optioneel)</span></label>
                                                <input type="date" name="end_date" class="form-input" value="<?php echo htmlspecialchars($editEvent['end_date'] ?? ''); ?>" />
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="form-label">Starttijd</label>
                                        <input type="time" name="time" class="form-input" value="<?php echo htmlspecialchars($editEvent['time'] ?? ''); ?>" />
                                        <div class="mt-2">
                                            <label class="form-checkbox">
                                                <input type="checkbox" id="add-end-time-edit" name="add_end_time" <?php echo !empty($editEvent['time_end']) ? 'checked' : ''; ?> />
                                                Eindtijd toevoegen
                                            </label>
                                            <div id="end-time-container-edit" class="admin-mt-8 <?php echo empty($editEvent['time_end']) ? 'admin-hidden' : ''; ?>">
                                                <label class="form-label">Eindtijd <span class="text-xs text-gray-500">(optioneel)</span></label>
                                                <input type="time" name="time_end" class="form-input" value="<?php echo htmlspecialchars($editEvent['time_end'] ?? ''); ?>" />
                                            </div>
                                        </div>
                                    </div>
                                </div>


                                <div>
                                    <label class="form-label">Plaats</label>
                                    <input name="location" class="form-input admin-input-surface admin-input-h-48" value="<?php echo htmlspecialchars($editEvent['location'] ?? ''); ?>" />
                                </div>

                                <div class="admin-event-hardware-picker" data-target="hardware-request-edit">
                                    <label class="form-label">Apparatuur toevoegen (optioneel)</label>
                                    <div style="display: flex; gap: 0.5rem; margin-bottom: 0.75rem; flex-wrap: wrap;">
                                        <select class="form-input js-hardware-select" style="flex: 1; min-width: 260px;">
                                            <option value="">-- Kies hardware --</option>
                                            <?php foreach ($eventHardwareOptions as $hw): ?>
                                                <option value="<?php echo (int)$hw['id']; ?>" data-name="<?php echo htmlspecialchars($hw['name'], ENT_QUOTES, 'UTF-8'); ?>" data-max="<?php echo (int)$hw['quantity']; ?>"><?php echo htmlspecialchars($hw['name']); ?> (max: <?php echo (int)$hw['quantity']; ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="number" class="form-input js-hardware-qty" min="1" value="1" style="width: 90px;">
                                        <button type="button" class="btn btn-secondary js-add-hardware-btn" style="width: auto;">Voeg toe</button>
                                    </div>
                                    <div class="js-hardware-selected" style="display: flex; flex-wrap: wrap; gap: 0.6rem;"></div>
                                    <textarea name="hardware_request" id="hardware-request-edit" rows="3" class="form-textarea admin-hidden" placeholder="Bijv. 2x VR bril, 1x Speaker"><?php echo htmlspecialchars($editEvent['hardware_request'] ?? ''); ?></textarea>
                                    <p class="text-xs text-gray-500 mt-2">Wordt meegenomen naar Booking en zichtbaar bij goedkeuring.</p>
                                </div>

                                <div>
                                    <label class="form-label">Wie van SociaalAI Lab zijn erbij? (optioneel)</label>
                                    <textarea name="staff_present" rows="3" class="form-textarea" placeholder="Bijv. Naam 1, Naam 2"><?php echo htmlspecialchars($editEvent['staff_present'] ?? ''); ?></textarea>
                                    <p class="text-xs text-gray-500 mt-2">Mag leeg blijven.</p>
                                </div>

                                <div>
                                    <label class="form-label">Omschrijving</label>
                                    <textarea name="description" rows="5" class="form-textarea"><?php echo htmlspecialchars($editEvent['description'] ?? ''); ?></textarea>
                                </div>

                                <div>
                                    <label class="form-label">Meer info tekst (optioneel)</label>
                                    <textarea name="meer_info" rows="5" class="form-textarea"><?php echo htmlspecialchars($editEvent['meer_info'] ?? ''); ?></textarea>
                                    <p class="text-xs text-gray-500 mt-2">Deze tekst wordt op de evenement detailpagina getoond boven de samenvatting.</p>
                                </div>

                                <div>
                                    <label class="form-label" for="event-info-link-edit">Informatielink (optioneel)</label>
                                    <input type="url" name="info_link" id="event-info-link-edit" class="form-input" value="<?php echo htmlspecialchars($editEvent['info_link'] ?? ''); ?>" placeholder="https://..." />
                                </div>

                                <div>
                                    <label class="form-label">Samenvatting na afloop (optioneel)</label>
                                    <textarea name="event_summary" rows="5" class="form-textarea"><?php echo htmlspecialchars($editEvent['event_summary'] ?? ''); ?></textarea>
                                    <p class="text-xs text-gray-500 mt-2">Deze samenvatting wordt op de evenement detailpagina getoond zodra deze is ingevuld en het evenement voorbij is.</p>
                                </div>

                                <div>
                                    <label class="form-label">Doelgroep (optioneel)</label>
                                    <input name="target_audience" class="form-input admin-input-surface" value="<?php echo htmlspecialchars($editEvent['target_audience'] ?? ''); ?>" placeholder="bijv. Scholieren, Docenten, Researchers" />
                                    <p class="text-xs text-gray-500 mt-2">Alleen zichtbaar voor goedkeuring, niet op de publieke pagina.</p>
                                </div>

                                <div>
                                    <label class="form-label">Meer info voor beoordeling (voeg ook het doel van het event toe)</label>
                                    <textarea name="internal_notes" rows="3" class="form-textarea" placeholder="Beschrijf extra info voor beoordeling en het doel van dit event"><?php echo htmlspecialchars($editEvent['internal_notes'] ?? ''); ?></textarea>
                                    <p class="text-xs text-gray-500 mt-2">Alleen zichtbaar voor administratoren, niet op de publieke pagina.</p>
                                </div>

                                <div>
                                    <label class="form-label">Afbeelding (verplicht)</label>
                                    <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars($editEvent['image'] ?? ''); ?>" />
                                    <div class="admin-upload-widget" data-name="image" data-accept="image/*" data-required="true"></div>
                                    <?php if (!empty($editEvent['image'])): ?>
                                        <p class="text-sm mt-2 text-gray-600">Huidige: <?php echo htmlspecialchars($editEvent['image']); ?></p>
                                        <label class="form-checkbox mt-2 inline-flex items-center gap-2">
                                            <input type="checkbox" name="remove_image" value="1">
                                            Verwijder huidige afbeelding
                                        </label>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <label class="form-label">Foto's tijdens event (optioneel, meerdere bestanden)</label>
                                    <div class="admin-upload-widget" data-name="gallery_images[]" data-accept="image/*" data-multiple="true"></div>
                                    <?php $editGallery = decodeEventGallery($editEvent['event_gallery'] ?? null); ?>
                                    <?php if (!empty($editGallery)): ?>
                                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-3">
                                            <?php foreach ($editGallery as $galleryImage): ?>
                                                <label class="border border-gray-200 rounded p-2 block">
                                                    <img src="uploads/<?php echo htmlspecialchars($galleryImage); ?>" alt="Eventfoto" class="w-full h-24 object-cover rounded">
                                                    <span class="form-checkbox mt-2 inline-flex items-center gap-2">
                                                        <input type="checkbox" name="remove_gallery_images[]" value="<?php echo htmlspecialchars($galleryImage); ?>">
                                                        Verwijder
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <label class="form-label">Documenten voor beoordeling (optioneel)</label>
                                    <input type="file" name="event_documents[]" class="form-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt" multiple>
                                    <p class="text-xs text-gray-500 mt-2">Alleen zichtbaar in de beoordelingsflow (Goedkeuren / Mijn Aanvragen).</p>
                                    <?php $editDocuments = decodeEventDocuments($editEvent['event_documents'] ?? null); ?>
                                    <?php if (!empty($editDocuments)): ?>
                                        <div class="mt-3 space-y-2">
                                            <?php foreach ($editDocuments as $documentFile): ?>
                                                <label class="flex items-center justify-between border border-gray-200 rounded p-2 gap-3">
                                                    <a href="uploads/<?php echo rawurlencode($documentFile); ?>" target="_blank" rel="noopener noreferrer" class="text-sm text-blue-700 underline break-all">
                                                        <?php echo htmlspecialchars($documentFile); ?>
                                                    </a>
                                                    <span class="form-checkbox inline-flex items-center gap-2">
                                                        <input type="checkbox" name="remove_event_documents[]" value="<?php echo htmlspecialchars($documentFile); ?>">
                                                        Verwijder
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <!-- Meer info link veld verwijderd -->

                                <div>
                                    <label class="form-label" for="signup_embed">Aanmelder.nl link (optioneel):</label>
                                    <input
                                        type="url"
                                        name="signup_embed"
                                        id="signup_embed"
                                        class="form-input admin-input-surface"
                                        value="<?php echo htmlspecialchars($editEvent['signup_embed'] ?? ''); ?>"
                                        placeholder="https://aanmelder.nl/subscribe/..." />
                                    <?php if (!empty($editEvent['signup_embed'])): ?>
                                        <a href="<?php echo htmlspecialchars($editEvent['signup_embed']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-info bg-[#00811F] text-white px-3 py-1 rounded shadow hover:bg-[#00691A] transition mt-2 inline-block">
                                            <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i> Aanmelden
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <label class="form-checkbox">
                                        <input type="checkbox" name="show_signup_button" <?php echo $editEvent['show_signup_button'] ? 'checked' : ''; ?> />
                                        Toon inschrijf knop
                                    </label>
                                </div>

                                <div>
                                    <label class="form-checkbox">
                                        <input type="checkbox" name="show_on_homepage" <?php echo ($editEvent['show_on_homepage'] ?? 0) ? 'checked' : ''; ?> />
                                        Toon op homepage
                                    </label>
                                </div>

                                <div class="flex gap-2 pt-2">
                                    <button type="button" class="btn btn-secondary js-content-preview-btn">
                                        <i class="fa-solid fa-eye"></i> Preview
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fa-solid fa-save"></i> Opslaan
                                    </button>
                                </div>
                            </form>
                        <?php elseif ($canManageEvents || $canCreateEvents): ?>
                            <form method="POST" enctype="multipart/form-data" class="bg-white p-6 shadow-md space-y-4 mb-6 js-content-preview-form">
                                <h3 class="font-semibold text-lg">Nieuw Evenement</h3>
                                <input type="hidden" name="action" value="create">

                                <div>
                                    <label class="form-label">Titel</label>
                                    <textarea name="title" rows="1" class="form-textarea"></textarea>
                                </div>

                                <div class="grid grid-cols-3 gap-4">
                                    <div>
                                        <label class="form-label">Wanneer (datum)</label>
                                        <input type="date" name="date" required class="form-input" value="<?php echo htmlspecialchars($_POST['date'] ?? ''); ?>" />
                                        <div class="mt-2">
                                            <label class="form-checkbox">
                                                <input type="checkbox" id="add-end-date-create" name="add_end_date" <?php echo !empty($_POST['end_date']) ? 'checked' : ''; ?> />
                                                Einddatum toevoegen
                                            </label>
                                            <div id="end-date-container-create" class="admin-mt-8 <?php echo empty($_POST['end_date']) ? 'admin-hidden' : ''; ?>">
                                                <label class="form-label">Einddatum <span class="text-xs text-gray-500">(optioneel)</span></label>
                                                <input type="date" name="end_date" class="form-input" value="<?php echo htmlspecialchars($_POST['end_date'] ?? ''); ?>" />
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="form-label">Starttijd</label>
                                        <input type="time" name="time" class="form-input" value="<?php echo htmlspecialchars($_POST['time'] ?? ''); ?>" />
                                        <div class="mt-2">
                                            <label class="form-checkbox">
                                                <input type="checkbox" id="add-end-time-create" name="add_end_time" <?php echo !empty($_POST['time_end']) ? 'checked' : ''; ?> />
                                                Eindtijd toevoegen
                                            </label>
                                            <div id="end-time-container-create" class="admin-mt-8 <?php echo empty($_POST['time_end']) ? 'admin-hidden' : ''; ?>">
                                                <label class="form-label">Eindtijd <span class="text-xs text-gray-500">(optioneel)</span></label>
                                                <input type="time" name="time_end" class="form-input" value="<?php echo htmlspecialchars($_POST['time_end'] ?? ''); ?>" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label">Plaats</label>
                                    <input name="location" class="form-input admin-input-surface" value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>" />
                                </div>

                                <div class="admin-event-hardware-picker" data-target="hardware-request-create">
                                    <label class="form-label">Apparatuur toevoegen (optioneel)</label>
                                    <div style="display: flex; gap: 0.5rem; margin-bottom: 0.75rem; flex-wrap: wrap;">
                                        <select class="form-input js-hardware-select" style="flex: 1; min-width: 260px;">
                                            <option value="">-- Kies hardware --</option>
                                            <?php foreach ($eventHardwareOptions as $hw): ?>
                                                <option value="<?php echo (int)$hw['id']; ?>" data-name="<?php echo htmlspecialchars($hw['name'], ENT_QUOTES, 'UTF-8'); ?>" data-max="<?php echo (int)$hw['quantity']; ?>"><?php echo htmlspecialchars($hw['name']); ?> (max: <?php echo (int)$hw['quantity']; ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="number" class="form-input js-hardware-qty" min="1" value="1" style="width: 90px;">
                                        <button type="button" class="btn btn-secondary js-add-hardware-btn" style="width: auto;">Voeg toe</button>
                                    </div>
                                    <div class="js-hardware-selected" style="display: flex; flex-wrap: wrap; gap: 0.6rem;"></div>
                                    <textarea name="hardware_request" id="hardware-request-create" rows="3" class="form-textarea admin-hidden" placeholder="Bijv. 2x VR bril, 1x Speaker"><?php echo htmlspecialchars($_POST['hardware_request'] ?? ''); ?></textarea>
                                    <p class="text-xs text-gray-500 mt-2">Wordt meegenomen naar Booking en zichtbaar bij goedkeuring.</p>
                                </div>

                                <div>
                                    <label class="form-label">Wie van SociaalAI Lab zijn erbij? (optioneel)</label>
                                    <textarea name="staff_present" rows="3" class="form-textarea" placeholder="Bijv. Naam 1, Naam 2"><?php echo htmlspecialchars($_POST['staff_present'] ?? ''); ?></textarea>
                                    <p class="text-xs text-gray-500 mt-2">Mag leeg blijven.</p>
                                </div>

                                <div>
                                    <label class="form-label">Omschrijving</label>
                                    <textarea name="description" rows="5" class="form-textarea"></textarea>
                                </div>

                                <div>
                                    <label class="form-label">Meer info tekst (optioneel)</label>
                                    <textarea name="meer_info" rows="5" class="form-textarea"></textarea>
                                    <p class="text-xs text-gray-500 mt-2">Deze tekst wordt op de evenement detailpagina getoond boven de samenvatting.</p>
                                </div>

                                <div>
                                    <label class="form-label" for="event-info-link-create">Informatielink (optioneel)</label>
                                    <input type="url" name="info_link" id="event-info-link-create" class="form-input" value="<?php echo htmlspecialchars($_POST['info_link'] ?? ''); ?>" placeholder="https://..." />
                                </div>

                                <div>
                                    <label class="form-label">Samenvatting na afloop (optioneel)</label>
                                    <textarea name="event_summary" rows="5" class="form-textarea"></textarea>
                                    <p class="text-xs text-gray-500 mt-2">Deze samenvatting wordt op de evenement detailpagina getoond zodra deze is ingevuld.</p>
                                </div>

                                <div>
                                    <label class="form-label">Doelgroep (optioneel)</label>
                                    <input name="target_audience" class="form-input admin-input-surface" placeholder="bijv. Scholieren, Docenten, Researchers" />
                                    <p class="text-xs text-gray-500 mt-2">Alleen zichtbaar voor goedkeuring, niet op de publieke pagina.</p>
                                </div>

                                <div>
                                    <label class="form-label">Meer info voor beoordeling (voeg ook het doel van het event toe)</label>
                                    <textarea name="internal_notes" rows="3" class="form-textarea" placeholder="Beschrijf extra info voor beoordeling en het doel van dit event"></textarea>
                                    <p class="text-xs text-gray-500 mt-2">Alleen zichtbaar voor administratoren, niet op de publieke pagina.</p>
                                </div>

                                <div>
                                    <label class="form-label">Afbeelding (verplicht)</label>
                                    <div class="admin-upload-widget" data-name="image" data-accept="image/*" data-required="true"></div>
                                </div>

                                <div>
                                    <label class="form-label">Foto's tijdens event (optioneel, meerdere bestanden)</label>
                                    <div class="admin-upload-widget" data-name="gallery_images[]" data-accept="image/*" data-multiple="true"></div>
                                </div>

                                <div>
                                    <label class="form-label">Documenten voor beoordeling (optioneel)</label>
                                    <input type="file" name="event_documents[]" class="form-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt" multiple>
                                    <p class="text-xs text-gray-500 mt-2">Alleen zichtbaar in de beoordelingsflow (Goedkeuren / Mijn Aanvragen).</p>
                                </div>
                                <!-- Meer info link veld verwijderd -->

                                <div>
                                    <label class="form-label" for="signup_embed">Aanmelder.nl link (optioneel):</label>
                                    <input
                                        type="url"
                                        name="signup_embed"
                                        id="signup_embed"
                                        class="form-input admin-input-surface"
                                        value=""
                                        placeholder="https://aanmelder.nl/subscribe/..." />
                                </div>

                                <div>
                                    <label class="form-checkbox">
                                        <input type="checkbox" name="show_signup_button" checked />
                                        Toon inschrijf knop
                                    </label>
                                </div>

                                <div>
                                    <label class="form-checkbox">
                                        <input type="checkbox" name="show_on_homepage" checked />
                                        Toon op homepage
                                    </label>
                                </div>

                                <div class="flex gap-2 pt-2">
                                    <button type="button" class="btn btn-secondary js-content-preview-btn">
                                        <i class="fa-solid fa-eye"></i> Preview
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fa-solid fa-plus"></i> Toevoegen
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <div class="card p-6">
                            <div class="flex items-center gap-2 mb-4 pb-4 border-b-2 border-gray-200">
                                <i class="fa-solid fa-list text-2xl text-[#00811F]"></i>
                                <h3 class="text-xl font-bold">Alle Evenementen</h3>
                            </div>
                            <?php if (empty($events)): ?>
                                <div class="text-center py-8 text-gray-500">
                                    <i class="fa-solid fa-inbox text-4xl mb-2"></i>
                                    <p>Geen evenementen aangemaakt</p>
                                </div>
                            <?php else: ?>
                                <?php if ($canDeleteEvents): ?>
                                    <div class="mb-4 flex gap-2 items-center">
                                        <input type="checkbox" id="selectAllEvents" class="w-4 h-4 cursor-pointer" title="Selecteer alles">
                                        <label for="selectAllEvents" class="cursor-pointer text-sm font-medium">Selecteer alles</label>
                                        <button type="button" class="btn btn-danger btn-sm admin-hidden" id="bulkDeleteEventsBtn">
                                            <i class="fa-solid fa-trash"></i> Verwijder geselecteerde (<span id="eventCount">0</span>)
                                        </button>
                                    </div>
                                <?php endif; ?>
                                <form method="POST" id="bulkDeleteEventsForm">
                                    <input type="hidden" name="action" value="delete_bulk_events">
                                    <div class="space-y-4">
                                        <?php foreach ($events as $event): ?>
                                            <div class="border border-gray-200 rounded p-4 hover:shadow-md transition">
                                                <div class="admin-row flex justify-between items-start gap-4">
                                                    <?php if ($canDeleteEvents): ?>
                                                        <input type="checkbox" class="event-checkbox w-4 h-4 mt-1 flex-shrink-0 cursor-pointer" name="event_ids[]" value="<?php echo (int)$event['id']; ?>">
                                                    <?php endif; ?>
                                                    <div class="flex-1">
                                                        <h4 class="font-bold text-lg text-gray-800"><?php echo htmlspecialchars(sanitizeEditorText($event['title'])); ?></h4>
                                                        <?php
                                                        $dateDisplay = formatEventDateDisplay($event['date']);
                                                        $timeDisplay = $event['time'] ? formatEventTimeDisplay($event['time']) : '';
                                                        $timeEndDisplay = $event['time_end'] ? formatEventTimeDisplay($event['time_end']) : '';
                                                        $eventDescriptionPreview = editorPreviewText($event['description'] ?? '', 200);
                                                        $eventGalleryCount = count(decodeEventGallery($event['event_gallery'] ?? null));
                                                        ?>
                                                        <p class="text-sm text-gray-600 mt-1"><strong>Wanneer:</strong> <?php echo htmlspecialchars($dateDisplay); ?><?php if ($timeDisplay) {
                                                                                                                                                                            echo ' ' . htmlspecialchars($timeDisplay);
                                                                                                                                                                        } ?><?php if ($timeEndDisplay) {
                                                                                                                                                                                echo ' - ' . htmlspecialchars($timeEndDisplay);
                                                                                                                                                                            } ?></p>
                                                        <?php if (!empty($event['location'])): ?>
                                                            <p class="text-sm text-gray-600"><strong>Plaats:</strong> <?php echo htmlspecialchars($event['location']); ?></p>
                                                        <?php endif; ?>
                                                        <p class="text-sm text-gray-600 mt-2 line-clamp-2"><?php echo nl2br(htmlspecialchars($eventDescriptionPreview)); ?></p>
                                                        <?php if ($eventGalleryCount > 0): ?>
                                                            <p class="text-sm text-gray-600 mt-1"><strong>Eventfoto's:</strong> <?php echo (int)$eventGalleryCount; ?></p>
                                                        <?php endif; ?>
                                                        <!-- Meer info link verwijderd uit event overzicht -->
                                                    </div>
                                                    <div class="admin-row-actions flex gap-2 flex-shrink-0">
                                                        <?php if (!empty($event['image'])): ?>
                                                            <a href="#" class="btn btn-secondary btn-sm" onclick="openImageModal(event, 'uploads/<?php echo htmlspecialchars($event['image']); ?>')">
                                                                <i class="fa-solid fa-image"></i> Foto
                                                            </a>
                                                        <?php endif; ?>
                                                        <div class="admin-row-actions-stack flex flex-col gap-1">
                                                            <?php if ($canManageEvents): ?>
                                                                <a href="admin.php?page=agenda&edit=<?php echo (int)$event['id']; ?>" class="btn btn-secondary btn-sm">
                                                                    <i class="fa-solid fa-pencil"></i> Bewerk
                                                                </a>
                                                            <?php endif; ?>
                                                            <?php if ($canDeleteEvents): ?>
                                                                <form method="POST" onsubmit="return confirm('Verwijder dit evenement?');" class="admin-inline-form">
                                                                    <input type="hidden" name="action" value="delete">
                                                                    <input type="hidden" name="id" value="<?php echo (int)$event['id']; ?>">
                                                                    <button type="submit" class="btn btn-danger btn-sm w-full">
                                                                        <i class="fa-solid fa-trash"></i> Verwijder
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>

                        <script>
                            (function() {
                                function parseHardwareLine(line) {
                                    const cleanLine = (line || '').trim();
                                    if (cleanLine === '') {
                                        return null;
                                    }

                                    const match = cleanLine.match(/^(\d+)\s*[xX×]\s+(.+)$/);
                                    if (match) {
                                        return {
                                            qty: Math.max(1, parseInt(match[1], 10) || 1),
                                            name: match[2].trim(),
                                            custom: false,
                                            line: cleanLine
                                        };
                                    }

                                    return {
                                        qty: 1,
                                        name: cleanLine,
                                        custom: true,
                                        line: cleanLine
                                    };
                                }

                                function createChip(item, onRemove) {
                                    const chip = document.createElement('div');
                                    chip.style.display = 'flex';
                                    chip.style.alignItems = 'center';
                                    chip.style.gap = '0.5rem';
                                    chip.style.padding = '0.45rem 0.7rem';
                                    chip.style.background = '#f0f5ff';
                                    chip.style.border = '1px solid #dfe8f3';
                                    chip.style.borderRadius = '8px';

                                    const name = document.createElement('span');
                                    name.style.fontWeight = '600';
                                    name.style.color = '#00811F';
                                    name.textContent = item.name;

                                    const qty = document.createElement('span');
                                    qty.style.background = '#00811F';
                                    qty.style.color = '#fff';
                                    qty.style.padding = '0.15rem 0.45rem';
                                    qty.style.borderRadius = '999px';
                                    qty.style.fontSize = '0.75rem';
                                    qty.style.fontWeight = '700';
                                    qty.textContent = item.qty + 'x';

                                    const removeBtn = document.createElement('button');
                                    removeBtn.type = 'button';
                                    removeBtn.style.border = 'none';
                                    removeBtn.style.background = 'transparent';
                                    removeBtn.style.cursor = 'pointer';
                                    removeBtn.style.color = '#dc2626';
                                    removeBtn.style.fontSize = '1.1rem';
                                    removeBtn.textContent = '×';
                                    removeBtn.addEventListener('click', onRemove);

                                    chip.appendChild(name);
                                    chip.appendChild(qty);
                                    chip.appendChild(removeBtn);
                                    return chip;
                                }

                                function initHardwarePicker(wrapper) {
                                    const targetId = wrapper.getAttribute('data-target');
                                    if (!targetId) {
                                        return;
                                    }

                                    const textarea = document.getElementById(targetId);
                                    const select = wrapper.querySelector('.js-hardware-select');
                                    const qtyInput = wrapper.querySelector('.js-hardware-qty');
                                    const addBtn = wrapper.querySelector('.js-add-hardware-btn');
                                    const selectedContainer = wrapper.querySelector('.js-hardware-selected');

                                    if (!textarea || !select || !qtyInput || !addBtn || !selectedContainer) {
                                        return;
                                    }

                                    const items = {};
                                    let customCounter = 0;

                                    function syncTextarea() {
                                        const lines = Object.values(items).map(function(item) {
                                            return item.custom ? item.line : (item.qty + 'x ' + item.name);
                                        });
                                        textarea.value = lines.join("\n");
                                    }

                                    function renderItems() {
                                        selectedContainer.innerHTML = '';
                                        Object.keys(items).forEach(function(key) {
                                            const item = items[key];
                                            selectedContainer.appendChild(createChip(item, function() {
                                                delete items[key];
                                                renderItems();
                                                syncTextarea();
                                            }));
                                        });
                                    }

                                    function addSelectedHardware() {
                                        const selectedOption = select.options[select.selectedIndex];
                                        const id = select.value;
                                        if (!id || !selectedOption) {
                                            alert('Kies eerst een hardware item.');
                                            return;
                                        }

                                        const qty = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                                        const max = Math.max(1, parseInt(selectedOption.getAttribute('data-max') || '1', 10));
                                        if (qty > max) {
                                            alert('Maximaal ' + max + ' beschikbaar van dit item.');
                                            return;
                                        }

                                        items[id] = {
                                            id: id,
                                            name: selectedOption.getAttribute('data-name') || selectedOption.textContent,
                                            qty: qty,
                                            max: max,
                                            custom: false,
                                            line: ''
                                        };

                                        renderItems();
                                        syncTextarea();

                                        select.value = '';
                                        qtyInput.value = '1';
                                    }

                                    addBtn.addEventListener('click', addSelectedHardware);

                                    const initialLines = (textarea.value || '').split(/\r?\n/);
                                    initialLines.forEach(function(line) {
                                        const parsed = parseHardwareLine(line);
                                        if (!parsed) {
                                            return;
                                        }

                                        const option = Array.from(select.options).find(function(opt) {
                                            return (opt.getAttribute('data-name') || '').trim().toLowerCase() === parsed.name.toLowerCase();
                                        });

                                        if (option && option.value) {
                                            const max = Math.max(1, parseInt(option.getAttribute('data-max') || '1', 10));
                                            items[option.value] = {
                                                id: option.value,
                                                name: option.getAttribute('data-name') || parsed.name,
                                                qty: Math.min(parsed.qty, max),
                                                max: max,
                                                custom: false,
                                                line: ''
                                            };
                                        } else {
                                            const key = 'custom-' + (++customCounter);
                                            items[key] = {
                                                id: key,
                                                name: parsed.name,
                                                qty: parsed.qty,
                                                max: parsed.qty,
                                                custom: true,
                                                line: parsed.line
                                            };
                                        }
                                    });

                                    renderItems();
                                    syncTextarea();
                                }

                                document.addEventListener('DOMContentLoaded', function() {
                                    document.querySelectorAll('.admin-event-hardware-picker').forEach(initHardwarePicker);
                                });
                            })();
                        </script>
                    </div>

                <?php elseif ($page === 'users'): ?>
                    <div class="card p-6">
                        <div class="flex items-center gap-2 mb-4 pb-4 border-b-2 border-gray-200">
                            <i class="fa-solid fa-users text-2xl text-[#00811F]"></i>
                            <h2 class="text-2xl font-bold">Gebruikers & Rechten</h2>
                        </div>
                        <p class="text-sm text-gray-600 mb-4">Zet per gebruiker aan wat die persoon wel of niet mag aanpassen, bekijken of verwijderen.</p>

                        <?php if ($hasPermission('create_users')): ?>
                            <form method="POST" id="userFormContainer" class="bg-white p-6 shadow-md space-y-4 mb-6 border border-gray-200 rounded">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 id="userFormTitle" class="font-semibold text-lg">Gebruiker toevoegen</h3>
                                    <button type="button" id="userFormCancelBtn" class="btn btn-secondary btn-sm hidden" onclick="resetUserForm()">
                                        <i class="fa-solid fa-times"></i> Annuleren
                                    </button>
                                </div>

                                <input type="hidden" name="action" id="userFormAction" value="create_user">
                                <input type="hidden" name="target_email_old" id="targetEmailOld" value="">

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="form-label" for="new_first_name">Voornaam</label>
                                        <input id="new_first_name" type="text" name="new_first_name" class="form-input" maxlength="120" placeholder="Voornaam">
                                    </div>
                                    <div>
                                        <label class="form-label" for="new_last_name">Achternaam</label>
                                        <input id="new_last_name" type="text" name="new_last_name" class="form-input" maxlength="120" placeholder="Achternaam">
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="md:col-span-2">
                                        <label class="form-label" for="new_email">E-mailadres</label>
                                        <input id="new_email" type="email" name="new_email" class="form-input" required placeholder="naam@voorbeeld.nl">
                                    </div>
                                    <div>
                                        <label class="form-label" for="new_role">Basisrol</label>
                                        <select id="new_role" name="new_role" class="form-input">
                                            <option value="viewer">Geen vaste rol</option>
                                            <option value="editor">Onderzoeker</option>
                                            <option value="content_manager">Content Manager</option>
                                            <option value="administrator">Administrator</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label class="form-label" for="new_password">Wachtwoord <span id="passwordNote" class="text-xs text-gray-500"></span></label>
                                    <input id="new_password" type="text" name="new_password" class="form-input" placeholder="Voer wachtwoord in">
                                </div>

                                <button type="submit" id="userFormBtn" class="btn btn-primary">
                                    <i class="fa-solid fa-user-plus"></i> Gebruiker toevoegen
                                </button>
                            </form>

                            <script>
                                function editUser(email, firstName, lastName, role, permissions) {
                                    document.getElementById('userFormTitle').textContent = 'Gebruiker bewerken';
                                    document.getElementById('userFormAction').value = 'update_user';
                                    document.getElementById('targetEmailOld').value = email;
                                    document.getElementById('new_email').value = email;
                                    document.getElementById('new_first_name').value = firstName;
                                    document.getElementById('new_last_name').value = lastName;
                                    document.getElementById('new_role').value = role;
                                    document.getElementById('new_password').value = '';
                                    document.getElementById('passwordNote').textContent = '(laat leeg om hetzelfde wachtwoord te behouden)';
                                    document.getElementById('userFormBtn').innerHTML = '<i class="fa-solid fa-save"></i> Opslaan';
                                    document.getElementById('userFormCancelBtn').classList.remove('hidden');
                                    document.getElementById('userFormContainer').scrollIntoView({
                                        behavior: 'smooth',
                                        block: 'start'
                                    });
                                }

                                function resetUserForm() {
                                    document.getElementById('userFormContainer').reset();
                                    document.getElementById('userFormTitle').textContent = 'Gebruiker toevoegen';
                                    document.getElementById('userFormAction').value = 'create_user';
                                    document.getElementById('targetEmailOld').value = '';
                                    document.getElementById('new_password').value = '';
                                    document.getElementById('passwordNote').textContent = '';
                                    document.getElementById('userFormBtn').innerHTML = '<i class="fa-solid fa-user-plus"></i> Gebruiker toevoegen';
                                    document.getElementById('userFormCancelBtn').classList.add('hidden');
                                }
                            </script>
                        <?php endif; ?>

                        <?php if (empty($userAccounts)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fa-solid fa-inbox text-4xl mb-2"></i>
                                <p>Geen gebruikers gevonden.</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($userAccounts as $acc): ?>
                                    <?php
                                    $accEmail = (string)($acc['email'] ?? '');
                                    $accFirstName = trim((string)($acc['first_name'] ?? ''));
                                    $accLastName = trim((string)($acc['last_name'] ?? ''));
                                    $accFullName = trim($accFirstName . ' ' . $accLastName);
                                    $accRole = trim((string)($acc['role'] ?? ''));
                                    if ($accRole === '') {
                                        $accRole = ((int)($acc['admin'] ?? 0) === 1) ? 'superadmin' : 'viewer';
                                    }
                                    $accPermissions = normalizeAccountPermissions($acc['permissions'] ?? null);
                                    if ($accPermissions === null) {
                                        $accPermissions = permissionsForRole($accRole, $rolePermissions);
                                    }
                                    ?>
                                    <div class="border border-gray-200 rounded p-4 bg-white space-y-4">
                                        <div class="flex flex-col md:flex-row md:items-center gap-3 md:justify-between">
                                            <div>
                                                <?php if ($accFullName !== ''): ?>
                                                    <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($accFullName); ?></p>
                                                <?php endif; ?>
                                                <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($accEmail); ?></p>
                                                <p class="text-xs text-gray-500">Basisrol: <?php echo htmlspecialchars($accRole); ?></p>
                                            </div>

                                            <div class="flex flex-wrap gap-2">
                                                <?php if ($hasPermission('delete_users') && $accEmail !== ($_SESSION['email'] ?? '')): ?>
                                                    <form method="POST" class="admin-inline-form">
                                                        <input type="hidden" name="target_email" value="<?php echo htmlspecialchars($accEmail); ?>">
                                                        <button type="submit" name="action" value="delete_user" class="btn btn-danger btn-sm" onclick="return confirm('Verwijder deze gebruiker?');">
                                                            <i class="fa-solid fa-trash"></i> Gebruiker verwijderen
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if ($hasPermission('edit_users')): ?>
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="editUser('<?php echo htmlspecialchars($accEmail, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($accFirstName, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($accLastName, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($accRole, ENT_QUOTES); ?>', '<?php echo htmlspecialchars(json_encode($accPermissions), ENT_QUOTES); ?>')">
                                                <i class="fa-solid fa-pencil"></i> Bewerken
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($page === 'image-optimizer'): ?>
                    <div class="card p-6">
                        <div class="flex items-center gap-2 mb-4 pb-4 border-b-2 border-gray-200">
                            <i class="fa-solid fa-wand-magic-sparkles text-2xl text-[#00811F]"></i>
                            <h2 class="text-2xl font-bold">Afbeelding Optimizer</h2>
                        </div>
                        <p class="text-sm text-gray-600 mb-6">Optimaliseer afbeeldingen om de laadsnelheid van uw website te verbeteren. Afbeeldingen worden gecomprimeerd tot een smaller bestandsformaat.</p>

                        <div class="bg-white p-6 shadow-md border border-gray-200 rounded mb-6">
                            <h3 class="font-semibold text-lg mb-4">Afbeelding selecteren</h3>

                            <div class="mb-6">
                                <label class="form-label" for="image_select">Selecteer een afbeelding uit uploads</label>
                                <select id="image_select" name="image_path" class="form-input">
                                    <option value="">-- Selecteer een afbeelding --</option>
                                    <?php
                                    $uploadDir = __DIR__ . '/uploads/';
                                    if (is_dir($uploadDir)) {
                                        $files = array_diff(scandir($uploadDir), ['.', '..']);
                                        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                                        foreach ($files as $file) {
                                            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                                            if (in_array($ext, $imageExtensions)) {
                                                $fileSize = filesize($uploadDir . $file);
                                                $fileSizeMB = number_format($fileSize / 1024 / 1024, 2, '.', '');
                                                echo '<option value="' . htmlspecialchars($file) . '">' . htmlspecialchars($file) . ' (' . $fileSizeMB . ' MB)</option>';
                                            }
                                        }
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                                <div>
                                    <label class="form-label" for="quality">Compressiekwaliteit</label>
                                    <div class="flex items-center gap-3">
                                        <input type="range" id="quality" name="quality" min="60" max="95" value="85" class="flex-1">
                                        <span id="quality_value" class="text-sm font-semibold text-gray-700 w-12">85</span>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">Lager = kleiner bestand, hogere compressie. Hoger = betere kwaliteit.</p>
                                </div>
                            </div>

                            <form method="POST" id="optimize_form" class="flex gap-3">
                                <input type="hidden" name="action" value="optimize_image">
                                <input type="hidden" name="image_path" id="form_image_path" value="">
                                <input type="hidden" name="quality" id="form_quality" value="85">
                                <button type="submit" id="optimize_btn" class="btn btn-primary" disabled>
                                    <i class="fa-solid fa-wand-magic-sparkles"></i> Afbeelding optimaliseren
                                </button>
                            </form>
                        </div>

                        <div id="results_container" style="display: none;" class="bg-blue-50 border border-blue-200 rounded p-6">
                            <div class="flex items-start gap-3">
                                <i class="fa-solid fa-circle-check text-2xl text-blue-600 mt-1"></i>
                                <div>
                                    <h4 class="font-semibold text-blue-900 mb-2">Optimalisatie voltooid!</h4>
                                    <p id="results_text" class="text-sm text-blue-800"></p>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($page === 'goedkeuren'): ?>
                    <div class="card p-6">
                        <div class="flex items-center gap-2 mb-4 pb-4 border-b-2 border-gray-200">
                            <i class="fa-solid fa-check-circle text-2xl text-[#00811F]"></i>
                            <h2 class="text-2xl font-bold">Goedkeuren</h2>
                        </div>
                        <p class="text-sm text-gray-600 mb-6">Beoordeel ingediende inhoud van onderzoekers.</p>

                        <?php
                        // Fetch pending approval items - get ALL fields so we can display them
                        try {
                            $stmt = $pdo->prepare(
                                "SELECT id, title, date, end_date, time, time_end, description, location, image, 
                                        created_by, target_audience, internal_notes, approval_status, approval_feedback,
                                    info_link, signup_embed, show_signup_button, created_at, event_documents,
                                    hardware_request, staff_present
                                 FROM events 
                                 WHERE approval_status = 'pending' 
                                 ORDER BY created_at DESC"
                            );
                            $stmt->execute();
                            $pendingItems = $stmt->fetchAll();
                        } catch (Exception $e) {
                            $pendingItems = [];
                        }
                        ?>

                        <?php if (empty($pendingItems)): ?>
                            <div class="text-center py-12 text-gray-500">
                                <i class="fa-solid fa-inbox text-4xl mb-2"></i>
                                <p>Geen items ter goedkeuring.</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($pendingItems as $item): ?>
                                    <div class="border border-yellow-200 rounded-lg p-6 bg-yellow-50">
                                        <div class="flex items-start justify-between gap-4 mb-3">
                                            <div class="flex-1">
                                                <h3 class="font-semibold text-lg text-gray-900"><?php echo htmlspecialchars($item['title'] ?? ''); ?></h3>
                                                <p class="text-sm text-gray-600">
                                                    <i class="fa-solid fa-calendar"></i>
                                                    <?php echo !empty($item['date']) ? (new DateTime($item['date']))->format('d-m-Y') : 'Geen datum'; ?>
                                                </p>
                                                <p class="text-sm text-gray-600">
                                                    <i class="fa-solid fa-user"></i>
                                                    Ingediend door: <?php echo htmlspecialchars($item['created_by'] ?? 'Onbekend'); ?>
                                                </p>
                                            </div>
                                            <span class="inline-block px-3 py-1 bg-yellow-200 text-yellow-900 text-xs font-semibold rounded">In afwachting</span>
                                        </div>

                                        <?php if (!empty($item['target_audience']) || !empty($item['internal_notes'])): ?>
                                            <div class="bg-white p-4 rounded mb-4 border border-yellow-100">
                                                <?php if (!empty($item['target_audience'])): ?>
                                                    <p class="text-sm"><strong>Doelgroep:</strong> <?php echo htmlspecialchars($item['target_audience']); ?></p>
                                                <?php endif; ?>
                                                <?php if (!empty($item['internal_notes'])): ?>
                                                    <p class="text-sm"><strong>Opmerkingen:</strong> <?php echo nl2br(htmlspecialchars($item['internal_notes'])); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php $pendingDocuments = decodeEventDocuments($item['event_documents'] ?? null); ?>
                                        <?php if (!empty($pendingDocuments)): ?>
                                            <div class="bg-white p-4 rounded mb-4 border border-yellow-100">
                                                <p class="text-sm"><strong>Documenten voor beoordeling:</strong> <?php echo count($pendingDocuments); ?></p>
                                            </div>
                                        <?php endif; ?>

                                        <div class="flex flex-wrap gap-2 items-center justify-end">
                                            <button type="button" class="btn btn-info btn-sm" onclick="openApprovalModal(<?php echo (int)$item['id']; ?>, '<?php echo htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>')">
                                                <i class="fa-solid fa-eye"></i> Details bekijken & Beoordelen
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Approval Details Modal -->
                    <div id="approval-modal" class="hidden" style="position: fixed; inset: 0; z-index: 20000; background: rgba(0,0,0,.5); display: none; align-items: center; justify-content: center; padding: 1rem; opacity: 0; transition: opacity 0.3s ease; pointer-events: auto;">
                        <div style="background: white; border-radius: 12px; width: 100%; max-width: 900px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 50px rgba(0,0,0,.3); transform: scale(0.95); transition: transform 0.3s ease; margin: 0 auto;">
                            <!-- Header -->
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; background: white; z-index: 10;">
                                <h2 style="font-size: 1.5rem; font-weight: 700; margin: 0;" id="approval-modal-title">Details & Beoordeling</h2>
                                <button type="button" onclick="closeApprovalModal()" class="btn btn-error btn-sm" style="font-size: 0.875rem;">
                                    <i class="fa-solid fa-xmark"></i> Sluiten
                                </button>
                            </div>

                            <!-- Content -->
                            <div style="padding: 1.5rem;">
                                <form method="POST" id="approval-form">
                                    <input type="hidden" name="action" value="approve_with_review">
                                    <input type="hidden" name="item_id" id="approval-item-id">
                                    <input type="hidden" name="approval_edit_version" id="approval-edit-version">

                                    <!-- Event Details (Preview) -->
                                    <div style="background: #f9fafb; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;">
                                        <h3 style="font-weight: 700; margin-top: 0; margin-bottom: 1rem; color: #111827;">📋 Event Details (Bewerkbaar)</h3>

                                        <!-- Titel -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Titel:</strong></label>
                                            <input type="text" name="approval_title" id="approval-title-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                        </div>

                                        <!-- Datum, Einddatum, Starttijd, Eindtijd -->
                                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                                            <div>
                                                <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Startdatum:</strong></label>
                                                <input type="date" name="approval_date" id="approval-date-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                            </div>
                                            <div>
                                                <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Einddatum:</strong></label>
                                                <input type="date" name="approval_end_date" id="approval-end-date-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                            </div>
                                            <div>
                                                <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Starttijd:</strong></label>
                                                <input type="time" name="approval_time" id="approval-time-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                            </div>
                                            <div>
                                                <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Eindtijd:</strong></label>
                                                <input type="time" name="approval_time_end" id="approval-time-end-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                            </div>
                                        </div>

                                        <!-- Locatie -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Locatie:</strong></label>
                                            <input type="text" name="approval_location" id="approval-location-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                        </div>

                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Hardware (optioneel):</strong></label>
                                            <textarea name="approval_hardware_request" id="approval-hardware-request-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; min-height: 70px; font-family: inherit;"></textarea>
                                        </div>

                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Wie van SociaalAI Lab zijn erbij? (optioneel):</strong></label>
                                            <textarea name="approval_staff_present" id="approval-staff-present-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; min-height: 70px; font-family: inherit;"></textarea>
                                        </div>

                                        <!-- Beschrijving (CKEditor) -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Beschrijving:</strong></label>
                                            <textarea name="approval_description" id="approval-description-input" data-no-auto-init="true" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; min-height: 100px; font-family: inherit; background: white; color: #111827; display: block; visibility: visible;">
</textarea>
                                        </div>

                                        <!-- Meer info -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Meer info tekst (optioneel):</strong></label>
                                            <textarea name="approval_meer_info" id="approval-meer-info-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; min-height: 80px; font-family: inherit;"></textarea>
                                            <small style="color: #6b7280;">Deze tekst wordt op de evenement detailpagina getoond boven de samenvatting.</small>
                                        </div>

                                        <!-- Event samenvatting -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Samenvatting na afloop (optioneel):</strong></label>
                                            <textarea name="approval_event_summary" id="approval-event-summary-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; min-height: 80px; font-family: inherit;"></textarea>
                                            <small style="color: #6b7280;">Deze samenvatting wordt op de evenement detailpagina getoond zodra deze is ingevuld.</small>
                                        </div>

                                        <!-- Doelgroep -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Doelgroep (optioneel):</strong></label>
                                            <input type="text" name="approval_target_audience" id="approval-target-audience-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;" placeholder="bijv. Scholieren, Docenten, Researchers">
                                            <small style="color: #6b7280;">Alleen zichtbaar voor goedkeuring, niet op de publieke pagina.</small>
                                        </div>

                                        <!-- Meer info voor beoordeling -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Meer info voor beoordeling (voeg ook het doel van het event toe):</strong></label>
                                            <textarea name="approval_internal_notes" id="approval-internal-notes-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; min-height: 60px; font-family: inherit;" placeholder="Beschrijf extra info voor beoordeling en het doel van dit event"></textarea>
                                            <small style="color: #6b7280;">Alleen zichtbaar voor administratoren, niet op de publieke pagina.</small>
                                        </div>

                                        <!-- Signup link -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Aanmelder.nl link (optioneel):</strong></label>
                                            <input type="url" name="approval_signup_embed" id="approval-signup-embed-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;" placeholder="https://aanmelder.nl/subscribe/...">
                                        </div>

                                        <!-- Checkboxes -->
                                        <div style="margin-bottom: 1rem; display: flex; gap: 2rem;">
                                            <label style="display: flex; align-items: center; gap: 0.5rem; color: #111827; font-size: 0.875rem; cursor: pointer;">
                                                <input type="checkbox" name="approval_show_signup_button" id="approval-show-signup-button" checked style="width: 1rem; height: 1rem; cursor: pointer;">
                                                <strong>Toon inschrijf knop</strong>
                                            </label>
                                            <label style="display: flex; align-items: center; gap: 0.5rem; color: #111827; font-size: 0.875rem; cursor: pointer;">
                                                <input type="checkbox" name="approval_show_on_homepage" id="approval-show-on-homepage" checked style="width: 1rem; height: 1rem; cursor: pointer;">
                                                <strong>Toon op homepage</strong>
                                            </label>
                                        </div>

                                        <!-- Afbeelding -->
                                        <div id="approval-image-container" style="margin-top: 1rem; display: none;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.5rem 0; display: block;"><strong>Afbeelding:</strong></label>
                                            <img id="approval-image" src="" alt="Event afbeelding" style="max-width: 100%; max-height: 300px; border-radius: 8px; object-fit: cover; margin-bottom: 0.5rem;">
                                            <input type="file" name="approval_image" id="approval-image-input" class="form-input" accept="image/*" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                            <input type="hidden" name="approval_existing_image" id="approval-existing-image">
                                            <small style="color: #6b7280;">Laat leeg om huidige afbeelding te behouden</small>
                                        </div>

                                        <div id="approval-documents-container" style="margin-top: 1rem; display: none;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.5rem 0; display: block;"><strong>Documenten voor beoordeling:</strong></label>
                                            <ul id="approval-documents-list" style="margin: 0; padding-left: 1rem;"></ul>
                                        </div>
                                    </div>

                                    <!-- Feedback Section -->
                                    <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;">
                                        <h3 style="font-weight: 700; margin-top: 0; margin-bottom: 1rem; color: #92400e;">💬 Terugkoppeling Toevoegen</h3>
                                        <textarea name="approval_feedback" id="approval-feedback" data-no-auto-init="true" class="form-textarea" rows="4" placeholder="Voeg hier feedback/opmerkingen toe..." style="width: 100%; padding: 0.75rem; border: 1px solid #d4a574; border-radius: 4px; font-family: inherit;"></textarea>
                                    </div>

                                    <!-- Actions -->
                                    <div style="display: flex; gap: 1rem; justify-content: flex-end; padding-top: 1rem; border-top: 1px solid #e5e7eb;">
                                        <button type="button" onclick="closeApprovalModal()" class="btn btn-secondary">
                                            <i class="fa-solid fa-times"></i> Annuleren
                                        </button>
                                        <button type="button" onclick="submitApprovalWithFeedback('reject')" class="btn btn-error">
                                            <i class="fa-solid fa-thumbs-down"></i> Afkeuren
                                        </button>
                                        <button type="button" onclick="submitApprovalWithFeedback('approve')" class="btn btn-success">
                                            <i class="fa-solid fa-check-circle"></i> Goedkeuren
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <script>
                        function openApprovalModal(itemId, itemTitle) {
                            const modal = document.getElementById('approval-modal');
                            const formData = new FormData();
                            formData.append('action', 'get_approval_item');
                            formData.append('item_id', itemId);

                            fetch('admin.php', {
                                    method: 'POST',
                                    body: formData
                                })
                                .then(r => r.json())
                                .then(data => {
                                    if (!(data && data.success && data.item)) {
                                        alert('Kon item niet laden: ' + (data && data.error ? data.error : 'Onbekende fout'));
                                        return;
                                    }

                                    const item = data.item;
                                    document.getElementById('approval-item-id').value = itemId;
                                    document.getElementById('approval-edit-version').value = item.edit_version || '';
                                    document.getElementById('approval-modal-title').textContent = 'Details & Beoordeling - ' + itemTitle;

                                    // Basic fields
                                    document.getElementById('approval-title-input').value = item.title || '';
                                    if (item.date) {
                                        const d = new Date(item.date);
                                        document.getElementById('approval-date-input').value = d.toISOString().split('T')[0];
                                    } else {
                                        document.getElementById('approval-date-input').value = '';
                                    }
                                    if (item.end_date) {
                                        const d2 = new Date(item.end_date);
                                        document.getElementById('approval-end-date-input').value = d2.toISOString().split('T')[0];
                                    } else {
                                        document.getElementById('approval-end-date-input').value = '';
                                    }

                                    document.getElementById('approval-time-input').value = item.time || '';
                                    document.getElementById('approval-time-end-input').value = item.time_end || '';
                                    document.getElementById('approval-location-input').value = item.location || '';
                                    document.getElementById('approval-hardware-request-input').value = item.hardware_request || '';
                                    document.getElementById('approval-staff-present-input').value = item.staff_present || '';
                                    document.getElementById('approval-meer-info-input').value = item.meer_info || '';
                                    document.getElementById('approval-event-summary-input').value = item.event_summary || '';
                                    document.getElementById('approval-target-audience-input').value = item.target_audience || '';
                                    document.getElementById('approval-internal-notes-input').value = item.internal_notes || '';
                                    document.getElementById('approval-signup-embed-input').value = item.signup_embed || '';

                                    document.getElementById('approval-show-signup-button').checked = item.show_signup_button !== '0' && item.show_signup_button !== false;
                                    document.getElementById('approval-show-on-homepage').checked = item.show_on_homepage !== '0' && item.show_on_homepage !== false;

                                    // Description: prefer CKEditor instance if present
                                    const descriptionInput = document.getElementById('approval-description-input');
                                    try {
                                        if (descriptionInput && descriptionInput.ckeditorInstance) {
                                            descriptionInput.ckeditorInstance.setData(item.description || '');
                                        } else if (descriptionInput) {
                                            ClassicEditor.create(descriptionInput, {
                                                toolbar: {
                                                    items: ['undo', 'redo', '|', 'bold', 'italic', 'underline', '|', 'bulletedList', 'numberedList', '|', 'link', 'insertTable', '|', 'blockQuote']
                                                },
                                                link: {
                                                    addTargetToExternalLinks: true
                                                }
                                            }).then(editor => {
                                                descriptionInput.ckeditorInstance = editor;
                                                descriptionInput.style.display = 'none';
                                                editor.setData(item.description || '');
                                            }).catch(() => {
                                                // fallback: set textarea value
                                                descriptionInput.value = item.description || '';
                                            });
                                        }
                                    } catch (e) {
                                        if (descriptionInput) descriptionInput.value = item.description || '';
                                    }

                                    document.getElementById('approval-feedback').value = '';
                                    document.getElementById('approval-existing-image').value = item.image || '';

                                    const imageContainer = document.getElementById('approval-image-container');
                                    if (item.image) {
                                        const imageElem = document.getElementById('approval-image');
                                        imageElem.src = 'uploads/' + item.image;
                                        imageContainer.style.display = 'block';
                                    } else {
                                        imageContainer.style.display = 'none';
                                    }
                                    
                                    // Show modal with animation
                                    modal.classList.remove('hidden');
                                    modal.style.display = 'flex';
                                    document.body.classList.add('approval-modal-open');
                                    setTimeout(() => {
                                        modal.style.opacity = '1';
                                        modal.querySelector('div').style.transform = 'scale(1)';
                                    }, 10);
                                })
                                .catch(err => {
                                    alert('Fout bij laden van gegevens: ' + err.message);
                                });
                        }

                        function closeApprovalModal() {
                            const modal = document.getElementById('approval-modal');
                            modal.style.opacity = '0';
                            modal.querySelector('div').style.transform = 'scale(0.95)';
                            setTimeout(() => {
                                modal.classList.add('hidden');
                                modal.style.display = 'none';
                                document.body.classList.remove('approval-modal-open');
                            }, 300);
                        }

                        function submitApprovalWithFeedback(action) {
                            const form = document.getElementById('approval-form');
                            const feedbackInput = document.getElementById('approval-feedback');
                            let feedback = feedbackInput.value || '';
                            const itemId = document.getElementById('approval-item-id').value;
                            const descriptionInput = document.getElementById('approval-description-input');

                            // If feedback field has a CKEditor instance, use its data.
                            if (feedbackInput && feedbackInput.ckeditorInstance) {
                                feedback = feedbackInput.ckeditorInstance.getData() || '';
                            }

                            if (action === 'reject' && !feedback.trim()) {
                                alert('Voeg feedback in voordat je afkeurt!');
                                return;
                            }

                            // Get description from CKEditor if available, otherwise from textarea
                            let descriptionValue = descriptionInput ? descriptionInput.value : '';

                            // Controleer CKEditor 5 instance
                            if (descriptionInput.ckeditorInstance) {
                                descriptionValue = descriptionInput.ckeditorInstance.getData();
                                console.log('[submitApproval] CKEditor content gelezen:', descriptionValue.substring(0, 100));
                            }
                            // Controleer oude CKEDITOR (v4)
                            else if (window.CKEDITOR && window.CKEDITOR.instances && window.CKEDITOR.instances['approval-description-input']) {
                                descriptionValue = window.CKEDITOR.instances['approval-description-input'].getData();
                                console.log('[submitApproval] CKEDITOR v4 content gelezen:', descriptionValue.substring(0, 100));
                            } else {
                                console.log('[submitApproval] Geen CKEditor, textarea value gebruikt:', descriptionValue.substring(0, 100));
                            }

                            // Close modal first
                            closeApprovalModal();

                            // Create a form and submit it directly (not via fetch)
                            const submitForm = document.createElement('form');
                            submitForm.method = 'POST';
                            submitForm.action = 'admin.php';

                            // Add all fields
                            const fields = {
                                'action': action === 'reject' ? 'reject_item' : 'approve_with_review',
                                'item_id': itemId,
                                'approval_edit_version': document.getElementById('approval-edit-version').value,
                                'approval_title': document.getElementById('approval-title-input').value,
                                'approval_date': document.getElementById('approval-date-input').value,
                                'approval_end_date': document.getElementById('approval-end-date-input').value,
                                'approval_time': document.getElementById('approval-time-input').value,
                                'approval_time_end': document.getElementById('approval-time-end-input').value,
                                'approval_location': document.getElementById('approval-location-input').value,
                                'approval_hardware_request': document.getElementById('approval-hardware-request-input').value,
                                'approval_staff_present': document.getElementById('approval-staff-present-input').value,
                                'approval_description': descriptionValue,
                                'approval_meer_info': document.getElementById('approval-meer-info-input').value,
                                'approval_event_summary': document.getElementById('approval-event-summary-input').value,
                                'approval_target_audience': document.getElementById('approval-target-audience-input').value,
                                'approval_internal_notes': document.getElementById('approval-internal-notes-input').value,
                                'approval_signup_embed': document.getElementById('approval-signup-embed-input').value,
                                'approval_show_signup_button': document.getElementById('approval-show-signup-button').checked ? '1' : '0',
                                'approval_show_on_homepage': document.getElementById('approval-show-on-homepage').checked ? '1' : '0',
                                'approval_feedback': feedback
                            };

                            Object.keys(fields).forEach(key => {
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = key;
                                input.value = fields[key];
                                submitForm.appendChild(input);
                            });

                            document.body.appendChild(submitForm);
                            submitForm.submit();
                        }

                        // Close modal when clicking outside
                        document.addEventListener('click', function(e) {
                            const modal = document.getElementById('approval-modal');
                            if (modal && e.target === modal) {
                                closeApprovalModal();
                            }
                        });

                        // Close modal on ESC key
                        document.addEventListener('keydown', function(e) {
                            if (e.key === 'Escape') {
                                const modal = document.getElementById('approval-modal');
                                if (modal && !modal.classList.contains('hidden')) {
                                    closeApprovalModal();
                                }
                            }
                        });
                    </script>



                <?php elseif ($page === 'audit'): ?>
                    <div class="card p-6">
                        <div class="flex items-center gap-2 mb-4 pb-4 border-b-2 border-gray-200">
                            <i class="fa-solid fa-clipboard-list text-2xl text-[#00811F]"></i>
                            <h2 class="text-2xl font-bold">Auditlogboek</h2>
                        </div>

                        <form method="GET" class="bg-white p-4 rounded border border-gray-200 mb-6">
                            <input type="hidden" name="page" value="audit">
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                                <div>
                                    <label class="form-label">Actie</label>
                                    <select name="audit_action" class="form-input">
                                        <option value="">Alle acties</option>
                                        <?php foreach ($auditActions as $action): ?>
                                            <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $auditActionFilter === $action ? 'selected' : ''; ?>><?php echo htmlspecialchars($action); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Tabel</label>
                                    <select name="audit_table" class="form-input">
                                        <option value="">Alle tabellen</option>
                                        <?php foreach ($auditTables as $tableName): ?>
                                            <option value="<?php echo htmlspecialchars($tableName); ?>" <?php echo $auditTableFilter === $tableName ? 'selected' : ''; ?>><?php echo htmlspecialchars($tableName); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Per pagina</label>
                                    <select name="audit_per_page" class="form-input">
                                        <?php foreach ($auditPerPageOptions as $perPageOption): ?>
                                            <option value="<?php echo (int)$perPageOption; ?>" <?php echo $auditPerPage === (int)$perPageOption ? 'selected' : ''; ?>>
                                                <?php echo (int)$perPageOption; ?> regels
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="audit-filter-actions flex gap-2">
                                    <button type="submit" class="btn btn-audit-accent">
                                        <i class="fa-solid fa-filter"></i> Filter
                                    </button>
                                    <a href="admin.php?page=audit" class="btn btn-audit-accent">
                                        <i class="fa-solid fa-rotate-left"></i> Reset
                                    </a>
                                </div>
                            </div>
                        </form>

                        <div class="mb-4 text-sm text-gray-600">
                            Totaal logregels: <strong><?php echo (int)$auditTotal; ?></strong> - Per pagina: <strong><?php echo (int)$auditPerPage; ?></strong>
                        </div>

                        <?php if (empty($auditLogs)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fa-solid fa-inbox text-4xl mb-2"></i>
                                <p>Geen auditregels gevonden voor deze filter.</p>
                            </div>
                        <?php else: ?>
                            <div class="audit-table-wrap overflow-x-auto border border-gray-200 rounded-lg">
                                <table class="audit-table w-full text-sm">
                                    <thead class="bg-gray-100 text-left">
                                        <tr>
                                            <th class="p-3">Datum</th>
                                            <th class="p-3">Actie</th>
                                            <th class="p-3">Tabel</th>
                                            <th class="p-3">Record ID</th>
                                            <th class="p-3">Details</th>
                                            <th class="p-3">Gebruiker</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($auditLogs as $log): ?>
                                            <?php
                                            $createdAt = !empty($log['created_at']) ? date('d-m-Y H:i:s', strtotime($log['created_at'])) : '-';
                                            $detailsRaw = trim((string)($log['details'] ?? ''));
                                            $detailsPreview = $detailsRaw;
                                            if (strlen($detailsPreview) > 200) {
                                                $detailsPreview = substr($detailsPreview, 0, 197) . '...';
                                            }
                                            ?>
                                            <tr class="border-t border-gray-200 align-top">
                                                <td class="p-3 whitespace-nowrap" data-label="Datum"><?php echo htmlspecialchars($createdAt); ?></td>
                                                <td class="p-3" data-label="Actie"><?php echo htmlspecialchars((string)$log['action']); ?></td>
                                                <td class="p-3" data-label="Tabel"><?php echo htmlspecialchars((string)$log['table_name']); ?></td>
                                                <td class="p-3" data-label="Record ID"><?php echo htmlspecialchars((string)($log['record_id'] ?? '-')); ?></td>
                                                <td class="p-3 text-gray-700 audit-details" data-label="Details">
                                                    <div class="audit-details-content"><?php echo nl2br(htmlspecialchars($detailsPreview !== '' ? $detailsPreview : '-')); ?></div>
                                                    <?php if ($detailsRaw !== '' && $detailsRaw !== $detailsPreview): ?>
                                                        <details class="audit-details-more">
                                                            <summary>Meer details</summary>
                                                            <div class="audit-details-content"><?php echo nl2br(htmlspecialchars($detailsRaw)); ?></div>
                                                        </details>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-3" data-label="Gebruiker"><?php echo htmlspecialchars((string)($log['performed_by'] ?? '-')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php
                            $queryBase = 'page=audit';
                            if ($auditActionFilter !== '') {
                                $queryBase .= '&audit_action=' . urlencode($auditActionFilter);
                            }
                            if ($auditTableFilter !== '') {
                                $queryBase .= '&audit_table=' . urlencode($auditTableFilter);
                            }
                            if ($auditPerPage !== 50) {
                                $queryBase .= '&audit_per_page=' . (int)$auditPerPage;
                            }
                            ?>
                            <div class="audit-pagination flex items-center justify-between mt-4">
                                <div class="text-sm text-gray-600">
                                    Pagina <?php echo (int)$auditPage; ?> van <?php echo (int)$auditTotalPages; ?>
                                </div>
                                <div class="flex gap-2">
                                    <?php if ($auditPage > 1): ?>
                                        <a href="admin.php?<?php echo $queryBase; ?>&audit_page=<?php echo (int)($auditPage - 1); ?>" class="btn btn-secondary btn-sm">
                                            <i class="fa-solid fa-chevron-left"></i> Vorige
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($auditPage < $auditTotalPages): ?>
                                        <a href="admin.php?<?php echo $queryBase; ?>&audit_page=<?php echo (int)($auditPage + 1); ?>" class="btn btn-secondary btn-sm">
                                            Volgende <i class="fa-solid fa-chevron-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($page === 'aanvragen'): ?>
                    <!-- Mijn Aanvragen (Requests) Page for Onderzoekers -->
                    <div class="card p-6">
                        <div class="flex items-center gap-2 mb-4 pb-4 border-b-2 border-gray-200">
                            <i class="fa-solid fa-paper-plane text-2xl text-[#00811F]"></i>
                            <h2 class="text-2xl font-bold">Mijn Aanvragen</h2>
                        </div>

                        <?php
                        // Get rejected items for current user
                        $stmt = $pdo->prepare(
                            'SELECT id, title, date, location, description, image, event_documents, approval_status, approval_feedback, created_at
                             FROM events 
                             WHERE created_by = ? AND approval_status IN ("rejected", "pending")
                             ORDER BY created_at DESC'
                        );
                        $stmt->execute([$currentUser]);
                        $myRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>

                        <?php if (empty($myRequests)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fa-solid fa-inbox text-4xl mb-2"></i>
                                <p>Geen aangevraagde items of ze zijn allemaal goedgekeurd.</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($myRequests as $request): ?>
                                    <div class="border border-gray-200 rounded-lg p-4 <?php echo $request['approval_status'] === 'rejected' ? 'bg-red-50' : 'bg-yellow-50'; ?>">
                                        <div class="flex items-start justify-between mb-3">
                                            <div class="flex-1">
                                                <h3 class="text-lg font-bold"><?php echo htmlspecialchars($request['title']); ?></h3>
                                                <p class="text-sm text-gray-600">
                                                    <i class="fa-solid fa-calendar"></i> <?php echo date('d-m-Y', strtotime($request['date'])); ?>
                                                    <?php if (!empty($request['location'])): ?>
                                                        | <i class="fa-solid fa-map-pin"></i> <?php echo htmlspecialchars($request['location']); ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                            <span class="badge <?php echo $request['approval_status'] === 'rejected' ? 'bg-red-500' : 'bg-yellow-500'; ?>">
                                                <?php echo ucfirst($request['approval_status']); ?>
                                            </span>
                                        </div>

                                        <!-- Show rejection reason if rejected -->
                                        <?php if ($request['approval_status'] === 'rejected' && !empty($request['approval_feedback'])): ?>
                                            <div class="bg-red-100 border border-red-300 rounded p-3 mb-3">
                                                <p class="text-sm font-semibold text-red-700 mb-1">
                                                    <i class="fa-solid fa-exclamation-circle"></i> Reden van afkeuring:
                                                </p>
                                                <p class="text-sm text-red-900"><?php echo nl2br(htmlspecialchars($request['approval_feedback'])); ?></p>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Description preview -->
                                        <p class="text-sm text-gray-700 mb-3">
                                            <?php
                                            $desc = strip_tags($request['description']);
                                            echo htmlspecialchars(substr($desc, 0, 150)) . (strlen($desc) > 150 ? '...' : '');
                                            ?>
                                        </p>

                                        <?php $requestDocuments = decodeEventDocuments($request['event_documents'] ?? null); ?>
                                        <?php if (!empty($requestDocuments)): ?>
                                            <p class="text-sm text-gray-700 mb-3"><strong>Documenten voor beoordeling:</strong> <?php echo count($requestDocuments); ?></p>
                                        <?php endif; ?>

                                        <!-- Edit button -->
                                        <?php if ($request['approval_status'] === 'rejected'): ?>
                                            <button type="button" class="btn btn-primary btn-sm" onclick="openEditRequestModal(<?php echo (int)$request['id']; ?>, '<?php echo htmlspecialchars($request['title'], ENT_QUOTES); ?>')">
                                                <i class="fa-solid fa-pencil"></i> Aanpassen & Opnieuw Indienen
                                            </button>
                                        <?php elseif ($request['approval_status'] === 'pending'): ?>
                                            <span class="text-sm text-yellow-700">
                                                <i class="fa-solid fa-hourglass"></i> In afwachting van beoordeling...
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Edit Request Modal -->
                    <div id="edit-request-modal" class="hidden" style="position: fixed; inset: 0; z-index: 9998; background: rgba(0,0,0,.5); display: none; align-items: center; justify-content: center; padding: 1rem; opacity: 0; transition: opacity 0.3s ease;">
                        <div style="background: white; border-radius: 12px; width: 100%; max-width: 900px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 50px rgba(0,0,0,.3); transform: scale(0.95); transition: transform 0.3s ease;">
                            <!-- Header -->
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; background: white; z-index: 10;">
                                <h2 style="font-size: 1.5rem; font-weight: 700; margin: 0;" id="edit-request-title">Aanvraag Aanpassen</h2>
                                <button type="button" onclick="closeEditRequestModal()" class="btn btn-error btn-sm" style="font-size: 0.875rem;">
                                    <i class="fa-solid fa-xmark"></i> Sluiten
                                </button>
                            </div>

                            <!-- Content -->
                            <div style="padding: 1.5rem;">
                                <form method="POST" id="edit-request-form" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="resubmit_request">
                                    <input type="hidden" name="request_id" id="edit-request-id">
                                    <input type="hidden" name="edit_version" id="edit-request-version">

                                    <!-- Admin Feedback Section -->
                                    <div id="feedback-section" style="background: #fee2e2; border-left: 4px solid #ef4444; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; display: none;">
                                        <h3 style="font-weight: 700; margin-top: 0; margin-bottom: 0.5rem; color: #991b1b;">⚠️ Feedback van Beheerder</h3>
                                        <p id="feedback-text" style="margin: 0; color: #7f1d1d; white-space: pre-wrap; line-height: 1.5;"></p>
                                    </div>

                                    <!-- Event Details -->
                                    <div style="background: #f9fafb; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;">
                                        <h3 style="font-weight: 700; margin-top: 0; margin-bottom: 1rem; color: #111827;">📋 Event Details</h3>

                                        <!-- Titel -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Titel:</strong></label>
                                            <input type="text" name="edit_title" id="edit-title-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                        </div>

                                        <!-- Datum, Einddatum, Starttijd, Eindtijd -->
                                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                                            <div>
                                                <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Startdatum:</strong></label>
                                                <input type="date" name="edit_date" id="edit-date-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                            </div>
                                            <div>
                                                <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Einddatum:</strong></label>
                                                <input type="date" name="edit_end_date" id="edit-end-date-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                            </div>
                                            <div>
                                                <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Starttijd:</strong></label>
                                                <input type="time" name="edit_time" id="edit-time-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                            </div>
                                            <div>
                                                <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Eindtijd:</strong></label>
                                                <input type="time" name="edit_time_end" id="edit-time-end-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                            </div>
                                        </div>

                                        <!-- Locatie -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Locatie:</strong></label>
                                            <input type="text" name="edit_location" id="edit-location-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                        </div>

                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Hardware (optioneel):</strong></label>
                                            <textarea name="edit_hardware_request" id="edit-hardware-request-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; min-height: 70px; font-family: inherit;"></textarea>
                                        </div>

                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Wie van SociaalAI Lab zijn erbij? (optioneel):</strong></label>
                                            <textarea name="edit_staff_present" id="edit-staff-present-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; min-height: 70px; font-family: inherit;"></textarea>
                                        </div>

                                        <!-- Beschrijving -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Beschrijving:</strong></label>
                                            <textarea name="edit_description" id="edit-description-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; min-height: 100px; font-family: inherit;"></textarea>
                                        </div>

                                        <!-- Meer info -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Meer info tekst (optioneel):</strong></label>
                                            <textarea name="edit_meer_info" id="edit-meer-info-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; min-height: 80px; font-family: inherit;"></textarea>
                                        </div>

                                        <!-- Samenvatting -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Samenvatting na afloop (optioneel):</strong></label>
                                            <textarea name="edit_event_summary" id="edit-event-summary-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; min-height: 80px; font-family: inherit;"></textarea>
                                        </div>

                                        <!-- Doelgroep -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Doelgroep (optioneel):</strong></label>
                                            <input type="text" name="edit_target_audience" id="edit-target-audience-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;" placeholder="bijv. Scholieren, Docenten, Researchers">
                                        </div>

                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Meer info voor beoordeling (voeg ook het doel van het event toe):</strong></label>
                                            <textarea name="edit_internal_notes" id="edit-internal-notes-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; min-height: 60px; font-family: inherit;" placeholder="Beschrijf extra info voor beoordeling en het doel van dit event"></textarea>
                                            <small style="color: #6b7280;">Alleen zichtbaar voor administratoren, niet op de publieke pagina.</small>
                                        </div>

                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Documenten voor beoordeling (optioneel):</strong></label>
                                            <input type="file" name="edit_event_documents[]" id="edit-event-documents-input" class="form-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt" multiple style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                                            <small style="color: #6b7280;">Alleen zichtbaar in de beoordelingsflow.</small>
                                            <div id="edit-documents-container" style="margin-top: 0.75rem; display: none;">
                                                <div id="edit-documents-list" style="display: flex; flex-direction: column; gap: 0.5rem;"></div>
                                            </div>
                                        </div>

                                        <!-- Signup link -->
                                        <div style="margin-bottom: 1rem;">
                                            <label style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.25rem 0; display: block;"><strong>Aanmelder.nl link (optioneel):</strong></label>
                                            <input type="url" name="edit_signup_embed" id="edit-signup-embed-input" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;" placeholder="https://aanmelder.nl/subscribe/...">
                                        </div>

                                        <!-- Checkboxes -->
                                        <div style="margin-bottom: 1rem; display: flex; gap: 2rem;">
                                            <label style="display: flex; align-items: center; gap: 0.5rem; color: #111827; font-size: 0.875rem; cursor: pointer;">
                                                <input type="checkbox" name="edit_show_signup_button" id="edit-show-signup-button" checked style="width: 1rem; height: 1rem; cursor: pointer;">
                                                <strong>Toon inschrijf knop</strong>
                                            </label>
                                            <label style="display: flex; align-items: center; gap: 0.5rem; color: #111827; font-size: 0.875rem; cursor: pointer;">
                                                <input type="checkbox" name="edit_show_on_homepage" id="edit-show-on-homepage" checked style="width: 1rem; height: 1rem; cursor: pointer;">
                                                <strong>Toon op homepage</strong>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div style="display: flex; gap: 1rem; justify-content: flex-end; padding-top: 1rem; border-top: 1px solid #e5e7eb;">
                                        <button type="button" onclick="closeEditRequestModal()" class="btn btn-secondary">
                                            <i class="fa-solid fa-times"></i> Annuleren
                                        </button>
                                        <button type="submit" class="btn btn-success">
                                            <i class="fa-solid fa-check-circle"></i> Opnieuw Indienen
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <script>
                        function openEditRequestModal(requestId, requestTitle) {
                            const modal = document.getElementById('edit-request-modal');
                            const formData = new FormData();
                            formData.append('action', 'get_request_details');
                            formData.append('request_id', requestId);

                            fetch('admin.php', {
                                    method: 'POST',
                                    body: formData
                                })
                                .then(r => r.json())
                                .then(data => {
                                    if (!(data && data.success && data.item)) {
                                        alert('Kon aanvraag niet laden');
                                        return;
                                    }

                                    const item = data.item;
                                    document.getElementById('edit-request-id').value = requestId;
                                    document.getElementById('edit-request-version').value = item.edit_version || '';
                                    document.getElementById('edit-request-title').textContent = 'Aanvraag Aanpassen - ' + requestTitle;

                                    document.getElementById('edit-title-input').value = item.title || '';
                                    if (item.date) {
                                        const d = new Date(item.date);
                                        document.getElementById('edit-date-input').value = d.toISOString().split('T')[0];
                                    } else {
                                        document.getElementById('edit-date-input').value = '';
                                    }
                                    if (item.end_date) {
                                        const d2 = new Date(item.end_date);
                                        document.getElementById('edit-end-date-input').value = d2.toISOString().split('T')[0];
                                    } else {
                                        document.getElementById('edit-end-date-input').value = '';
                                    }

                                    document.getElementById('edit-time-input').value = item.time || '';
                                    document.getElementById('edit-time-end-input').value = item.time_end || '';
                                    document.getElementById('edit-location-input').value = item.location || '';
                                    document.getElementById('edit-hardware-request-input').value = item.hardware_request || '';
                                    document.getElementById('edit-staff-present-input').value = item.staff_present || '';
                                    document.getElementById('edit-description-input').value = item.description || '';
                                    document.getElementById('edit-meer-info-input').value = item.meer_info || '';
                                    document.getElementById('edit-event-summary-input').value = item.event_summary || '';
                                    document.getElementById('edit-target-audience-input').value = item.target_audience || '';
                                    document.getElementById('edit-internal-notes-input').value = item.internal_notes || '';
                                    document.getElementById('edit-signup-embed-input').value = item.signup_embed || '';
                                    
                                    // Checkboxes
                                    document.getElementById('edit-show-signup-button').checked = item.show_signup_button !== '0' && item.show_signup_button !== false;
                                    document.getElementById('edit-show-on-homepage').checked = item.show_on_homepage !== '0' && item.show_on_homepage !== false;

                                    const feedbackSection = document.getElementById('feedback-section');
                                    if (item.approval_feedback) {
                                        document.getElementById('feedback-text').textContent = item.approval_feedback;
                                        feedbackSection.style.display = 'block';
                                    } else {
                                        feedbackSection.style.display = 'none';
                                    }

                                    modal.classList.remove('hidden');
                                    modal.style.display = 'flex';
                                    setTimeout(() => {
                                        modal.style.opacity = '1';
                                        modal.querySelector('div').style.transform = 'scale(1)';
                                    }, 10);
                                })
                                .catch(err => alert('Fout: ' + err.message));
                        }

                        function closeEditRequestModal() {
                            const modal = document.getElementById('edit-request-modal');
                            modal.style.opacity = '0';
                            modal.querySelector('div').style.transform = 'scale(0.95)';
                            setTimeout(() => {
                                modal.classList.add('hidden');
                                modal.style.display = 'none';
                            }, 300);
                        }

                        document.addEventListener('keydown', function(e) {
                            if (e.key === 'Escape') {
                                const modal = document.getElementById('edit-request-modal');
                                if (modal && !modal.classList.contains('hidden')) {
                                    closeEditRequestModal();
                                }
                            }
                        });
                    </script>

                <?php elseif ($page != 'banner'): ?>
                    <?php if ($page === 'dashboard'): ?>
                        <?php
                        // Minimal dashboard aggregations — safe queries with fallbacks
                        try {
                            $totalEvents = (int) $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
                        } catch (Exception $e) {
                            $totalEvents = 0;
                        }
                        try {
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE COALESCE(end_date, date) >= CURDATE()");
                            $stmt->execute();
                            $totalUpcoming = (int) $stmt->fetchColumn();
                        } catch (Exception $e) {
                            $totalUpcoming = 0;
                        }
                        try {
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE COALESCE(end_date, date) < CURDATE()");
                            $stmt->execute();
                            $totalPast = (int) $stmt->fetchColumn();
                        } catch (Exception $e) {
                            $totalPast = 0;
                        }
                        // Registrations
                        try {
                            $totalRegs = (int) $pdo->query("SELECT COUNT(*) FROM inschrijven")->fetchColumn();
                            $topRegsStmt = $pdo->prepare("SELECT event_id, event_title, COUNT(*) AS cnt FROM inschrijven GROUP BY event_id, event_title ORDER BY cnt DESC LIMIT 5");
                            $topRegsStmt->execute();
                            $topRegs = $topRegsStmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {
                            $totalRegs = 0;
                            $topRegs = [];
                        }
                        // Bookings
                        try {
                            $totalBookings = (int) $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
                            $bookingsByLocStmt = $pdo->prepare("SELECT COALESCE(location_description, CONCAT('loc-', location_id)) AS loc, COUNT(*) AS cnt FROM bookings GROUP BY loc ORDER BY cnt DESC LIMIT 8");
                            $bookingsByLocStmt->execute();
                            $bookingsByLoc = $bookingsByLocStmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {
                            $totalBookings = 0;
                            $bookingsByLoc = [];
                        }
                        // Partners (from pages.meta partner keys)
                        $partners = [];
                        try {
                            $pages = $pdo->query("SELECT meta FROM pages")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($pages as $meta) {
                                if (!$meta) continue;
                                $arr = json_decode($meta, true);
                                if (!is_array($arr)) continue;
                                if (!empty($arr['partner'])) {
                                    $p = trim((string)$arr['partner']);
                                    if ($p !== '') $partners[] = $p;
                                }
                            }
                        } catch (Exception $e) {
                            // ignore
                        }
                        $partnerCounts = [];
                        foreach ($partners as $p) $partnerCounts[$p] = ($partnerCounts[$p] ?? 0) + 1;
                        arsort($partnerCounts);
                        $topPartners = array_slice($partnerCounts, 0, 8, true);

                        // Simple KPI calculations
                        $kpi = [
                            'occupancy_rate' => null,
                            'codesign_percent' => null,
                            'companies_percent' => null,
                        ];
                        try {
                            // occupancy: bookings in last 30 days / (days * distinct locations) * 100
                            $days = 30;
                            $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
                            $endDate = date('Y-m-d');
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE booking_date BETWEEN ? AND ?");
                            $stmt->execute([$startDate, $endDate]);
                            $bookingsLast30 = (int)$stmt->fetchColumn();
                            $locCount = (int)$pdo->query("SELECT COUNT(DISTINCT location_id) FROM bookings WHERE location_id IS NOT NULL")->fetchColumn();
                            if ($locCount > 0) {
                                $slotsBase = $days * $locCount; // simplistic slots base
                                $kpi['occupancy_rate'] = round(($bookingsLast30 / max(1, $slotsBase)) * 100, 1);
                            }
                        } catch (Exception $e) {
                            // leave null
                        }

                        try {
                            // co-design share: events in last 90 days mentioning co-design
                            $daysCo = 90;
                            $startCo = date('Y-m-d', strtotime('-' . ($daysCo - 1) . ' days'));
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE COALESCE(end_date, date) >= ? AND (title LIKE ? OR description LIKE ?)");
                            $stmt->execute([$startCo, '%co-design%', '%co-design%']);
                            $coCount = (int)$stmt->fetchColumn();
                            $totalRecent = 0;
                            $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM events WHERE COALESCE(end_date, date) >= ?");
                            if ($stmt2->execute([$startCo])) {
                                $totalRecent = (int)$stmt2->fetchColumn();
                            }
                            if ($totalRecent > 0) {
                                $kpi['codesign_percent'] = round(($coCount / $totalRecent) * 100, 1);
                            }
                        } catch (Exception $e) {
                            // ignore
                        }

                        try {
                            // companies percent from partner names heuristics (bv, ltd, bedrijf, stichting, vereniging)
                            $companyKeywords = ['bv', 'ltd', 'company', 'bedrijf', 'inc', 'gmbh'];
                            $orgKeywords = ['stichting', 'vereniging', 'non-profit', 'ngo', 'organisatie'];
                            $company = 0;
                            $org = 0;
                            $totalP = 0;
                            foreach ($partnerCounts as $name => $cnt) {
                                $totalP += $cnt;
                                $lower = strtolower($name);
                                $isCompany = false;
                                $isOrg = false;
                                foreach ($companyKeywords as $kw) if (strpos($lower, $kw) !== false) $isCompany = true;
                                foreach ($orgKeywords as $kw) if (strpos($lower, $kw) !== false) $isOrg = true;
                                if ($isCompany) $company += $cnt;
                                elseif ($isOrg) $org += $cnt;
                            }
                            if ($totalP > 0) {
                                $kpi['companies_percent'] = round(($company / $totalP) * 100, 1);
                            }
                        } catch (Exception $e) {
                            // ignore
                        }
                        ?>
                        <div class="card p-6">
                            <div class="flex items-center gap-2 mb-4 pb-4 border-b-2 border-gray-200">
                                <i class="fa-solid fa-chart-pie text-2xl text-[#00811F]"></i>
                                <h2 class="text-2xl font-bold">Dashboard — Overzicht & Monitoring</h2>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                                <a href="agenda.php" class="kpi-card">
                                    <div class="kpi-title">Totaal evenementen</div>
                                    <div class="kpi-value"><?php echo $totalEvents; ?></div>
                                    <div class="kpi-meta">Aankomend: <?php echo $totalUpcoming; ?> — Terugblik: <?php echo $totalPast; ?></div>
                                </a>
                                <a href="inschrijven.php" class="kpi-card">
                                    <div class="kpi-title">Inschrijvingen (totaal)</div>
                                    <div class="kpi-value"><?php echo $totalRegs; ?></div>
                                    <div class="kpi-meta">Top geregistreerde events hieronder</div>
                                </a>
                                <a href="booking.php" class="kpi-card">
                                    <div class="kpi-title">Bookings (totaal)</div>
                                    <div class="kpi-value"><?php echo $totalBookings; ?></div>
                                    <div class="kpi-meta">Verdeling per locatie</div>
                                </a>
                                <a href="terugblikken.php" class="kpi-card">
                                    <div class="kpi-title">Top partners</div>
                                    <div class="kpi-value"><?php echo count($partnerCounts); ?></div>
                                    <div class="kpi-meta">Partners uit pagina-meta</div>
                                </a>
                            </div>

                            <div class="dashboard-grid">
                                <div class="chart-card">
                                    <h3 class="font-semibold mb-2">Top geregistreerde events</h3>
                                    <?php if (empty($topRegs)): ?>
                                        <div class="text-sm text-gray-600">Geen registratiegegevens beschikbaar.</div>
                                    <?php else: ?>
                                        <canvas id="chartTopRegs"></canvas>
                                    <?php endif; ?>
                                </div>

                                <div class="chart-card">
                                    <h3 class="font-semibold mb-2">Bookings per locatie (top)</h3>
                                    <?php if (empty($bookingsByLoc)): ?>
                                        <div class="text-sm text-gray-600">Geen bookings data.</div>
                                    <?php else: ?>
                                        <canvas id="chartBookingsByLoc"></canvas>
                                    <?php endif; ?>
                                </div>

                                <div class="chart-card">
                                    <h3 class="font-semibold mb-2">Partner netwerk (top)</h3>
                                    <?php if (empty($topPartners)): ?>
                                        <div class="text-sm text-gray-600">Geen partner-gegevens gevonden in pagina-meta.</div>
                                    <?php else: ?>
                                        <canvas id="chartTopPartners"></canvas>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mt-6 text-sm text-gray-600">
                                <strong>Impact per doelstelling</strong>: kolommen en scores tonen we zodra score-velden beschikbaar zijn in de dataset.
                                <br>
                                <strong>Kern-KPI's (schatting)</strong>:
                                <ul class="mt-2 text-sm">
                                    <li>Bezettingsgraad (laatste 30d): <strong><?php echo is_null($kpi['occupancy_rate']) ? 'n.v.t.' : ($kpi['occupancy_rate'] . '%'); ?></strong></li>
                                    <li>Co-design activiteiten (laatste 90d): <strong><?php echo is_null($kpi['codesign_percent']) ? 'n.v.t.' : ($kpi['codesign_percent'] . '%'); ?></strong></li>
                                    <li>Partners: aandeel bedrijven (heuristiek): <strong><?php echo is_null($kpi['companies_percent']) ? 'n.v.t.' : ($kpi['companies_percent'] . '%'); ?></strong></li>
                                </ul>
                            </div>

                            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
                            <style>
                                /* Compact chart heights for dashboard */
                                #chartTopRegs,
                                #chartBookingsByLoc {
                                    height: 200px !important;
                                }

                                /* Per-canvas height hints to keep charts compact and consistent */
                                #chartTopRegs {
                                    height: 260px !important;
                                }

                                #chartBookingsByLoc {
                                    height: 220px !important;
                                }

                                #chartTopPartners {
                                    height: 200px !important;
                                }

                                /* .chart-wrapper moved to admin-styles.css */

                                .chart-container {
                                    position: relative;
                                    width: 100%;
                                    max-width: 820px;
                                    /* keep charts from stretching too wide */
                                    margin: 0 auto;
                                    padding: 12px 0;
                                }

                                /* Excel-like canvas styling: white background and subtle border */
                                .card canvas {
                                    background: #fff;
                                    box-shadow: none;
                                    border: 1px solid rgba(0, 0, 0, 0.06);
                                    display: block;
                                    margin: 0 auto;
                                    max-width: 100%;
                                }
                            </style>
                            <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    const truncate = function(label, maxLen = 28) {
                                        if (!label) return '';
                                        const s = String(label);
                                        return s.length > maxLen ? s.substr(0, maxLen - 1) + '…' : s;
                                    };

                                    const baseOptions = (maxTicks = 6) => {
                                        const w = Math.max(window.innerWidth || 1024, 320);
                                        const isMobile = w < 640;
                                        const fontSize = isMobile ? 11 : (w < 1024 ? 12 : 13);
                                        const titleSize = isMobile ? 15 : 18;

                                        return {
                                            responsive: true,
                                            maintainAspectRatio: false,
                                            aspectRatio: isMobile ? 1.0 : (w < 1024 ? 1.2 : 1.8),
                                            animation: {
                                                duration: 600,
                                                easing: 'easeOutQuart'
                                            },
                                            plugins: {
                                                legend: {
                                                    display: true,
                                                    position: 'top',
                                                    labels: {
                                                        color: '#374151',
                                                        font: {
                                                            size: isMobile ? 12 : 13,
                                                            weight: 600
                                                        }
                                                    }
                                                },
                                                tooltip: {
                                                    enabled: true,
                                                    backgroundColor: 'rgba(17,24,39,0.95)',
                                                    titleFont: {
                                                        size: titleSize,
                                                        weight: 700
                                                    },
                                                    bodyFont: {
                                                        size: fontSize + 1,
                                                        weight: 500
                                                    },
                                                    padding: 10,
                                                    cornerRadius: 8,
                                                    displayColors: false,
                                                    callbacks: {
                                                        label: function(ctx) {
                                                            const v = ctx.raw;
                                                            return (typeof v === 'number') ? v.toString() : String(v);
                                                        }
                                                    }
                                                }
                                            },
                                            layout: {
                                                padding: {
                                                    top: 12,
                                                    right: 8,
                                                    left: 8,
                                                    bottom: 8
                                                }
                                            },
                                            scales: {
                                                x: {
                                                    grid: {
                                                        display: false
                                                    },
                                                    ticks: {
                                                        color: '#374151',
                                                        font: {
                                                            size: Math.max(12, fontSize)
                                                        },
                                                        callback: function(t) {
                                                            return truncate(this.getLabelForValue(t), isMobile ? 18 : 40);
                                                        },
                                                        maxRotation: 45,
                                                        minRotation: 45,
                                                        autoSkip: false,
                                                        maxTicksLimit: maxTicks
                                                    }
                                                },
                                                y: {
                                                    grid: {
                                                        color: 'rgba(0,0,0,0.06)',
                                                        drawBorder: false
                                                    },
                                                    beginAtZero: true,
                                                    ticks: {
                                                        color: '#374151',
                                                        font: {
                                                            size: Math.max(12, fontSize)
                                                        },
                                                        precision: 0
                                                    },
                                                    title: {
                                                        display: true,
                                                        text: 'Aantal',
                                                        color: '#6b7280',
                                                        font: {
                                                            size: Math.max(12, fontSize),
                                                            weight: 600
                                                        }
                                                    }
                                                }
                                            },
                                            fonts: {
                                                family: 'Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial'
                                            }
                                        };
                                    };

                                    // improved plugin to draw values on bars with responsive font and overlap avoidance
                                    const valueLabelsPlugin = {
                                        id: 'valueLabels',
                                        afterDatasetsDraw(chart) {
                                            const ctx = chart.ctx;
                                            const scaleFactor = Math.max(1, Math.min(chart.width / 800, 1.6));
                                            const baseFontSize = Math.round(12 * scaleFactor);
                                            ctx.font = baseFontSize + 'px system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial';
                                            ctx.fillStyle = '#111827';

                                            chart.data.datasets.forEach((dataset, dsIndex) => {
                                                const meta = chart.getDatasetMeta(dsIndex);
                                                if (!meta || !meta.data) return;
                                                meta.data.forEach((el, index) => {
                                                    const val = dataset.data[index];
                                                    if (val === null || typeof val === 'undefined') return;

                                                    const pos = el.tooltipPosition();
                                                    ctx.save();
                                                    const isHorizontal = chart.options.indexAxis === 'y';

                                                    // Only render labels when there is space (avoid overlap on very small bars)
                                                    const box = el.getProps(['base', 'x', 'y', 'width', 'height'], true);
                                                    const minSize = 18 * scaleFactor; // minimum pixel height/width to show label
                                                    const canShow = isHorizontal ? Math.abs(box.width) > minSize : Math.abs(box.height) > minSize;
                                                    if (!canShow) {
                                                        ctx.restore();
                                                        return;
                                                    }

                                                    if (isHorizontal) {
                                                        ctx.textAlign = 'left';
                                                        ctx.textBaseline = 'middle';
                                                        ctx.fillText(String(val), pos.x + 8, pos.y);
                                                    } else {
                                                        ctx.textAlign = 'center';
                                                        ctx.textBaseline = 'bottom';
                                                        ctx.fillText(String(val), pos.x, pos.y - 6);
                                                    }
                                                    ctx.restore();
                                                });
                                            });
                                        }
                                    };
                                    // register locally to avoid global mutation (Chart.js auto-registration not guaranteed)
                                    if (typeof Chart !== 'undefined' && Chart.register) Chart.register(valueLabelsPlugin);

                                    // Top regs
                                    <?php if (!empty($topRegs)): ?>
                                            (function() {
                                                const labels = <?php echo json_encode(array_map(function ($r) {
                                                                    return $r['event_title'] ?: ('Event ' + $r['event_id']);
                                                                }, $topRegs)); ?>;
                                                const data = <?php echo json_encode(array_map(function ($r) {
                                                                    return (int)$r['cnt'];
                                                                }, $topRegs)); ?>;
                                                const ctx = document.getElementById('chartTopRegs');
                                                if (ctx) {
                                                    // Use a consistent blue palette for a professional look
                                                    const primaryBlue = '#2f6fbf';
                                                    const ctx2 = ctx.getContext('2d');
                                                    const grad = ctx2.createLinearGradient(0, 0, 0, ctx2.canvas.height || 200);
                                                    grad.addColorStop(0, '#7fb3ff');
                                                    grad.addColorStop(1, primaryBlue);
                                                    const bg = labels.map(() => grad);
                                                    const w = Math.max(window.innerWidth || 1024, 320);
                                                    const isMobile = w < 640;
                                                    const titleSize = isMobile ? 15 : 18;
                                                    new Chart(ctx.getContext('2d'), {
                                                        type: 'bar',
                                                        data: {
                                                            labels: labels.map(l => truncate(l, 28)),
                                                            datasets: [{
                                                                label: 'Inschrijvingen',
                                                                data: data,
                                                                backgroundColor: bg,
                                                                borderRadius: 12,
                                                                borderColor: 'rgba(47,111,191,0.12)',
                                                                borderWidth: 1,
                                                                borderSkipped: false,
                                                                barPercentage: 0.65,
                                                                categoryPercentage: 0.75
                                                            }]
                                                        },
                                                        options: Object.assign({}, baseOptions(5), {
                                                            plugins: Object.assign({}, {
                                                                valueLabels: {}
                                                            }, {
                                                                title: {
                                                                    display: true,
                                                                    text: 'Inschrijvingen',
                                                                    align: 'center',
                                                                    font: {
                                                                        size: titleSize,
                                                                        weight: 700
                                                                    },
                                                                    padding: {
                                                                        bottom: 8
                                                                    }
                                                                }
                                                            }),
                                                            scales: {
                                                                x: {
                                                                    ticks: {
                                                                        autoSkip: false,
                                                                        minRotation: 45,
                                                                        maxRotation: 45,
                                                                        font: {
                                                                            size: 13,
                                                                            weight: '600'
                                                                        }
                                                                    },
                                                                    grid: {
                                                                        display: false
                                                                    }
                                                                },
                                                                y: {
                                                                    beginAtZero: true,
                                                                    grid: {
                                                                        color: 'rgba(0,0,0,0.06)'
                                                                    }
                                                                }
                                                            }
                                                        })
                                                    });
                                                }
                                            })();
                                    <?php endif; ?>

                                    // Bookings by location
                                    <?php if (!empty($bookingsByLoc)): ?>
                                            (function() {
                                                const labels = <?php echo json_encode(array_map(function ($b) {
                                                                    return $b['loc'];
                                                                }, $bookingsByLoc)); ?>;
                                                const data = <?php echo json_encode(array_map(function ($b) {
                                                                    return (int)$b['cnt'];
                                                                }, $bookingsByLoc)); ?>;
                                                const ctx = document.getElementById('chartBookingsByLoc');
                                                if (ctx) {
                                                    const primaryBlue = '#2f6fbf';
                                                    const ctx2 = ctx.getContext('2d');
                                                    const grad = ctx2.createLinearGradient(0, 0, 0, ctx2.canvas.height || 200);
                                                    grad.addColorStop(0, '#7fb3ff');
                                                    grad.addColorStop(1, primaryBlue);
                                                    new Chart(ctx2, {
                                                        type: 'bar',
                                                        data: {
                                                            labels: labels.map(l => truncate(l, 28)),
                                                            datasets: [{
                                                                label: 'Bookings',
                                                                data: data,
                                                                backgroundColor: grad,
                                                                borderRadius: 12,
                                                                borderColor: 'rgba(47,111,191,0.12)',
                                                                borderWidth: 1,
                                                                borderSkipped: false,
                                                                barPercentage: 0.65,
                                                                categoryPercentage: 0.75
                                                            }]
                                                        },
                                                        options: Object.assign({}, baseOptions(5), {
                                                            plugins: Object.assign({}, {
                                                                valueLabels: {}
                                                            }, {
                                                                title: {
                                                                    display: true,
                                                                    text: 'Bookings per locatie',
                                                                    align: 'center',
                                                                    font: {
                                                                        size: 14,
                                                                        weight: 600
                                                                    },
                                                                    padding: {
                                                                        bottom: 8
                                                                    }
                                                                }
                                                            }),
                                                            scales: {
                                                                x: {
                                                                    ticks: {
                                                                        autoSkip: false,
                                                                        minRotation: 45,
                                                                        maxRotation: 45,
                                                                        font: {
                                                                            size: 13,
                                                                            weight: '600'
                                                                        }
                                                                    },
                                                                    grid: {
                                                                        display: false
                                                                    }
                                                                },
                                                                y: {
                                                                    beginAtZero: true,
                                                                    grid: {
                                                                        color: 'rgba(0,0,0,0.06)'
                                                                    }
                                                                }
                                                            }
                                                        })
                                                    });
                                                }
                                            })();
                                    <?php endif; ?>

                                    // Top partners (horizontal, sorted)
                                    <?php if (!empty($topPartners)): ?>
                                            (function() {
                                                let labels = <?php echo json_encode(array_keys($topPartners)); ?>;
                                                let data = <?php echo json_encode(array_values($topPartners)); ?>;
                                                // pair and sort descending by value
                                                const paired = labels.map((l, i) => ({
                                                    label: l,
                                                    value: data[i]
                                                }));
                                                paired.sort((a, b) => b.value - a.value);
                                                labels = paired.map(p => truncate(p.label, 32));
                                                data = paired.map(p => p.value);
                                                const ctx = document.getElementById('chartTopPartners');
                                                if (ctx) {
                                                    const primaryBlue = '#2f6fbf';
                                                    const ctx2 = ctx.getContext('2d');
                                                    const grad = ctx2.createLinearGradient(0, 0, ctx2.canvas.width || 300, 0);
                                                    grad.addColorStop(0, '#7fb3ff');
                                                    grad.addColorStop(1, primaryBlue);
                                                    // make partners vertical (indexAxis: 'x') for consistent vertical charts across devices
                                                    new Chart(ctx2, {
                                                        type: 'bar',
                                                        data: {
                                                            labels: labels,
                                                            datasets: [{
                                                                label: 'Partner mentions',
                                                                data: data,
                                                                backgroundColor: grad,
                                                                borderRadius: 12,
                                                                borderColor: 'rgba(47,111,191,0.12)',
                                                                borderWidth: 1,
                                                                borderSkipped: false,
                                                                barPercentage: 0.65,
                                                                categoryPercentage: 0.75
                                                            }]
                                                        },
                                                        options: Object.assign({}, baseOptions(6), {
                                                            plugins: Object.assign({}, {
                                                                valueLabels: {}
                                                            }, {
                                                                title: {
                                                                    display: true,
                                                                    text: 'Top partners',
                                                                    align: 'center',
                                                                    font: {
                                                                        size: 14,
                                                                        weight: 600
                                                                    },
                                                                    padding: {
                                                                        bottom: 8
                                                                    }
                                                                }
                                                            }),
                                                            scales: {
                                                                x: {
                                                                    ticks: {
                                                                        autoSkip: false,
                                                                        minRotation: 45,
                                                                        maxRotation: 45,
                                                                        font: {
                                                                            size: 13,
                                                                            weight: '600'
                                                                        }
                                                                    },
                                                                    grid: {
                                                                        display: false
                                                                    }
                                                                },
                                                                y: {
                                                                    ticks: {
                                                                        callback: function(t) {
                                                                            return truncate(this.getLabelForValue(t), 28);
                                                                        },
                                                                        maxTicksLimit: 8
                                                                    },
                                                                    grid: {
                                                                        display: false
                                                                    }
                                                                }
                                                            }
                                                        })
                                                    });
                                                }
                                            })();
                                    <?php endif; ?>
                                });
                            </script>
                        </div>
                        <?php return; ?>
                    <?php endif; ?>
                    <?php
                    $pageKey = $page;
                    $pageLabelMap = [
                        'index' => 'Homepage',
                        'evenementen' => 'Evenementen',
                        'terugblikken' => 'Terugblikken',
                        'over' => 'Voor wie?',
                        'wie-zijn-we' => 'Wie zijn we?',
                        'ons-team' => 'Ons team',
                        'verantwoord-ai' => 'Verantwoorde AI',
                        'contact' => 'Contact',
                        'programma-kennis' => 'Wat doen we: Kennis & vaardigheden',
                        'programma-actie' => 'Wat doen we: Actie, onderzoek & ontwerp',
                        'programma-faciliteit' => 'Wat doen we: Faciliteit van het Lab',
                    ];
                    $pageLabel = $pageLabelMap[$pageKey] ?? $pageKey;
                    $isProgrammaPage = (strpos($pageKey, 'programma-') === 0);
                    $itemLabel = $isProgrammaPage ? 'Kaart' : 'Item';
                    $fields = ['title', 'body', 'image'];
                    $extraLabels = [];
                    switch ($pageKey) {
                        case 'contact':
                            $fields = ['title', 'body', 'address', 'email', 'map_embed', 'image'];
                            $extraLabels['address'] = 'Adres';
                            $extraLabels['email'] = 'E-mail';
                            $extraLabels['map_embed'] = 'Map embed (iframe)';
                            break;
                        default:
                            $fields = ['title', 'body', 'image'];
                    }
                    $editMeta = [];
                    if ($editPage && !empty($editPage['meta'])) {
                        $decodedEditMeta = json_decode($editPage['meta'], true);
                        if (is_array($decodedEditMeta)) {
                            $editMeta = $decodedEditMeta;
                        }
                    }
                    $currentLayout = $editMeta['layout'] ?? 'custom';
                    if (!in_array($currentLayout, ['welcome', 'card', 'info', 'contact', 'custom'], true)) {
                        $currentLayout = 'custom';
                    }
                    $currentImagePosition = $editMeta['image_position'] ?? 'normal';
                    if (!in_array($currentImagePosition, ['normal', 'left', 'right'], true)) {
                        $currentImagePosition = 'normal';
                    }
                    $currentGreenText = $editMeta['green_text'] ?? ($editMeta['green_heading'] ?? '');
                    $currentRole = $editMeta['role'] ?? '';
                    $currentGreenTextPosition = $editMeta['green_text_position'] ?? 'above';
                    if (!in_array($currentGreenTextPosition, ['above', 'below'], true)) {
                        $currentGreenTextPosition = 'above';
                    }
                    ?>

                    <div class="card p-6">
                        <div class="flex items-center gap-2 mb-4 pb-4 border-b-2 border-gray-200">
                            <i class="fa-solid fa-file-pen text-2xl text-[#00811F]"></i>
                            <h2 class="text-2xl font-bold">Beheer: <?php echo htmlspecialchars($pageLabel); ?></h2>
                        </div>
                        <?php if (strpos($pageKey, 'programma-') === 0): ?>
                            <p class="text-sm text-gray-600 mb-4">Hier kun je kaarten toevoegen, wijzigen en verwijderen voor de pagina onder de tab "Wat doen we". De eerste kaart wordt als brede intro bovenaan getoond; de rest komt in de rij met kaarten.</p>
                        <?php endif; ?>

                        <?php if ($editPage && $canManagePages): ?>
                            <a href="admin.php?page=<?php echo urlencode($pageKey); ?>" class="btn btn-secondary mb-6">
                                <i class="fa-solid fa-arrow-left"></i> Terug
                            </a>
                        <?php endif; ?>

                        <?php if ($editPage): ?>
                            <form method="POST" enctype="multipart/form-data" class="bg-white p-6 shadow-md space-y-4 mb-6 js-content-preview-form">
                                <h3 class="font-semibold text-lg">Bewerk <?php echo htmlspecialchars($itemLabel); ?></h3>
                                <input type="hidden" name="page_action" value="update_page">
                                <input type="hidden" name="id" value="<?php echo (int)$editPage['id']; ?>">
                                <input type="hidden" name="page_key" value="<?php echo htmlspecialchars($pageKey); ?>">

                                <div>
                                    <label class="form-label">Titel</label>
                                    <textarea name="title" rows="1" class="form-textarea"><?php echo htmlspecialchars($editPage['title'] ?? ''); ?></textarea>
                                </div>

                                <div>
                                    <label class="form-label">Inhoud</label>
                                    <textarea name="body" rows="6" class="form-textarea"><?php echo htmlspecialchars($editPage['body'] ?? ''); ?></textarea>
                                </div>

                                <div>
                                    <label class="form-label">Layout</label>
                                    <select name="layout" class="form-input">
                                        <option value="custom" <?php echo $currentLayout === 'custom' ? 'selected' : ''; ?>>Custom (standaard)</option>
                                        <option value="card" <?php echo $currentLayout === 'card' ? 'selected' : ''; ?>>Kaart</option>
                                        <option value="welcome" <?php echo $currentLayout === 'welcome' ? 'selected' : ''; ?>>Welkom sectie</option>
                                        <option value="info" <?php echo $currentLayout === 'info' ? 'selected' : ''; ?>>Info blok</option>
                                        <option value="contact" <?php echo $currentLayout === 'contact' ? 'selected' : ''; ?>>Contact</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="form-label">Groene tekst (optioneel)</label>
                                    <textarea name="green_text" rows="2" class="form-textarea"><?php echo htmlspecialchars($currentGreenText); ?></textarea>
                                </div>

                                <div>
                                    <label class="form-label">Positie groene tekst</label>
                                    <select name="green_text_position" class="form-input">
                                        <option value="above" <?php echo $currentGreenTextPosition === 'above' ? 'selected' : ''; ?>>Boven het kopje</option>
                                        <option value="below" <?php echo $currentGreenTextPosition === 'below' ? 'selected' : ''; ?>>Onderaan</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="form-label">Afbeelding (optioneel)</label>
                                    <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars($editPage['image'] ?? ''); ?>" />
                                    <div class="admin-upload-widget" data-name="image" data-accept="image/*"></div>
                                    <?php if (!empty($editPage['image'])): ?>
                                        <p class="text-sm mt-2 text-gray-600">Huidige afbeelding: <?php echo htmlspecialchars($editPage['image']); ?></p>
                                        <label class="form-checkbox mt-2 inline-flex items-center gap-2">
                                            <input type="checkbox" name="remove_image" value="1">
                                            Verwijder huidige afbeelding
                                        </label>
                                    <?php endif; ?>
                                </div>


                                <div>
                                    <label class="form-label" for="info_link">Meer info link (optioneel):</label>
                                    <input
                                        type="url"
                                        name="info_link"
                                        id="info_link"
                                        class="form-input"
                                        value="<?php echo htmlspecialchars($editMeta['info_link'] ?? ''); ?>"
                                        placeholder="https://voorbeeld.nl" />
                                </div>

                                <div>
                                    <label class="form-label" for="role">Functie / rol (optioneel, gebruikt op Ons team):</label>
                                    <input
                                        type="text"
                                        name="role"
                                        id="role"
                                        class="form-input"
                                        value="<?php echo htmlspecialchars($currentRole); ?>"
                                        placeholder="Bijv. Projectleider" />
                                </div>

                                <div>
                                    <label class="form-label">Foto positie</label>
                                    <select name="image_position" class="form-input">
                                        <option value="normal" <?php echo $currentImagePosition === 'normal' ? 'selected' : ''; ?>>Normaal (foto onder)</option>
                                        <option value="left" <?php echo $currentImagePosition === 'left' ? 'selected' : ''; ?>>Foto links</option>
                                        <option value="right" <?php echo $currentImagePosition === 'right' ? 'selected' : ''; ?>>Foto rechts</option>
                                    </select>
                                </div>



                                <div class="flex gap-2 pt-2">
                                    <button type="button" class="btn btn-secondary js-content-preview-btn">
                                        <i class="fa-solid fa-eye"></i> Preview
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fa-solid fa-save"></i> Opslaan
                                    </button>
                                </div>
                            </form>
                        <?php elseif ($canManagePages): ?>
                            <form method="POST" enctype="multipart/form-data" class="bg-white p-6 shadow-md space-y-4 mb-6 js-content-preview-form">
                                <h3 class="font-semibold text-lg">Nieuw <?php echo htmlspecialchars($itemLabel); ?></h3>
                                <input type="hidden" name="page_action" value="create_page">
                                <input type="hidden" name="page_key" value="<?php echo htmlspecialchars($pageKey); ?>">

                                <div>
                                    <label class="form-label">Titel</label>
                                    <textarea name="title" rows="1" class="form-textarea"></textarea>
                                </div>

                                <div>
                                    <label class="form-label">Inhoud</label>
                                    <textarea name="body" rows="6" class="form-textarea"></textarea>
                                </div>

                                <div>
                                    <label class="form-label">Layout</label>
                                    <select name="layout" class="form-input">
                                        <option value="custom" selected>Custom (standaard)</option>
                                        <option value="card">Kaart</option>
                                        <option value="welcome">Welkom sectie</option>
                                        <option value="info">Info blok</option>
                                        <option value="contact">Contact</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="form-label">Groene tekst (optioneel)</label>
                                    <textarea name="green_text" rows="2" class="form-textarea"></textarea>
                                </div>

                                <div>
                                    <label class="form-label">Positie groene tekst</label>
                                    <select name="green_text_position" class="form-input">
                                        <option value="above" selected>Boven het kopje</option>
                                        <option value="below">Onderaan</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="form-label">Afbeelding (optioneel)</label>
                                    <div class="admin-upload-widget" data-name="image" data-accept="image/*"></div>
                                </div>


                                <div>
                                    <label class="form-label" for="info_link">Meer info link (optioneel):</label>
                                    <input
                                        type="url"
                                        name="info_link"
                                        id="info_link"
                                        class="form-input"
                                        value="<?php echo htmlspecialchars($_POST['info_link'] ?? ''); ?>"
                                        placeholder="https://voorbeeld.nl" />
                                </div>

                                <div>
                                    <label class="form-label" for="role">Functie / rol (optioneel, gebruikt op Ons team):</label>
                                    <input
                                        type="text"
                                        name="role"
                                        id="role"
                                        class="form-input"
                                        value="<?php echo htmlspecialchars($_POST['role'] ?? ''); ?>"
                                        placeholder="Bijv. Projectleider" />
                                </div>

                                <div>
                                    <label class="form-label">Foto positie</label>
                                    <select name="image_position" class="form-input">
                                        <option value="normal" selected>Normaal (foto onder)</option>
                                        <option value="left">Foto links</option>
                                        <option value="right">Foto rechts</option>
                                    </select>
                                </div>



                                <div>
                                    <label class="form-label">Positie</label>
                                    <select name="insert_position" class="form-input">
                                        <option value="bottom" selected>Onderaan toevoegen</option>
                                        <option value="top">Bovenaan toevoegen</option>
                                    </select>
                                </div>

                                <div class="flex gap-2 pt-2">
                                    <button type="button" class="btn btn-secondary js-content-preview-btn">
                                        <i class="fa-solid fa-eye"></i> Preview
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fa-solid fa-plus"></i> <?php echo $isProgrammaPage ? 'Kaart toevoegen' : 'Toevoegen'; ?>
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <div class="card p-6">
                            <div class="flex items-center gap-2 mb-4 pb-4 border-b-2 border-gray-200">
                                <i class="fa-solid fa-list text-2xl text-[#00811F]"></i>
                                <h3 class="text-xl font-bold">Bestaande <?php echo $isProgrammaPage ? 'Kaarten' : 'Items'; ?></h3>
                            </div>
                            <?php if (empty($pageItems)): ?>
                                <div class="text-center py-8 text-gray-500">
                                    <i class="fa-solid fa-inbox text-4xl mb-2"></i>
                                    <p>Geen <?php echo $isProgrammaPage ? 'kaarten' : 'items'; ?> toegevoegd voor deze pagina</p>
                                </div>
                            <?php else: ?>
                                <?php if ($canDeletePages): ?>
                                    <div class="mb-4 flex gap-2 items-center">
                                        <input type="checkbox" id="selectAllPages_<?php echo htmlspecialchars($pageKey); ?>" class="w-4 h-4 cursor-pointer selectAllPages" data-page="<?php echo htmlspecialchars($pageKey); ?>" title="Selecteer alles">
                                        <label for="selectAllPages_<?php echo htmlspecialchars($pageKey); ?>" class="cursor-pointer text-sm font-medium">Selecteer alles</label>
                                        <button type="button" class="btn btn-danger btn-sm admin-hidden" id="bulkDeletePagesBtn_<?php echo htmlspecialchars($pageKey); ?>">
                                            <i class="fa-solid fa-trash"></i> Verwijder geselecteerde (<span id="pageCount_<?php echo htmlspecialchars($pageKey); ?>">0</span>)
                                        </button>
                                    </div>
                                <?php endif; ?>
                                <form method="POST" id="bulkDeletePagesForm_<?php echo htmlspecialchars($pageKey); ?>">
                                    <input type="hidden" name="action" value="delete_bulk_pages">
                                    <input type="hidden" name="page_key" value="<?php echo htmlspecialchars($pageKey); ?>">
                                    <div class="space-y-4">
                                        <?php foreach ($pageItems as $index => $it): ?>
                                            <?php
                                            $isFirst = ($index === 0);
                                            $isLast = ($index === (count($pageItems) - 1));
                                            ?>
                                            <div class="border border-gray-200 rounded p-4 hover:shadow-md transition">
                                                <div class="admin-row flex justify-between items-start gap-4">
                                                    <?php if ($canDeletePages): ?>
                                                        <input type="checkbox" class="page-checkbox page-checkbox-<?php echo htmlspecialchars($pageKey); ?> w-4 h-4 mt-1 flex-shrink-0 cursor-pointer" name="page_ids[]" value="<?php echo (int)$it['id']; ?>">
                                                    <?php endif; ?>
                                                    <div class="flex-1">
                                                        <?php $pageBodyPreview = editorPreviewText($it['body'] ?? '', 150); ?>
                                                        <h4 class="font-bold text-lg text-gray-800"><?php echo htmlspecialchars(sanitizeEditorText($it['title'] ?? '')); ?></h4>
                                                        <p class="text-sm text-gray-600 mt-1 line-clamp-2"><?php echo nl2br(htmlspecialchars($pageBodyPreview)); ?></p>
                                                    </div>
                                                    <div class="admin-row-actions flex gap-2 flex-shrink-0">
                                                        <?php if (!empty($it['image'])): ?>
                                                            <a href="#" class="btn btn-secondary btn-sm" onclick="openImageModal(event, 'uploads/<?php echo htmlspecialchars($it['image']); ?>')">
                                                                <i class="fa-solid fa-image"></i> Foto
                                                            </a>
                                                        <?php endif; ?>
                                                        <div class="admin-row-actions-stack flex flex-col gap-1">
                                                            <?php if ($canManagePages): ?>
                                                                <div class="flex gap-1">
                                                                    <form method="POST">
                                                                        <input type="hidden" name="page_action" value="reorder_page">
                                                                        <input type="hidden" name="page_key" value="<?php echo htmlspecialchars($pageKey); ?>">
                                                                        <input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>">
                                                                        <input type="hidden" name="direction" value="up">
                                                                        <button type="submit" class="btn btn-secondary btn-sm" <?php echo $isFirst ? 'disabled' : ''; ?> title="Omhoog">
                                                                            <i class="fa-solid fa-arrow-up"></i>
                                                                        </button>
                                                                    </form>
                                                                    <form method="POST">
                                                                        <input type="hidden" name="page_action" value="reorder_page">
                                                                        <input type="hidden" name="page_key" value="<?php echo htmlspecialchars($pageKey); ?>">
                                                                        <input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>">
                                                                        <input type="hidden" name="direction" value="down">
                                                                        <button type="submit" class="btn btn-secondary btn-sm" <?php echo $isLast ? 'disabled' : ''; ?> title="Omlaag">
                                                                            <i class="fa-solid fa-arrow-down"></i>
                                                                        </button>
                                                                    </form>
                                                                </div>
                                                                <a href="admin.php?edit_page=<?php echo (int)$it['id']; ?>&page=<?php echo urlencode($pageKey); ?>" class="btn btn-secondary btn-sm">
                                                                    <i class="fa-solid fa-pencil"></i> Bewerk
                                                                </a>
                                                            <?php endif; ?>
                                                            <?php if ($canDeletePages): ?>
                                                                <form method="POST" onsubmit="return confirm('Verwijder dit item?');" class="admin-inline-form">
                                                                    <input type="hidden" name="page_action" value="delete_page">
                                                                    <input type="hidden" name="page_key" value="<?php echo htmlspecialchars($pageKey); ?>">
                                                                    <input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>">
                                                                    <button type="submit" class="btn btn-danger btn-sm w-full">
                                                                        <i class="fa-solid fa-trash"></i> Verwijder
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="card p-6">
                        <div class="flex items-center gap-2 mb-4 pb-4 border-b-2 border-gray-200">
                            <i class="fa-solid fa-image text-2xl text-[#00811F]"></i>
                            <h2 class="text-2xl font-bold">Banner Beheer</h2>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <p class="font-semibold mb-2">Banner 1</p>
                                <img src="<?php echo htmlspecialchars($banner1); ?>" alt="Banner 1" class="w-full h-auto border rounded-lg shadow">
                            </div>
                            <div>
                                <p class="font-semibold mb-2">Banner 2</p>
                                <img src="<?php echo htmlspecialchars($banner2); ?>" alt="Banner 2" class="w-full h-auto border rounded-lg shadow">
                            </div>
                            <?php if ($banner3): ?>
                            <div>
                                <p class="font-semibold mb-2">Banner 3</p>
                                <img src="<?php echo htmlspecialchars($banner3); ?>" alt="Banner 3" class="w-full h-auto border rounded-lg shadow">
                            </div>
                            <?php endif; ?>
                            <?php if ($banner4): ?>
                            <div>
                                <p class="font-semibold mb-2">Banner 4</p>
                                <img src="<?php echo htmlspecialchars($banner4); ?>" alt="Banner 4" class="w-full h-auto border rounded-lg shadow">
                            </div>
                            <?php endif; ?>
                        </div>

                        <form method="POST" enctype="multipart/form-data" class="space-y-4">
                            <input type="hidden" name="action" value="update_banner">

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-group">
                                    <label class="form-label">Vervang Banner 1</label>
                                    <div class="admin-upload-widget" data-name="banner1" data-accept="image/*"></div>
                                    <label class="form-checkbox mt-2 inline-flex items-center gap-2">
                                        <input type="checkbox" name="remove_banner1" value="1">
                                        Verwijder huidige banner 1
                                    </label>
                                    <small class="text-gray-600">Aanbevolen formaat: 1920x600px</small>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Vervang Banner 2</label>
                                    <div class="admin-upload-widget" data-name="banner2" data-accept="image/*"></div>
                                    <label class="form-checkbox mt-2 inline-flex items-center gap-2">
                                        <input type="checkbox" name="remove_banner2" value="1">
                                        Verwijder huidige banner 2
                                    </label>
                                    <small class="text-gray-600">Aanbevolen formaat: 1920x600px</small>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Vervang Banner 3 (optioneel)</label>
                                    <div class="admin-upload-widget" data-name="banner3" data-accept="image/*"></div>
                                    <label class="form-checkbox mt-2 inline-flex items-center gap-2">
                                        <input type="checkbox" name="remove_banner3" value="1">
                                        Verwijder huidige banner 3
                                    </label>
                                    <small class="text-gray-600">Aanbevolen formaat: 1920x600px</small>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Vervang Banner 4 (optioneel)</label>
                                    <div class="admin-upload-widget" data-name="banner4" data-accept="image/*"></div>
                                    <label class="form-checkbox mt-2 inline-flex items-center gap-2">
                                        <input type="checkbox" name="remove_banner4" value="1">
                                        Verwijder huidige banner 4
                                    </label>
                                    <small class="text-gray-600">Aanbevolen formaat: 1920x600px</small>
                                </div>
                            </div>

                            <div class="flex gap-2 pt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-save"></i> Banners Bijwerken
                                </button>
                            </div>
                        </form>

                        <form method="POST" onsubmit="return confirm('Weet je zeker dat je de banners wilt resetten naar de standaard afbeeldingen?');" class="mt-4 pt-4">
                            <input type="hidden" name="action" value="reset_banners">
                            <button type="submit" class="btn btn-secondary">
                                <i class="fa-solid fa-rotate-left"></i> Reset naar Standaard Banners
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </main>
    <script>
        // Tijdelijke test: einddatum/eindtijd tonen/verbergen
        function toggleField(checkboxId, containerId) {
            var checkbox = document.getElementById(checkboxId);
            var container = document.getElementById(containerId);
            if (!checkbox || !container) return;

            function update() {
                if (checkbox.checked) {
                    container.classList.remove('admin-hidden');
                } else {
                    container.classList.add('admin-hidden');
                }
            }
            checkbox.addEventListener('change', update);
            update();
        }
        toggleField('add-end-date-edit', 'end-date-container-edit');
        toggleField('add-end-time-edit', 'end-time-container-edit');
        toggleField('add-end-date-create', 'end-date-container-create');
        toggleField('add-end-time-create', 'end-time-container-create');
    </script>

    <div id="content-preview-modal" class="hidden" style="position: fixed; inset: 0; z-index: 9999; background: rgba(17,24,39,.6); padding: 1rem;">
        <div style="max-width: 860px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,.25); max-height: calc(100vh - 2rem); display: flex; flex-direction: column;">
            <div style="display:flex; align-items:center; justify-content:space-between; padding: .9rem 1rem; border-bottom: 1px solid #e5e7eb;">
                <h3 style="font-size: 1.1rem; font-weight: 700; color: #111827; margin: 0;">Preview zoals op de website</h3>
                <button type="button" id="content-preview-close" class="btn btn-secondary btn-sm">
                    <i class="fa-solid fa-xmark"></i> Sluiten
                </button>
            </div>
            <div style="padding: 1rem; overflow: auto; background:#f8fafc;">
                <div id="preview-rendered"></div>
                <p id="preview-empty" class="hidden" style="color: #6b7280; margin-top: .75rem;">Nog geen inhoud om te tonen.</p>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const modal = document.getElementById('content-preview-modal');
            const closeBtn = document.getElementById('content-preview-close');
            const renderedEl = document.getElementById('preview-rendered');
            const emptyEl = document.getElementById('preview-empty');
            const previewButtons = document.querySelectorAll('.js-content-preview-btn');
            let currentImageUrl = null;

            if (!modal || !closeBtn || !previewButtons.length) return;

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function getEditorContentByName(fieldName) {
                if (!window.tinymce || !Array.isArray(window.tinymce.editors)) {
                    return null;
                }

                for (const editor of window.tinymce.editors) {
                    if (editor && editor.targetElm && editor.targetElm.name === fieldName) {
                        return editor.getContent();
                    }
                }
                return null;
            }

            function getFieldInput(form, fieldName) {
                return form.querySelector('[name="' + fieldName + '"]');
            }

            function getFieldValue(form, fieldName) {
                const input = getFieldInput(form, fieldName);

                // EERST: Controleer of textarea/input hidden is
                if (input) {
                    const style = window.getComputedStyle(input);
                    const isHidden = style.display === 'none' || !input.offsetParent;

                    if (isHidden) {
                        console.log('[getFieldValue] Field', fieldName, 'is HIDDEN - zoekend contenteditable...');

                        // 1. Check volgende sibling
                        let sibling = input.nextElementSibling;
                        while (sibling && sibling.nextElementSibling && sibling !== input.nextElementSibling.nextElementSibling.nextElementSibling) {
                            if (sibling.contentEditable === 'true' || sibling.getAttribute('contenteditable') === 'true') {
                                const value = sibling.textContent || sibling.innerText || '';
                                console.log('[getFieldValue] ✓ Gevonden in nextSibling DIV:', fieldName, ':', value.substring(0, 50));
                                return value;
                            }
                            sibling = sibling.nextElementSibling;
                        }

                        // 2. Check parent children
                        const parent = input.parentElement;
                        if (parent) {
                            const allDivs = parent.querySelectorAll('[contenteditable="true"]');
                            if (allDivs.length > 0) {
                                // Zoek ContentEditable DIV dicht bij deze textarea
                                for (let div of allDivs) {
                                    const value = div.textContent || div.innerText || '';
                                    if (value && value.length > 0) {
                                        console.log('[getFieldValue] ✓ Gevonden in parent DIV:', fieldName, ':', value.substring(0, 50));
                                        return value;
                                    }
                                }
                            }
                        }

                        // 3. Fallback: geen DIV gevonden
                        console.log('[getFieldValue] ! Geen contenteditable DIV gevonden voor', fieldName);
                        return '';
                    }
                } else {
                    console.warn('[getFieldValue] Veld niet gevonden:', fieldName);
                    return '';
                }

                // Textarea/input is VISIBLE - return value normaal
                const value = input.value || '';
                console.log('[getFieldValue]', fieldName, '(visible):', value.substring(0, 50) + (value.length > 50 ? '...' : ''));
                return value;
            }

            function clearImagePreview() {
                if (currentImageUrl) {
                    URL.revokeObjectURL(currentImageUrl);
                    currentImageUrl = null;
                }
            }

            function resolveImageSrc(form) {
                // 1. Als er een nieuwe afbeelding gekozen is, toon die
                const imageInput = getFieldInput(form, 'image');
                if (imageInput && imageInput.files && imageInput.files.length > 0) {
                    clearImagePreview();
                    currentImageUrl = URL.createObjectURL(imageInput.files[0]);
                    return currentImageUrl;
                }

                // 2. Als "verwijder afbeelding" is aangevinkt, geen afbeelding tonen
                const removeImage = getFieldInput(form, 'remove_image');
                if (removeImage && removeImage.checked) {
                    return '';
                }

                // 3. Bij bewerken: bestaande afbeelding tonen als die er is
                const existingImage = getFieldValue(form, 'existing_image').trim();
                if (existingImage) {
                    return 'uploads/' + existingImage;
                }

                // 4. Geen afbeelding
                return '';
            }

            function isEventForm(form) {
                return !!getFieldInput(form, 'description') && !!getFieldInput(form, 'date');
            }

            function formatDateDisplay(dateValue) {
                if (!dateValue) return '';
                const parsed = new Date(dateValue + 'T00:00:00');
                if (Number.isNaN(parsed.getTime())) return dateValue;
                return parsed.toLocaleDateString('nl-NL', {
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric'
                });
            }

            function formatTimeDisplay(timeValue) {
                if (!timeValue) return '';
                const normalized = String(timeValue).slice(0, 5);
                return normalized;
            }

            function renderEventPreview(form) {
                console.log('[renderEventPreview] Starting render...');
                const titleRaw = getFieldValue(form, 'title').trim();
                const titleHtml = titleRaw || 'Preview titel';
                const descriptionRaw = getFieldValue(form, 'description').trim();
                const startDate = getFieldValue(form, 'date').trim();
                const endDate = getFieldValue(form, 'end_date').trim();
                const startTime = formatTimeDisplay(getFieldValue(form, 'time').trim());
                const endTime = formatTimeDisplay(getFieldValue(form, 'time_end').trim());
                const location = getFieldValue(form, 'location').trim() || 'Rotterdam - Hillevliet 90';
                const infoLink = getFieldValue(form, 'info_link').trim();
                const signupEmbed = getFieldValue(form, 'signup_embed').trim();
                const imageSrc = resolveImageSrc(form);
                const mapUrl = 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(location);
                const hasEmbed = signupEmbed !== '';
                const hasSignup = !!(getFieldInput(form, 'show_signup_button') && getFieldInput(form, 'show_signup_button').checked);
                const dateDisplay = formatDateDisplay(startDate);
                const endDateDisplay = formatDateDisplay(endDate);

                console.log('[renderEventPreview] Title:', titleHtml);
                console.log('[renderEventPreview] Description:', descriptionRaw.substring(0, 50));
                console.log('[renderEventPreview] Date:', startDate);

                renderedEl.innerHTML = '' +
                    '<section class="flex flex-col md:flex-row items-center gap-10 bg-white shadow-lg p-8 max-w-6xl mx-auto my-12">' +
                    '<div class="flex-1">' +
                    '<span class="inline-block text-white text-sm font-medium px-4 py-1 mb-4" style="background-color:#ce0245;">Evenement</span>' +
                    '<h2 class="text-2xl md:text-3xl font-semibold mb-4 text-gray-900">' + escapeHtml(titleHtml) + '</h2>' +
                    '<div class="space-y-4">' +
                    '<div class="flex items-center space-x-3">' +
                    '<i class="fa-regular fa-calendar text-[#00811F] ml-[2px] text-3xl"></i>' +
                    '<p class="text-gray-700"><strong> Wanneer:</strong> ' + escapeHtml(dateDisplay || startDate) + (endDateDisplay ? ' t/m ' + escapeHtml(endDateDisplay) : '') + '</p>' +
                    '</div>' +
                    ((startTime || endTime) ?
                        ('<div class="flex items-center space-x-3">' +
                            '<i class="fa-solid fa-clock text-[#00811F] ml-[2px] text-3xl"></i>' +
                            '<p class="text-gray-700"><strong>Hoelaat:</strong> ' + escapeHtml(startTime) + (endTime ? ' - ' + escapeHtml(endTime) : '') + '</p>' +
                            '</div>') :
                        '') +
                    '<div class="flex items-center space-x-3">' +
                    '<i class="fa-solid fa-location-dot text-[#00811F] ml-1 text-3xl"></i>' +
                    '<p class="text-gray-700 ml-1"><strong>Waar:</strong> <a href="' + escapeHtml(mapUrl) + '" target="_blank" rel="noopener noreferrer" class="underline hover:text-[#00811F]">' + escapeHtml(location) + '</a></p>' +
                    '</div>' +
                    '<div class="flex mb-2 space-x-3">' +
                    '<i class="fa-solid fa-bullseye text-[#00811F] text-3xl"></i>' +
                    '<div class="text-gray-700 pb-1"><strong> Wat:</strong><div class="mt-1">' + (descriptionRaw ? escapeHtml(descriptionRaw) : '<em class="text-gray-500">Geen beschrijving ingevuld</em>') + '</div></div>' +
                    '</div>' +
                    '</div>' +
                    (imageSrc ? '<div class="mobile-image mb-4"><img src="' + escapeHtml(imageSrc) + '" alt="" class="image-template-photo" style="object-fit: contain;"></div>' : '') +
                    (hasEmbed ?
                        '<div class="mt-4 rounded-md border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-600">Aanmelder.nl embed wordt na opslaan getoond.</div>' :
                        (hasSignup ?
                            '<a href="#" onclick="return false;" class="mt-4 inline-flex items-center bg-[#00811F] text-white font-semibold px-6 py-3 rounded-md shadow hover:bg-[#006f19] transition">Inschrijven</a>' :
                            '')
                    ) +
                    (infoLink ?
                        '<a href="' + escapeHtml(infoLink) + '" target="_blank" rel="noopener noreferrer" class="mt-4 ml-4 inline-flex items-center bg-[#00811F] text-white font-semibold px-6 py-3 rounded-md shadow hover:bg-[#006f19] transition">Meer info</a>' :
                        ''
                    ) +
                    '</div>' +
                    (imageSrc ? '<div class="flex-1 desktop-image"><img src="' + escapeHtml(imageSrc) + '" alt="" class="w-full h-auto object-cover shadow-md"></div>' : '') +
                    '</section>';
            }

            function renderPagePreview(form) {
                const titleHtml = getFieldValue(form, 'title').trim() || 'Preview titel';
                const bodyHtml = getFieldValue(form, 'body').trim() || '';
                const greenText = getFieldValue(form, 'green_text').trim();
                const greenTextPos = (getFieldValue(form, 'green_text_position').trim() || 'above');
                const imagePosition = (getFieldValue(form, 'image_position').trim() || 'normal');
                const infoLink = getFieldValue(form, 'info_link').trim();
                const imageSrc = resolveImageSrc(form);
                const hasImage = !!imageSrc;
                const hasText = !!(titleHtml || bodyHtml);
                const sideBySide = hasImage && hasText && imagePosition !== 'normal';
                const textStyle = sideBySide ? 'flex: 1; padding: 0 1.5rem;' : '';

                const leftImage = (imagePosition === 'left' && hasText) ?
                    '<div style="flex: 0 0 50%; min-width: 0; max-width: 600px;"><img src="' + escapeHtml(imageSrc) + '" alt="" class="w-full h-auto object-cover shadow-md"></div>' : '';
                const rightImage = (imagePosition === 'right' && hasText) ?
                    '<div style="flex: 0 0 50%; min-width: 0; max-width: 600px;"><img src="' + escapeHtml(imageSrc) + '" alt="" class="w-full h-auto object-cover shadow-md"></div>' : '';
                const imageOnly = (hasImage && !hasText) ?
                    '<div style="width: 100%;"><img src="' + escapeHtml(imageSrc) + '" alt="" class="w-full h-auto object-cover shadow-md"></div>' : '';
                const belowImage = (hasImage && imagePosition === 'normal' && hasText) ?
                    '<div class="mt-6" style="max-width: 600px;"><img src="' + escapeHtml(imageSrc) + '" alt="" class="w-full h-auto object-cover shadow-md"></div>' : '';

                renderedEl.innerHTML = '' +
                    '<section class="bg-white shadow-lg p-8 max-w-6xl mx-auto my-12">' +
                    '<div style="' + (sideBySide ? ('display: flex; flex-direction: ' + (imagePosition === 'left' ? 'row' : 'row-reverse') + '; align-items: flex-start; gap: 2rem;') : '') + '">' +
                    leftImage +
                    imageOnly +
                    (hasText ?
                        '<div style="' + textStyle + '">' +
                        (greenText && greenTextPos === 'above' ? '<div class="green-highlight mb-3">' + escapeHtml(greenText).replace(/\n/g, '<br>') + '</div>' : '') +
                        '<h1 class="text-3xl font-bold text-gray-900 mb-4">' + titleHtml + '</h1>' +
                        '<div class="text-gray-700 text-lg">' + bodyHtml + '</div>' +
                        (greenText && greenTextPos === 'below' ? '<div class="green-highlight mb-3">' + escapeHtml(greenText).replace(/\n/g, '<br>') + '</div>' : '') +
                        (infoLink ? '<a href="' + escapeHtml(infoLink) + '" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center bg-[#00811F] text-white font-semibold px-6 py-3 rounded-md shadow hover:bg-[#006f19] transition">Meer info</a>' : '') +
                        '</div>' :
                        '') +
                    rightImage +
                    '</div>' +
                    belowImage +
                    '</section>';
            }

            function renderPreview(form) {
                if (!renderedEl) return;

                if (isEventForm(form)) {
                    renderEventPreview(form);
                    return;
                }
                renderPagePreview(form);
            }

            function openModal() {
                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }

            function closeModal() {
                modal.classList.add('hidden');
                document.body.style.overflow = '';
                clearImagePreview();
                if (renderedEl) renderedEl.innerHTML = '';
            }

            closeBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', function(event) {
                if (event.target === modal) closeModal();
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                    closeModal();
                }
            });

            // Auto-save form values to localStorage
            document.querySelectorAll('form.js-content-preview-form').forEach(function(form) {
                const formId = (form.querySelector('[name="action"]')?.value || 'unknown') + '_' + (form.querySelector('[name="id"]')?.value || 'new');
                const storageKey = 'formData_' + formId;

                // Restore on load
                const saved = localStorage.getItem(storageKey);
                if (saved) {
                    try {
                        const data = JSON.parse(saved);
                        console.log('[Form] Restoring:', storageKey, data);
                        Object.keys(data).forEach(key => {
                            const el = form.elements[key];
                            if (el) {
                                el.value = data[key];
                                console.log('[Form] Restored', key, '=', data[key]);
                            }
                        });
                    } catch (e) {
                        console.error('[Form] Restore error:', e);
                    }
                }

                // Save on input
                form.addEventListener('input', function(e) {
                    if (e.target.name && e.target.type !== 'hidden') {
                        const data = {};
                        form.querySelectorAll('[name]').forEach(el => {
                            if (el.type !== 'hidden') {
                                data[el.name] = el.value;
                            }
                        });
                        localStorage.setItem(storageKey, JSON.stringify(data));
                    }
                });
            });

            previewButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    try {
                        console.log('[Preview] Preview-knop geklikt');
                        const form = button.closest('form.js-content-preview-form');
                        if (!form) {
                            console.warn('[Preview] Geen formulier gevonden voor preview');
                            return;
                        }

                        // DEBUG: Log ALL form fields om te zien welke zichtbaar zijn
                        console.log('[Preview] ===== ALL FORM FIELDS =====');
                        const allInputs = form.querySelectorAll('input, textarea, select, [contenteditable]');
                        allInputs.forEach(el => {
                            const isVisible = el.offsetParent !== null;
                            const value = el.value || el.textContent || el.innerText || '';
                            const style = window.getComputedStyle(el);
                            console.log('[Preview]', el.name || el.id || el.tagName,
                                '| visible:', isVisible,
                                '| display:', style.display,
                                '| value:', value.substring(0, 30));
                        });
                        console.log('[Preview] ===== END FIELDS =====');

                        // Save all form values to localStorage before anything else
                        const formElements = form.querySelectorAll('input, textarea, select');
                        const formData = {};
                        formElements.forEach(el => {
                            if (el.name && el.type !== 'hidden') {
                                if (el.type === 'checkbox' || el.type === 'radio') {
                                    formData[el.name] = el.checked;
                                } else {
                                    formData[el.name] = el.value;
                                }
                            }
                        });
                        console.log('[Preview] Saved form data:', formData);
                        localStorage.setItem('previewFormData', JSON.stringify(formData));

                        // Sync TinyMCE editors back naar textarea values
                        if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
                            console.log('[Preview] Calling tinymce.triggerSave()');
                            window.tinymce.triggerSave();
                        } else {
                            console.log('[Preview] TinyMCE not available');
                        }

                        // Re-read all form values AFTER sync in case TinyMCE changed them
                        formElements.forEach(el => {
                            if (el.name && el.type !== 'hidden') {
                                if (el.type !== 'checkbox' && el.type !== 'radio') {
                                    formData[el.name] = el.value;
                                }
                            }
                        });
                        console.log('[Preview] Form data after TinyMCE sync:', formData);

                        renderPreview(form);

                        if (renderedEl && renderedEl.textContent.trim() !== '') {
                            emptyEl.classList.add('hidden');
                        } else {
                            emptyEl.classList.remove('hidden');
                        }

                        openModal();
                    } catch (e) {
                        console.error('[Preview] Fout bij tonen preview:', e);
                        alert('Er is een fout opgetreden bij het tonen van de preview. Zie de console voor details.');
                    }
                });
            });
        })();

        (function() {
            const selectAllBoxes = document.querySelectorAll('.js-permission-select-all');
            if (!selectAllBoxes.length) return;

            function findPermissionGroup(selectAllBox) {
                const parent = selectAllBox.closest('.bg-gray-50, form');
                return parent ? parent.querySelector('.js-permission-group') : null;
            }

            function syncSelectAll(selectAllBox) {
                const group = findPermissionGroup(selectAllBox);
                if (!group) return;

                const boxes = group.querySelectorAll('input[type="checkbox"]');
                const checkedCount = Array.from(boxes).filter(function(box) {
                    return box.checked;
                }).length;

                selectAllBox.checked = boxes.length > 0 && checkedCount === boxes.length;
                selectAllBox.indeterminate = checkedCount > 0 && checkedCount < boxes.length;
            }

            selectAllBoxes.forEach(function(selectAllBox) {
                const group = findPermissionGroup(selectAllBox);
                if (!group) return;

                selectAllBox.addEventListener('change', function() {
                    group.querySelectorAll('input[type="checkbox"]').forEach(function(box) {
                        box.checked = selectAllBox.checked;
                    });
                    selectAllBox.indeterminate = false;
                });

                group.addEventListener('change', function(event) {
                    if ((event.target.type || '').toLowerCase() === 'checkbox') {
                        syncSelectAll(selectAllBox);
                    }
                });

                syncSelectAll(selectAllBox);
            });
        })();

        (function() {
            const isReadOnly = <?php echo $isEditorReadOnlyPage ? 'true' : 'false'; ?>;
            if (!isReadOnly) return;

            const scope = document.querySelector('.admin-readonly-scope');
            if (!scope) return;

            const controls = scope.querySelectorAll('button, input, select, textarea');
            controls.forEach(function(el) {
                if (el.tagName === 'INPUT' && (el.type || '').toLowerCase() === 'hidden') return;
                el.disabled = true;
                if (el.tagName === 'BUTTON') {
                    el.classList.add('btn-readonly-disabled');
                }
            });

            const actionLinks = scope.querySelectorAll('a.btn, a.sidebar-link');
            actionLinks.forEach(function(link) {
                link.classList.add('btn-readonly-disabled');
                link.setAttribute('aria-disabled', 'true');
                link.addEventListener('click', function(event) {
                    event.preventDefault();
                });
            });
        })();

        (function() {
            const wraps = document.querySelectorAll('.event-image-wrap');
            if (!wraps.length) return;

            let openWrap = null;

            function closePreview(wrap) {
                if (!wrap) return;
                wrap.classList.remove('is-open');
                const button = wrap.querySelector('.event-image-toggle');
                const preview = wrap.querySelector('.event-image-preview');
                if (button) button.setAttribute('aria-expanded', 'false');
                if (preview) preview.setAttribute('aria-hidden', 'true');
                if (openWrap === wrap) openWrap = null;
            }

            function openPreview(wrap) {
                if (!wrap) return;
                if (openWrap && openWrap !== wrap) {
                    closePreview(openWrap);
                }
                wrap.classList.add('is-open');
                const button = wrap.querySelector('.event-image-toggle');
                const preview = wrap.querySelector('.event-image-preview');
                if (button) button.setAttribute('aria-expanded', 'true');
                if (preview) preview.setAttribute('aria-hidden', 'false');
                openWrap = wrap;
            }

            wraps.forEach(function(wrap) {
                const button = wrap.querySelector('.event-image-toggle');
                button?.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (wrap.classList.contains('is-open')) {
                        closePreview(wrap);
                    } else {
                        openPreview(wrap);
                    }
                });
            });

            document.addEventListener('click', function(e) {
                if (openWrap && !openWrap.contains(e.target)) {
                    closePreview(openWrap);
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && openWrap) {
                    closePreview(openWrap);
                }
            });
        })();

        // Bulk selection for events
        (function() {
            const selectAllEvents = document.getElementById('selectAllEvents');
            const eventCheckboxes = document.querySelectorAll('.event-checkbox');
            const bulkDeleteEventsBtn = document.getElementById('bulkDeleteEventsBtn');
            const bulkDeleteEventsForm = document.getElementById('bulkDeleteEventsForm');
            const eventCount = document.getElementById('eventCount');

            if (!selectAllEvents || !eventCheckboxes.length) return;

            function updateEventBulkDelete() {
                const checked = document.querySelectorAll('.event-checkbox:checked').length;
                if (checked > 0) {
                    bulkDeleteEventsBtn.style.display = 'inline-flex';
                    eventCount.textContent = checked;
                } else {
                    bulkDeleteEventsBtn.style.display = 'none';
                }
                selectAllEvents.checked = checked === eventCheckboxes.length && eventCheckboxes.length > 0;
            }

            selectAllEvents.addEventListener('change', function() {
                eventCheckboxes.forEach(cb => cb.checked = this.checked);
                updateEventBulkDelete();
            });

            eventCheckboxes.forEach(cb => {
                cb.addEventListener('change', updateEventBulkDelete);
            });

            if (bulkDeleteEventsBtn && bulkDeleteEventsForm) {
                bulkDeleteEventsBtn.addEventListener('click', function(e) {
                    const checked = document.querySelectorAll('.event-checkbox:checked').length;
                    if (checked === 0) {
                        e.preventDefault();
                        alert('Selecteer minimaal één item');
                        return false;
                    }
                    if (!confirm('Verwijder alle geselecteerde evenementen?')) {
                        e.preventDefault();
                        return false;
                    }
                    bulkDeleteEventsForm.submit();
                });
            }
        })();

        // Bulk selection for page items
        (function() {
            const selectAllPageButtons = document.querySelectorAll('.selectAllPages');
            if (!selectAllPageButtons.length) return;

            selectAllPageButtons.forEach(btn => {
                const pageKey = btn.dataset.page;
                const pageCheckboxes = document.querySelectorAll(`.page-checkbox-${pageKey}`);
                const bulkDeletePagesBtn = document.getElementById(`bulkDeletePagesBtn_${pageKey}`);
                const bulkDeletePagesForm = document.getElementById(`bulkDeletePagesForm_${pageKey}`);
                const pageCount = document.getElementById(`pageCount_${pageKey}`);

                if (!pageCheckboxes.length || !bulkDeletePagesForm) return;

                function updatePageBulkDelete() {
                    const checked = document.querySelectorAll(`.page-checkbox-${pageKey}:checked`).length;
                    if (checked > 0) {
                        bulkDeletePagesBtn.style.display = 'inline-flex';
                        pageCount.textContent = checked;
                    } else {
                        bulkDeletePagesBtn.style.display = 'none';
                    }
                    btn.checked = checked === pageCheckboxes.length && pageCheckboxes.length > 0;
                }

                btn.addEventListener('change', function() {
                    pageCheckboxes.forEach(cb => cb.checked = this.checked);
                    updatePageBulkDelete();
                });

                pageCheckboxes.forEach(cb => {
                    cb.addEventListener('change', updatePageBulkDelete);
                });

                if (bulkDeletePagesBtn) {
                    bulkDeletePagesBtn.addEventListener('click', function(e) {
                        const checked = document.querySelectorAll(`.page-checkbox-${pageKey}:checked`).length;
                        if (checked === 0) {
                            e.preventDefault();
                            alert('Selecteer minimaal één item');
                            return false;
                        }
                        if (!confirm('Verwijder alle geselecteerde items?')) {
                            e.preventDefault();
                            return false;
                        }
                        bulkDeletePagesForm.submit();
                    });
                }
            });
        })();

        // Toggle image visibility in modal
        function openImageModal(event, imageSrc) {
            event.preventDefault();
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            if (modal && modalImg) {
                modalImg.src = imageSrc;
                modal.classList.add('active');
            }
        }

        // Close modal
        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            if (modal) {
                modal.classList.remove('active');
            }
        }

        // Close modal on background click
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('imageModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeImageModal();
                    }
                });
            }

            // Close on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeImageModal();
                }
            });
        });

        // Sidebar toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.querySelector('.sidebar');
            const toggleBtn = document.getElementById('sidebarToggle');
            const grid = document.querySelector('.admin-layout-grid');
            const sidebarLinks = document.querySelectorAll('.sidebar .sidebar-link');

            if (!sidebar || !toggleBtn || !grid) return;

            function setToggleExpanded(isExpanded) {
                toggleBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            }

            // Check if sidebar should be hidden on load (e.g., on mobile)
            function updateSidebarVisibility() {
                const isMobile = window.innerWidth < 1024;
                if (isMobile) {
                    sidebar.classList.add('hidden');
                    grid.classList.add('sidebar-hidden');
                    toggleBtn.classList.remove('hidden');
                    setToggleExpanded(false);
                } else {
                    sidebar.classList.remove('hidden');
                    grid.classList.remove('sidebar-hidden');
                    toggleBtn.classList.add('hidden');
                    setToggleExpanded(false);
                }
            }

            // Toggle sidebar
            toggleBtn.addEventListener('click', function() {
                sidebar.classList.toggle('hidden');
                grid.classList.toggle('sidebar-hidden');
                setToggleExpanded(!sidebar.classList.contains('hidden'));
            });

            // Also allow header click to toggle on mobile
            const sidebarHeader = document.querySelector('.sidebar-header');
            if (sidebarHeader) {
                sidebarHeader.addEventListener('click', function() {
                    if (window.innerWidth < 1024) {
                        sidebar.classList.toggle('hidden');
                        grid.classList.toggle('sidebar-hidden');
                        setToggleExpanded(!sidebar.classList.contains('hidden'));
                    }
                });
            }

            // Close sidebar after choosing a destination on mobile.
            sidebarLinks.forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 1024) {
                        sidebar.classList.add('hidden');
                        grid.classList.add('sidebar-hidden');
                        setToggleExpanded(false);
                    }
                });
            });

            // Update on resize
            window.addEventListener('resize', updateSidebarVisibility);

            // Initial check
            updateSidebarVisibility();
        });

        // Image Optimizer Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const imageSelect = document.getElementById('image_select');
            const qualitySlider = document.getElementById('quality');
            const qualityValue = document.getElementById('quality_value');
            const optimizeBtn = document.getElementById('optimize_btn');
            const optimizeForm = document.getElementById('optimize_form');
            const resultsContainer = document.getElementById('results_container');
            const resultsText = document.getElementById('results_text');
            const formImagePath = document.getElementById('form_image_path');
            const formQuality = document.getElementById('form_quality');

            if (!imageSelect || !qualitySlider || !optimizeBtn) return;

            // Update quality display
            qualitySlider.addEventListener('input', function() {
                qualityValue.textContent = this.value;
                formQuality.value = this.value;
            });

            // Enable/disable optimize button based on image selection
            imageSelect.addEventListener('change', function() {
                optimizeBtn.disabled = !this.value;
                formImagePath.value = this.value;
            });

            // Handle form submission
            optimizeForm.addEventListener('submit', function(e) {
                e.preventDefault();

                if (!imageSelect.value) {
                    showToast('Selecteer een afbeelding om te optimaliseren.', 'error');
                    return;
                }

                optimizeBtn.disabled = true;
                optimizeBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Bezig...';

                fetch('admin.php', {
                        method: 'POST',
                        body: new FormData(this)
                    })
                    .then(response => response.text())
                    .then(data => {
                        optimizeBtn.disabled = false;
                        optimizeBtn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Afbeelding optimaliseren';

                        // Check if optimization was successful
                        if (data.includes('alert-success') || data.includes('Afbeelding geoptimaliseerd')) {
                            // Extract success message
                            const match = data.match(/Afbeelding geoptimaliseerd[^<]*/);
                            if (match) {
                                const message = match[0];
                                showToast(message, 'success');
                                resultsText.textContent = message;
                                resultsContainer.style.display = 'block';

                                // Reset form after 2 seconds
                                setTimeout(() => {
                                    imageSelect.value = '';
                                    optimizeBtn.disabled = true;
                                    resultsContainer.style.display = 'none';
                                }, 3000);
                            }
                        } else {
                            // Check for error
                            const errorMatch = data.match(/<div[^>]*class="alert alert-error"[^>]*>[\s\S]*?<\/div>/);
                            if (errorMatch) {
                                const errorDiv = document.createElement('div');
                                errorDiv.innerHTML = errorMatch[0];
                                const errorText = errorDiv.textContent.trim();
                                showToast(errorText, 'error');
                            } else {
                                showToast('Onbekende fout bij optimalisatie.', 'error');
                            }
                        }
                    })
                    .catch(error => {
                        optimizeBtn.disabled = false;
                        optimizeBtn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Afbeelding optimaliseren';
                        showToast('Fout bij communicatie met server: ' + error, 'error');
                    });
            });

            // Check if optimization was successful after page reload
            <?php if (!empty($_SESSION['optimize_success'])): ?>
                const optimizeData = <?php echo json_encode($_SESSION['optimize_data'] ?? []); ?>;
                if (optimizeData && optimizeData.saved_percent) {
                    const message = 'Afbeelding geoptimaliseerd! ' +
                        (optimizeData.saved_bytes / 1024 / 1024).toFixed(2) + ' MB bespaard (' +
                        optimizeData.saved_percent + '%).';
                    showToast(message, 'success');
                    resultsText.textContent = message;
                    resultsContainer.style.display = 'block';
                }
                <?php unset($_SESSION['optimize_success'], $_SESSION['optimize_data']); ?>
            <?php endif; ?>
        });

        // Toast Notification Function
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = 'toast toast-' + type;
            toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 16px 24px;
        background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
        color: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        max-width: 400px;
        font-size: 14px;
        animation: slideIn 0.3s ease-out;
    `;
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease-out forwards';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        // Add CSS animations for toast
        const style = document.createElement('style');
        style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }
`;
        document.head.appendChild(style);
    </script>

    <!-- Image Modal -->
    <div id="imageModal" class="image-modal">
        <div class="image-modal-content">
            <button class="image-modal-close" onclick="closeImageModal()" aria-label="Sluiten">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <img id="modalImage" src="" alt="Preview">
        </div>
    </div>
    <script>
        document.addEventListener("DOMContentLoaded", function() {

            const sidebar = document.querySelector('.sidebar');
            const toggleBtn = document.querySelector('#sidebarToggle');

            if (!sidebar || !toggleBtn) return;

            // Toggle sidebar
            toggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                sidebar.classList.toggle('open');
            });

            // Klik buiten = sluiten
            document.addEventListener('click', function(e) {
                if (!sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
                    sidebar.classList.remove('open');
                }
            });

            // klik binnen sidebar = niet sluiten
            sidebar.addEventListener('click', function(e) {
                e.stopPropagation();
            });

        });
    </script>
</body>

</html>
