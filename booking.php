<?php
session_start();
require 'db.php';
require 'helpers.php';
require 'booking_hardware.php';
require_once 'role_permissions.php';

$sessionRole = siteNormalizeAccountRole($_SESSION['role'] ?? '', 0);
$sessionPermissions = sitePermissionsForRole($sessionRole);
$_SESSION['role'] = $sessionRole;
$_SESSION['admin'] = $sessionRole === 'administrator' ? 1 : 0;
$_SESSION['permissions'] = json_encode($sessionPermissions, JSON_UNESCAPED_SLASHES);
$_SESSION['can_access_admin'] = !empty($sessionPermissions);

if (!$_SESSION['can_access_admin']) {
    header('Location: login.php');
    exit;
}

if (!in_array('access_booking', $sessionPermissions, true)) {
    header('Location: admin.php');
    exit;
}
$canDeleteBookings = in_array('delete_bookings', $sessionPermissions, true);
if (!isset($_SESSION['booking_csrf_token']) || !is_string($_SESSION['booking_csrf_token'])) {
    $_SESSION['booking_csrf_token'] = bin2hex(random_bytes(32));
}
$bookingCsrfToken = $_SESSION['booking_csrf_token'];

// Staff color palette (auto-assigned to each unique staff member)
$staffColorPalette = [
    '#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8',
    '#F7DC6F', '#BB8FCE', '#85C1E2', '#F8B88B', '#52C9A8',
    '#E57373', '#64B5F6', '#81C784', '#FFD54F', '#BA68C8'
];

function getStaffColor($pdo, $staffName, $palette) {
    // Check if staff already has a color assigned
    $stmt = $pdo->prepare("SELECT color FROM location_staff WHERE staff_name = ? LIMIT 1");
    $stmt->execute([$staffName]);
    $existing = $stmt->fetchColumn();
    
    if ($existing) {
        return $existing; // Reuse existing color
    }
    
    // Get all colors in use
    $stmt = $pdo->prepare("SELECT DISTINCT color FROM location_staff WHERE color IS NOT NULL");
    $stmt->execute();
    $usedColors = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $usedColors = array_map('strtoupper', $usedColors);
    
    // Find first available color from palette
    foreach ($palette as $color) {
        if (!in_array(strtoupper($color), $usedColors)) {
            return $color;
        }
    }
    
    // If all colors used, return a random one (shouldn't happen with 15 colors)
    return $palette[count($usedColors) % count($palette)];
}

$currentMonth = $_GET['month'] ?? date('n');
$currentYear = $_GET['year'] ?? date('Y');
$view = $_GET['view'] ?? 'week';
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedDay = $_GET['selected_day'] ?? date('Y-m-d');

$selectedDateTime = new DateTime($selectedDate);
$weekStart = (clone $selectedDateTime)->modify('monday this week');
$weekEnd = (clone $weekStart)->modify('+6 days');

$locations = [
        ['id' => 1, 'name' => 'Sociaal AI Lab Hillevliet', 'color' => '#00811F'],
        ['id' => 2, 'name' => 'Labkar', 'color' => '#0066CC'],
        ['id' => 999, 'name' => 'Extern (overig)', 'color' => '#FF9500'],
];

$tables = [
    'gt1' => 'Grote tafel 1',
    'gt2' => 'Grote tafel 2',
    'gt3' => 'Grote tafel 3',
    'kt1' => 'Kleine tafel 1',
    'kt2' => 'Kleine tafel 2',
    'kt3' => 'Kleine tafel 3',
];

$hardware = [
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

function findById($arr, $id) {
    foreach ($arr as $i) if ($i['id'] == $id) return $i;
    return null;
}

/* LOAD BOOKINGS FIRST */
// Ensure database has `event_responsibility` column (safe, idempotent if permissions allow)
try {
    $col = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'event_responsibility'")->fetch();
    if (!$col) {
        // Try to add the column; if user/db does not permit this will throw and be ignored
        $pdo->exec("ALTER TABLE bookings ADD COLUMN event_responsibility TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Exception $e) {
    // Ignore - if DB user cannot alter table we'll still continue but won't store the field
}

$stmt = $pdo->prepare("SELECT * FROM bookings");
$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Also show approved agenda events in booking calendar/day view
$stmt = $pdo->prepare("SELECT id, title, date, end_date, time, time_end, location, hardware_request, staff_present, description, meer_info FROM events WHERE approval_status = 'approved'");
$stmt->execute();
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$message = "";
// Support flash messages from booking POST handling
if (!empty($_SESSION['booking_message'])) {
    $message = $_SESSION['booking_message'];
    unset($_SESSION['booking_message']);
}
$messageType = $_SESSION['booking_message_type'] ?? 'error';
unset($_SESSION['booking_message_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_booking_id'])) {
    if (!$canDeleteBookings) {
        $_SESSION['booking_message'] = 'Je hebt geen rechten om boekingen te verwijderen.';
        $_SESSION['booking_message_type'] = 'error';
    } elseif (!isset($_POST['booking_csrf_token']) || !is_string($_POST['booking_csrf_token'])
        || !hash_equals($bookingCsrfToken, $_POST['booking_csrf_token'])) {
        $_SESSION['booking_message'] = 'De aanvraag kon niet worden geverifieerd. Vernieuw de pagina en probeer opnieuw.';
        $_SESSION['booking_message_type'] = 'error';
    } else {
        $rawBookingId = $_POST['delete_booking_id'];
        $bookingId = is_string($rawBookingId) && preg_match('/^[1-9][0-9]*$/D', $rawBookingId)
            ? filter_var($rawBookingId, FILTER_VALIDATE_INT)
            : false;

        if ($bookingId === false || $bookingId < 1) {
            $_SESSION['booking_message'] = 'Ongeldige boeking geselecteerd.';
            $_SESSION['booking_message_type'] = 'error';
        } else {
            $lockAcquired = false;
            $transactionStarted = false;
            try {
                $engineStatement = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'");
                if (strtoupper((string)$engineStatement->fetchColumn()) !== 'INNODB') {
                    throw new RuntimeException('De boekingstabel ondersteunt geen veilige transacties.');
                }

                $lockStatement = $pdo->prepare('SELECT GET_LOCK(?, 10)');
                $lockStatement->execute(['sociaalailab_booking_hardware_inventory']);
                $lockAcquired = (int)$lockStatement->fetchColumn() === 1;
                if (!$lockAcquired) {
                    throw new RuntimeException('De voorraad is tijdelijk in gebruik.');
                }

                $pdo->beginTransaction();
                $transactionStarted = true;
                $bookingStatement = $pdo->prepare('SELECT id FROM bookings WHERE id = ? FOR UPDATE');
                $bookingStatement->execute([$bookingId]);
                if (!$bookingStatement->fetchColumn()) {
                    $pdo->rollBack();
                    $transactionStarted = false;
                    $_SESSION['booking_message'] = 'Deze boeking bestaat niet meer of is al verwijderd.';
                    $_SESSION['booking_message_type'] = 'error';
                } else {
                    $deleteStatement = $pdo->prepare('DELETE FROM bookings WHERE id = ?');
                    $deleteStatement->execute([$bookingId]);
                    if ($deleteStatement->rowCount() !== 1) {
                        throw new RuntimeException('De boeking kon niet worden verwijderd.');
                    }
                    $pdo->commit();
                    $transactionStarted = false;
                    $_SESSION['booking_message'] = 'De boeking is verwijderd.';
                    $_SESSION['booking_message_type'] = 'success';
                }
            } catch (Throwable $exception) {
                if ($transactionStarted && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Booking deletion failed: ' . $exception->getMessage());
                $_SESSION['booking_message'] = 'De boeking kon niet veilig worden verwijderd. Probeer het opnieuw.';
                $_SESSION['booking_message_type'] = 'error';
            } finally {
                if ($lockAcquired) {
                    try {
                        $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                        $releaseStatement->execute(['sociaalailab_booking_hardware_inventory']);
                    } catch (Throwable $exception) {
                        error_log('Could not release booking inventory lock: ' . $exception->getMessage());
                    }
                }
            }
        }
    }

    header("Location: booking.php?view=$view&date=$selectedDate&selected_day=$selectedDay");
    exit;
}

/* STAFF ASSIGNMENT HANDLING - CHECK FIRST */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_staff_assignment_id'])) {
    $assignmentId = intval($_POST['delete_staff_assignment_id']);
    if ($assignmentId > 0) {
        $stmt = $pdo->prepare("DELETE FROM location_staff WHERE id = ? AND location_id = 1");
        $stmt->execute([$assignmentId]);
    }
    header("Location: booking.php?view=$view&date=$selectedDate&selected_day=$selectedDay");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_dates'])) {
    $staffDates = trim($_POST['staff_dates']);
    $staffLocationId = $_POST['staff_location_id'] ?? 1;
    $staffNames = $_POST['staff_names'] ?? [];
    $startTime = trim($_POST['staff_start_time'] ?? null);
    $endTime = trim($_POST['staff_end_time'] ?? null);
    
    if (!empty($staffDates) && !empty($staffNames)) {
        $dateArray = preg_split('/[,;\n\r]+/', $staffDates, -1, PREG_SPLIT_NO_EMPTY);
        $dateArray = array_map('trim', $dateArray);
        
        foreach ($staffNames as $staffName) {
            $staffName = trim($staffName);
            if (empty($staffName)) continue;
            
            $staffColor = getStaffColor($pdo, $staffName, $staffColorPalette);
            
            foreach ($dateArray as $dateStr) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO location_staff (staff_date, location_id, staff_name, color, start_time, end_time)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$dateStr, $staffLocationId, $staffName, $staffColor, $startTime ?: null, $endTime ?: null]);
                }
            }
        }
    }

    header("Location: booking.php?view=$view&date=$selectedDate");
    exit;
}

/* BOOKING INSERT - support multiple locations selection */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_date'])) {
    $bookingDate = $_POST['booking_date'] ?? null;
    $bookingStartTime = $_POST['start_time'] ?? null;
    $bookingEndTime = $_POST['end_time'] ?? null;
    $bookingTitle = $_POST['booking_title'] ?? null;
    $locationIds = $_POST['location_ids'] ?? [];
    $tablesJson = $_POST['tables_json'] ?? '[]';
    $eventResponsibility = (isset($_POST['event_responsibility']) && ($_POST['event_responsibility'] === '1' || $_POST['event_responsibility'] === 'on')) ? 1 : 0;

    if (!$eventResponsibility) {
        $_SESSION['booking_message'] = 'You must accept responsibility for the event before submitting your booking.';
        header("Location: booking.php?view=$view&date=$selectedDate");
        exit;
    }

    try {
        $validatedPeriod = validateBookingPeriod($bookingDate, $bookingStartTime, $bookingEndTime);
        $bookingDate = $validatedPeriod['date'];
        $bookingStartTime = $validatedPeriod['start_time'];
        $bookingEndTime = $validatedPeriod['end_time'];

        $requestedHardware = validateBookingHardwareJson($_POST['hardware_json'] ?? null, $hardware);
        $hardwareJson = json_encode($requestedHardware, JSON_THROW_ON_ERROR);

        if (!is_array($locationIds)) {
            $locationIds = [$locationIds];
        }
        $knownLocationIds = array_column($locations, 'id');
        $validatedLocationIds = [];
        foreach ($locationIds as $locationId) {
            $locationId = bookingParsePositiveInteger($locationId, 'Locatie-ID');
            if (!in_array($locationId, $knownLocationIds, true)) {
                throw new InvalidArgumentException('Een geselecteerde locatie is ongeldig.');
            }
            if (!in_array($locationId, $validatedLocationIds, true)) {
                $validatedLocationIds[] = $locationId;
            }
        }
        if (!$validatedLocationIds) {
            throw new InvalidArgumentException('Selecteer ten minste één locatie.');
        }
    } catch (InvalidArgumentException $exception) {
        $_SESSION['booking_message'] = $exception->getMessage();
        header("Location: booking.php?view=$view&date=$selectedDate");
        exit;
    }

    $hasEventResponsibilityColumn = false;
    try {
        $hasEventResponsibilityColumn = (bool)$pdo->query("SHOW COLUMNS FROM bookings LIKE 'event_responsibility'")->fetch();
    } catch (Exception $exception) {
        $hasEventResponsibilityColumn = false;
    }

    $inserted = [];
    $skipped = [];
    $lockAcquired = false;
    $transactionStarted = false;
    $failureMessage = null;

    try {
        $engineStatement = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'");
        if (strtoupper((string)$engineStatement->fetchColumn()) !== 'INNODB') {
            throw new RuntimeException('De boekingstabel ondersteunt geen veilige transacties.');
        }

        $lockStatement = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lockStatement->execute(['sociaalailab_booking_hardware_inventory']);
        $lockAcquired = (int)$lockStatement->fetchColumn() === 1;
        if (!$lockAcquired) {
            throw new RuntimeException('De voorraad is tijdelijk in gebruik.');
        }

        $pdo->beginTransaction();
        $transactionStarted = true;
        $stmt = $pdo->prepare('SELECT id, location_id, booking_date, start_time, end_time, hardware_ids FROM bookings WHERE booking_date = ? FOR UPDATE');
        $stmt->execute([$bookingDate]);
        $bookingsForDate = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($validatedLocationIds as $locId) {
            $conflict = false;
            if ($locId !== 999) {
                foreach ($bookingsForDate as $booking) {
                    if ((int)$booking['location_id'] === $locId && bookingIntervalsOverlap(
                        $booking['booking_date'],
                        $booking['start_time'],
                        $booking['end_time'],
                        $bookingDate,
                        $bookingStartTime,
                        $bookingEndTime
                    )) {
                        $conflict = true;
                        break;
                    }
                }
            }

            if ($conflict) {
                $skipped[] = $locId;
                continue;
            }

            $availabilityError = getBookingHardwareAvailabilityError(
                $requestedHardware,
                $bookingsForDate,
                $bookingDate,
                $bookingStartTime,
                $bookingEndTime,
                $hardware
            );
            if ($availabilityError !== null) {
                throw new DomainException($availabilityError);
            }

            $locationDescription = $locId === 999 ? ($_POST['location_description'] ?? '') : null;
            $tablesForBooking = ($locId === 1) ? $tablesJson : '[]';

            if ($hasEventResponsibilityColumn) {
                $stmt = $pdo->prepare('INSERT INTO bookings (title, location_id, location_description, booking_date, start_time, end_time, hardware_ids, tables_ids, event_responsibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$bookingTitle, $locId, $locationDescription, $bookingDate, $bookingStartTime, $bookingEndTime, $hardwareJson, $tablesForBooking, $eventResponsibility]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO bookings (title, location_id, location_description, booking_date, start_time, end_time, hardware_ids, tables_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$bookingTitle, $locId, $locationDescription, $bookingDate, $bookingStartTime, $bookingEndTime, $hardwareJson, $tablesForBooking]);
            }

            $inserted[] = $locId;
            $bookingsForDate[] = [
                'location_id' => $locId,
                'booking_date' => $bookingDate,
                'start_time' => $bookingStartTime,
                'end_time' => $bookingEndTime,
                'hardware_ids' => $hardwareJson,
            ];
        }

        $pdo->commit();
        $transactionStarted = false;
    } catch (DomainException $exception) {
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $transactionStarted = false;
        $failureMessage = $exception->getMessage();
    } catch (Throwable $exception) {
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $transactionStarted = false;
        error_log('Booking transaction failed: ' . $exception->getMessage());
        $failureMessage = 'De boeking kon niet veilig worden opgeslagen. Probeer het opnieuw.';
    } finally {
        if ($lockAcquired) {
            try {
                $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $releaseStatement->execute(['sociaalailab_booking_hardware_inventory']);
            } catch (Throwable $exception) {
                error_log('Could not release booking inventory lock: ' . $exception->getMessage());
            }
        }
    }

    if ($failureMessage !== null) {
        $_SESSION['booking_message'] = $failureMessage;
    } else {
        $msgParts = [];
        if ($inserted) {
            $names = [];
            foreach ($inserted as $id) {
                $loc = findById($locations, $id);
                $names[] = $loc ? $loc['name'] : "#{$id}";
            }
            $msgParts[] = 'Geboekt: ' . implode(', ', $names);
        }
        if ($skipped) {
            $names = [];
            foreach ($skipped as $id) {
                $loc = findById($locations, $id);
                $names[] = $loc ? $loc['name'] : "#{$id}";
            }
            $msgParts[] = 'Kon niet boeken (conflict): ' . implode(', ', $names);
        }
        if ($msgParts) {
            $_SESSION['booking_message'] = implode(' | ', $msgParts);
        }
    }

    header("Location: booking.php?view=$view&date=$selectedDate");
    exit;
}

/* LOAD ALL STAFF ASSIGNMENTS FOR DISPLAY */
$stmt = $pdo->prepare("SELECT DISTINCT staff_name, color, COUNT(DISTINCT staff_date) as num_dates FROM location_staff WHERE location_id = 1 GROUP BY staff_name, color ORDER BY staff_name");
$stmt->execute();
$allStaffAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* LOAD ALL NAMES FROM ACCOUNTS FOR DROPDOWN */
$allStaffNameList = $pdo->query("
    SELECT DISTINCT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) COLLATE utf8mb4_general_ci AS name
    FROM accounts WHERE first_name IS NOT NULL AND first_name != '' ORDER BY name
")->fetchAll(PDO::FETCH_COLUMN);

/* LOAD STAFF ASSIGNMENTS FOR SELECTED DAY */
$stmt = $pdo->prepare("SELECT * FROM location_staff WHERE staff_date = ?");
/* LOAD STAFF DATA FOR SELECTED DAY AND MERGE TIME SLOTS */
$stmt = $pdo->prepare("SELECT id, staff_name, start_time, end_time, color FROM location_staff WHERE staff_date = ? AND location_id = 1 ORDER BY start_time");
$stmt->execute([$selectedDay]);
$staffData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by staff_name and merge overlapping/adjacent time slots
$staffByLocation = [];
$staffGroups = [];
foreach ($staffData as $staff) {
    $name = $staff['staff_name'];
    if (!isset($staffGroups[$name])) {
        $staffGroups[$name] = ['color' => $staff['color'], 'slots' => []];
    }
    $staffGroups[$name]['slots'][] = [
        'start' => $staff['start_time'],
        'end' => $staff['end_time']
    ];
}

// Merge overlapping and adjacent time slots for each staff member
foreach ($staffGroups as $name => $group) {
    $slots = $group['slots'];
    
    if (empty($slots)) continue;
    
    // Sort by start time
    usort($slots, function($a, $b) {
        $aStart = $a['start'] ?? '00:00';
        $bStart = $b['start'] ?? '00:00';
        return strcmp($aStart, $bStart);
    });
    
    // Merge overlapping/adjacent slots
    $merged = [];
    foreach ($slots as $slot) {
        $start = $slot['start'];
        $end = $slot['end'];
        
        if (empty($merged)) {
            $merged[] = $slot;
        } else {
            $lastSlot = &$merged[count($merged) - 1];
            $lastEnd = $lastSlot['end'] ?? '23:59';
            $currentStart = $start ?? '00:00';
            
            // Check if slots overlap or are adjacent (within 1 minute)
            if (strtotime($currentStart) <= strtotime($lastEnd)) {
                // Merge: extend the end time if new slot ends later
                if ($end && (!$lastSlot['end'] || strtotime($end) > strtotime($lastSlot['end']))) {
                    $lastSlot['end'] = $end;
                }
            } else {
                // No overlap, add as new slot
                $merged[] = $slot;
            }
        }
    }
    
    $staffByLocation[$name] = [
        'color' => $group['color'],
        'slots' => $merged
    ];
}

/* LOAD ALL DATES WITH STAFF FOR CALENDAR INDICATOR */
$stmt = $pdo->prepare("SELECT staff_date, GROUP_CONCAT(color SEPARATOR '|') as colors FROM location_staff WHERE location_id = 1 GROUP BY staff_date");
$stmt->execute();
$staffByDate = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $staffByDate[$row['staff_date']] = explode('|', $row['colors']);
}
$daysWithStaff = array_keys($staffByDate);
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Booking</title>

    <link rel="stylesheet" href="style/booking.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>

<header class="topbar">
    <div class="logo"><i class="fa fa-calendar"></i> Booking</div>
    <div class="nav">
        <a href="index.php">Terug naar site</a>
        <a href="admin.php">Terug naar admin</a>
        <a href="logout.php">Uitloggen</a>
    </div>
</header>

<div class="container">

    <!-- BOOKINGS -->
    <section class="card">
        <h2>Boekingen <?php if ($selectedDay): ?> - <?= date('d F Y', strtotime($selectedDay)) ?><?php endif; ?></h2>

        <?php 
        $dayBookings = array_filter($bookings, function($b) use ($selectedDay) {
            return $selectedDay && $b['booking_date'] === $selectedDay;
        });
        $dayEvents = array_filter($events, function($e) use ($selectedDay) {
            if (!$selectedDay || empty($e['date'])) return false;
            $start = $e['date'];
            $end = $e['end_date'] ?: $e['date'];
            return $selectedDay >= $start && $selectedDay <= $end;
        });
        
        if ($selectedDay && empty($dayBookings) && empty($dayEvents)): 
        ?>
            <p style="color: #999; text-align: center; padding: 2rem;">Geen boekingen op deze dag</p>
        <?php elseif (!$selectedDay): ?>
            <p style="color: #999; text-align: center; padding: 2rem;">Selecteer een dag om boekingen te zien</p>
        <?php else: ?>
            <?php foreach ($dayEvents as $e): ?>
                <?php
                $eventPayload = [
                    'title' => (string)($e['title'] ?? 'Ongetiteld event'),
                    'location' => (string)($e['location'] ?? 'Locatie volgt'),
                    'date' => (string)($e['date'] ?? ''),
                    'end_date' => (string)($e['end_date'] ?? ''),
                    'time' => (string)($e['time'] ?? ''),
                    'time_end' => (string)($e['time_end'] ?? ''),
                    'hardware_request' => (string)($e['hardware_request'] ?? ''),
                    'staff_present' => (string)($e['staff_present'] ?? ''),
                    'description' => (string)($e['description'] ?? ''),
                    'meer_info' => (string)($e['meer_info'] ?? ''),
                ];
                $eventPayloadJson = htmlspecialchars(
                    json_encode($eventPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ENT_QUOTES,
                    'UTF-8'
                );
                ?>
                <div class="booking booking-event" style="border-left-color:#2563eb;">
                    <div>
                        <strong><?= htmlspecialchars($e['title'] ?? 'Ongetiteld event') ?></strong><br>
                        <?= htmlspecialchars($e['location'] ?? 'Locatie volgt') ?><br>
                        <span style="font-size: 0.9rem; color: #666;">
                            <?= !empty($e['time']) ? substr($e['time'], 0, 5) : 'Tijd volgt' ?><?= !empty($e['time_end']) ? ' - ' . substr($e['time_end'], 0, 5) : '' ?>
                        </span>
                        <?php if (!empty($e['hardware_request'])): ?>
                            <br><span style="font-size: 0.85rem; color: #2563eb;"><strong>Hardware:</strong> <?= nl2br(htmlspecialchars($e['hardware_request'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($e['staff_present'])): ?>
                            <br><span style="font-size: 0.85rem; color: #1f2937;"><strong>SociaalAI Lab erbij:</strong> <?= nl2br(htmlspecialchars($e['staff_present'])) ?></span>
                        <?php endif; ?>
                        <div style="margin-top: 0.75rem;">
                            <button type="button" class="event-info-btn js-event-info-btn" data-event="<?php echo $eventPayloadJson; ?>">
                                Meer info
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
             <?php foreach ($dayBookings as $b): ?>
                 <?php $loc = findById($locations, $b['location_id']); ?>
                 <div class="booking">
                     <div>
                         <strong><?= htmlspecialchars($b['title'] ?? 'Ongetiteld') ?></strong><br>
                         <?php if ($b['location_id'] == 999): ?>
                             Extern (overig)<?php if (!empty($b['location_description'])): ?> - <?= htmlspecialchars($b['location_description']) ?><?php endif; ?>
                         <?php else: ?>
                             <?= htmlspecialchars($loc['name']) ?>
                         <?php endif; ?><br>
                         <span style="font-size: 0.9rem; color: #666;">
                             <?= substr($b['start_time'], 0, 5) ?> - <?= substr($b['end_time'], 0, 5) ?>
                            <?php
                            // Show tables if this is Sociaal AI Lab Hillevliet
                            if ($b['location_id'] == 1 && !empty($b['tables_ids'])):
                                $tablesList = json_decode($b['tables_ids'], true);
                                if (!empty($tablesList)):
                                    $tableNames = [];
                                    foreach ($tablesList as $tableId) {
                                        $tableNames[] = $tables[$tableId] ?? $tableId;
                                    }
                            ?>
                                <br><span style="font-size: 0.85rem; color: #0066CC;"><strong>Tafels:</strong> <?= implode(', ', $tableNames) ?></span>
                            <?php endif; endif; ?>

                            <?php
                            if ($b['hardware_ids']): 
                                $hwList = json_decode($b['hardware_ids'], true);
                                if (!empty($hwList)):
                            ?>
                                <br><span style="font-size: 0.85rem; color: #00811F;"><strong>Hardware:</strong>
                                <?php 
                                $hwNames = [];
                                foreach ((array)$hwList as $hw) {
                                    if (isset($hw['id']) && isset($hw['quantity'])) {
                                        foreach ($hardware as $h) {
                                            if ($h['id'] == $hw['id']) {
                                                $hwNames[] = $hw['quantity'] . '× ' . $h['name'];
                                                break;
                                            }
                                        }
                                    }
                                }
                                echo implode(', ', $hwNames);
                                ?>
                                </span>
                            <?php endif; endif; ?>
                            <?php
                            // Show Event Responsibility flag if available
                            if (isset($b['event_responsibility'])):
                                $accepted = !empty($b['event_responsibility']);
                            ?>
                                <br><span style="font-size: 0.9rem; color: #374151;"><strong>Event Responsibility Accepted:</strong> <?php echo $accepted ? '✅ Yes' : '❌ No'; ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($canDeleteBookings): ?>
                        <form method="POST" class="booking-delete-form" onsubmit="return confirm('Weet je zeker dat je deze boeking wilt verwijderen?');">
                            <input type="hidden" name="delete_booking_id" value="<?= (int)$b['id'] ?>">
                            <input type="hidden" name="booking_csrf_token" value="<?= htmlspecialchars($bookingCsrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <button type="submit" class="booking-delete-btn">Verwijderen</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if ($selectedDay && !empty($staffByLocation)): ?>
            <div style="margin-top: 2rem; border-top: 2px solid #dfe8f3; padding-top: 1.5rem;">
                <h3 style="margin-bottom: 1rem; color: #00811F;">Ingedeeld personeel</h3>
                <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
                    <?php foreach ($staffByLocation as $name => $info): ?>
                        <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; background: #f9fafb; border-radius: 10px; border: 2px solid #dfe8f3;">
                            <div style="width: 20px; height: 20px; border-radius: 4px; background: <?= htmlspecialchars($info['color']) ?>; border: 2px solid #ddd;"></div>
                            <div>
                                <span style="font-weight: 600; color: #00811F; display: block;"><?= htmlspecialchars($name) ?></span>
                                <span style="color: #666; font-size: 0.85rem;">
                                    <?php 
                                    if (empty($info['slots'])) {
                                        echo 'Hele dag';
                                    } else {
                                        $timeStrs = [];
                                        foreach ($info['slots'] as $slot) {
                                            if ($slot['start'] && $slot['end']) {
                                                $timeStrs[] = substr($slot['start'], 0, 5) . '-' . substr($slot['end'], 0, 5);
                                            } else {
                                                $timeStrs[] = 'Hele dag';
                                            }
                                        }
                                        echo implode(', ', $timeStrs);
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 1.25rem;">
                    <h4 style="margin-bottom: 1rem; color: #00811F;">Personeelsindeling verwijderen</h4>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <?php foreach ($staffData as $assignment): ?>
                            <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem 1rem; background: #f5f8ff; border-radius: 10px; border: 1px solid #dfe8f3;">
                                <div>
                                    <span style="font-weight: 600; color: #00811F; display: block;"><?= htmlspecialchars($assignment['staff_name']) ?></span>
                                    <span style="color: #666; font-size: 0.85rem;">
                                        <?= $assignment['start_time'] ? substr($assignment['start_time'], 0, 5) : 'Hele dag' ?>
                                        <?= $assignment['end_time'] ? ' - ' . substr($assignment['end_time'], 0, 5) : '' ?>
                                    </span>
                                </div>
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="delete_staff_assignment_id" value="<?= intval($assignment['id']) ?>">
                                    <button type="submit" class="btn" style="padding: 0.6rem 1rem; font-size: 0.9rem; background: #ff6b6b; box-shadow: none;">Verwijderen</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <!-- MONTH CALENDAR -->
    <section class="card">
            <h2>Kalender <?= date('F Y', mktime(0, 0, 0, $currentMonth, 1, $currentYear)) ?></h2>
            <div class="nav-buttons">
                <?php 
                $prevMonth = $currentMonth - 1 ?: 12;
                $prevYear = $currentMonth - 1 ? $currentYear : $currentYear - 1;
                $nextMonth = ($currentMonth % 12) + 1;
                $nextYear = $currentMonth == 12 ? $currentYear + 1 : $currentYear;
                ?>
                <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn-nav"><i class="fa fa-chevron-left"></i></a>
                <a href="?month=<?= $currentMonth ?>&year=<?= $currentYear ?>" class="btn-nav"><?= date('F', mktime(0, 0, 0, $currentMonth, 1, $currentYear)) ?></a>
                <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn-nav"><i class="fa fa-chevron-right"></i></a>
            </div>
        </div>

        <table class="month-calendar">
            <thead>
                <tr>
                    <th>Ma</th>
                    <th>Di</th>
                    <th>Wo</th>
                    <th>Do</th>
                    <th>Vr</th>
                    <th>Za</th>
                    <th>Zo</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $firstDay = mktime(0, 0, 0, $currentMonth, 1, $currentYear);
                $lastDay = mktime(0, 0, 0, $currentMonth + 1, 0, $currentYear);
                $daysInMonth = date('d', $lastDay);
                $startWeekday = date('N', $firstDay) - 1;
                
                // Create array of dates with bookings
                $daysWithBookings = [];
                $bookingTitlesByDate = [];
                foreach ($bookings as $b) {
                    $daysWithBookings[] = $b['booking_date'];
                    if (!isset($bookingTitlesByDate[$b['booking_date']])) {
                        $bookingTitlesByDate[$b['booking_date']] = [];
                    }
                    if (!empty($b['title'])) {
                        $bookingTitlesByDate[$b['booking_date']][] = $b['title'];
                    }
                }
                foreach ($events as $e) {
                    if (empty($e['date'])) continue;
                    $startTs = strtotime($e['date']);
                    $endTs = strtotime($e['end_date'] ?: $e['date']);
                    if ($startTs === false || $endTs === false) continue;
                    for ($ts = $startTs; $ts <= $endTs; $ts = strtotime('+1 day', $ts)) {
                        $d = date('Y-m-d', $ts);
                        $daysWithBookings[] = $d;
                        if (!isset($bookingTitlesByDate[$d])) {
                            $bookingTitlesByDate[$d] = [];
                        }
                        if (!empty($e['title'])) {
                            $bookingTitlesByDate[$d][] = $e['title'];
                        }
                    }
                }
                
                $day = 1;
                for ($i = 0; $i < 6; $i++) {
                    echo "<tr>";
                    for ($j = 0; $j < 7; $j++) {
                        if (($i == 0 && $j < $startWeekday) || $day > $daysInMonth) {
                            echo "<td class=\"empty\"></td>";
                        } else {
                            $dateStr = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $day);
                            $today = date('Y-m-d');
                            $isPast = $dateStr < $today ? 'past' : '';
                            $isToday = $dateStr === date('Y-m-d') ? 'today' : '';
                            $isSelected = $dateStr === $selectedDay ? 'selected' : '';
                            $hasBooking = in_array($dateStr, $daysWithBookings) ? 'has-booking' : '';
                            $hasStaff = in_array($dateStr, $daysWithStaff) ? 'has-staff' : '';
                            // Past dates ARE now clickable to view history
                            $clickable = 'onclick="selectDay(\'' . $dateStr . '\', ' . $currentMonth . ', ' . $currentYear . ')" style="cursor: pointer;"';
                            echo "<td class=\"day-cell $isToday $isSelected $hasBooking $hasStaff $isPast\" $clickable>";
                            echo "<span class=\"day-number\">$day</span>";
                            if ($hasStaff) {
                                echo "<div class=\"staff-indicators\">";
                                if (isset($staffByDate[$dateStr])) {
                                    foreach ($staffByDate[$dateStr] as $color) {
                                        echo "<div class=\"staff-indicator\" style=\"background-color: " . htmlspecialchars($color) . ";\"></div>";
                                    }
                                }
                                echo "</div>";
                            }
                            if ($hasBooking) {
                                echo "<div class=\"booking-indicator\"></div>";
                                if (!empty($bookingTitlesByDate[$dateStr])) {
                                    echo "<div class=\"booking-titles\">";
                                    foreach ($bookingTitlesByDate[$dateStr] as $title) {
                                        echo "<div class=\"booking-title\">" . htmlspecialchars($title) . "</div>";
                                    }
                                    echo "</div>";
                                }
                            }
                            echo "</td>";
                            $day++;
                        }
                    }
                    echo "</tr>";
                    if ($day > $daysInMonth) break;
                }
                ?>
            </tbody>
        </table>
    </section>

    <!-- TIME SCHEDULES FOR ALL DAYS (Hidden by default) -->
    <?php
    $firstDay = mktime(0, 0, 0, $currentMonth, 1, $currentYear);
    $lastDay = mktime(0, 0, 0, $currentMonth + 1, 0, $currentYear);
    $daysInMonth = date('d', $lastDay);
    $startWeekday = date('N', $firstDay) - 1;
    
    $day = 1;
    for ($i = 0; $i < 6; $i++) {
        for ($j = 0; $j < 7; $j++) {
            if (($i == 0 && $j < $startWeekday) || $day > $daysInMonth) {
                // empty cells
            } else {
                $dateStr = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $day);
                $selectedDateObj = new DateTime($dateStr);
                $dayOfWeek = $selectedDateObj->format('N');
                $isWeekend = ($dayOfWeek >= 6);
                ?>
                <?php
                $day++;
            }
        }
    }
    ?>

    <!-- FORM 2 -->
    <section class="card form-card">
        <h2>Nieuwe booking</h2>

        <?php if ($message): ?>
            <div class="message <?= $messageType === 'success' ? 'success-message' : 'error-message' ?>">
                <i class="fa fa-exclamation-circle"></i> <?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="bookingForm">
            <input type="text" name="booking_title" id="booking_title" placeholder="Titel/naam van de booking (bijv: Team Meeting, Workshop)" required>
            
            <input type="date" name="booking_date" id="booking_date" required>

            <div style="margin-bottom: 1.2rem;">
                <label style="display: block; margin-bottom: 0.75rem; font-weight: 600; color: #00811F;">Selecteer locatie(s):</label>
                <div class="location-buttons">
                    <?php foreach ($locations as $l): ?>
                        <label class="location-button" style="cursor: pointer;">
                            <input type="checkbox" name="location_ids[]" value="<?= $l['id'] ?>" onchange="toggleLocationDescription(); toggleTableSelection()">
                            <span style="display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1rem; background: #f9fafb; border: 2px solid #dfe8f3; border-radius: 10px; font-weight: 500; color: #333; transition: all 0.3s ease;">
                                <div style="width: 12px; height: 12px; border-radius: 3px; border: 2px solid #dfe8f3; background: white;"></div>
                                <?= htmlspecialchars($l['name']) ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Tafelselectie voor Sociaal AI Lab Hillevliet -->
            <div id="table_selection" style="display:none; margin-bottom: 1.2rem; padding: 1rem; background: #f9fafb; border-radius: 10px; border: 2px solid #dfe8f3;">
                <label style="display: block; margin-bottom: 0.75rem; font-weight: 600; color: #00811F;">Selecteer tafel(s) in Sociaal AI Lab Hillevliet:</label>
                
                <fieldset style="margin-bottom: 1rem; padding: 0.75rem; border: 1px solid #dfe8f3; border-radius: 8px; background: white;">
                    <legend style="font-weight: 600; color: #333; padding: 0 0.5rem;">Grote tafels</legend>
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="tables[]" value="gt1" style="cursor: pointer;">
                            <span>Grote tafel 1</span>
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="tables[]" value="gt2" style="cursor: pointer;">
                            <span>Grote tafel 2</span>
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="tables[]" value="gt3" style="cursor: pointer;">
                            <span>Grote tafel 3</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset style="padding: 0.75rem; border: 1px solid #dfe8f3; border-radius: 8px; background: white;">
                    <legend style="font-weight: 600; color: #333; padding: 0 0.5rem;">Kleine tafels</legend>
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="tables[]" value="kt1" style="cursor: pointer;">
                            <span>Kleine tafel 1</span>
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="tables[]" value="kt2" style="cursor: pointer;">
                            <span>Kleine tafel 2</span>
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="tables[]" value="kt3" style="cursor: pointer;">
                            <span>Kleine tafel 3</span>
                        </label>
                    </div>
                </fieldset>
            </div>

            <input type="text" name="location_description" id="location_description" placeholder="Waar/wat is de externe locatie?" style="display:none;">

            <div class="row">
                <input type="time" name="start_time" id="start_time" required>
                <input type="time" name="end_time" id="end_time" required>
            </div>

            <textarea name="staff_present" placeholder="Wie is/zijn er present bij deze booking?" style="resize: vertical; min-height: 60px;"></textarea>

            <div style="margin-bottom: 1.2rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #00811F;">Hardware toevoegen:</label>
                <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
                    <select id="hardware_select" style="flex: 1;">
                        <option value="">-- Kies hardware --</option>
                        <?php foreach ($hardware as $h): ?>
                            <option value="<?= $h['id'] ?>" data-max="<?= $h['quantity'] ?>"><?= htmlspecialchars($h['name']) ?> (max: <?= $h['quantity'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" id="hardware_qty" min="1" step="1" value="1" style="width: 80px;">
                    <button type="button" class="btn" onclick="addHardware()" style="width: auto; padding: 0.85rem 1.5rem;">Voeg toe</button>
                </div>
                
                <div id="selected_hardware" style="display: flex; flex-wrap: wrap; gap: 0.75rem;">
                    <!-- Items worden hier toegevoegd -->
                </div>
            </div>

             <!-- Hidden input voor hardware data -->
             <input type="hidden" name="hardware_json" id="hardware_json" value="[]">
             
             <!-- Hidden input voor tafels data -->
             <input type="hidden" name="tables_json" id="tables_json" value="[]">

                <!-- Event Responsibility card (required) -->
                <div style="margin: 1rem 0; padding: 1rem; border-radius: 12px; background: #fff; border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 6px 18px rgba(2,6,23,0.04);">
                    <div style="display:flex; gap:0.75rem; align-items:flex-start;">
                        <div style="font-size:1.25rem; color:#00811F; line-height:1; margin-top:2px;"><i class="fa fa-info-circle" aria-hidden="true"></i></div>
                        <div style="flex:1;">
                            <div style="font-weight:700; margin-bottom:0.5rem;">Event Responsibility</div>
                            <div style="font-size:0.95rem; color:#374151; margin-bottom:0.75rem;">The person submitting this booking is considered the primary organizer and is fully responsible for the event. By continuing, the organizer confirms that all provided information is accurate and accepts responsibility for guests, venue rules, damages, additional costs, and compliance with all applicable laws and regulations.</div>
                            <label style="display:flex; align-items:center; gap:0.5rem; font-weight:600; color:#374151;">
                                <input type="checkbox" id="event_responsibility" name="event_responsibility" value="1" required style="width:18px; height:18px;">
                                <span style="font-weight:600;">I confirm that I am the organizer (or authorized to book on behalf of the organizer) and accept full responsibility for this event, including compliance with the venue's terms and conditions.</span>
                            </label>
                            <div id="responsibilityError" style="color:#b91c1c; font-weight:600; margin-top:0.5rem; display:none;">You must accept responsibility for the event before submitting your booking.</div>
                        </div>
                    </div>
                </div>

             <button type="submit" class="btn" id="submitBtn">Boek nu</button>
        </form>
    </section>

</div>

<div id="eventInfoModal" class="event-info-modal" aria-hidden="true">
    <div class="event-info-dialog" role="dialog" aria-modal="true" aria-labelledby="eventInfoTitle">
        <div class="event-info-header">
            <h3 id="eventInfoTitle">Event informatie</h3>
            <button type="button" class="close-btn" onclick="closeEventInfoModal()" aria-label="Sluiten">&times;</button>
        </div>
        <div class="event-info-body">
            <p><strong>Titel:</strong> <span id="eventInfoFieldTitle">-</span></p>
            <p><strong>Wanneer:</strong> <span id="eventInfoFieldDate">-</span></p>
            <p><strong>Tijd:</strong> <span id="eventInfoFieldTime">-</span></p>
            <p><strong>Locatie:</strong> <span id="eventInfoFieldLocation">-</span></p>
            <p><strong>Hardware:</strong> <span id="eventInfoFieldHardware">-</span></p>
            <p><strong>SociaalAI Lab erbij:</strong> <span id="eventInfoFieldStaff">-</span></p>
            <p><strong>Omschrijving:</strong></p>
            <div id="eventInfoFieldDescription" class="event-info-block">Geen omschrijving beschikbaar.</div>
            <p><strong>Meer info:</strong></p>
            <div id="eventInfoFieldMeerInfo" class="event-info-block">Geen extra informatie beschikbaar.</div>
        </div>
    </div>
</div>

<script>
function selectDay(dateStr, month, year) {
    // Update URL to include selected_day parameter
    window.location.href = `?month=${month}&year=${year}&selected_day=${dateStr}`;
}

function checkIfPastDate(dateStr) {
    const submitBtn = document.getElementById('submitBtn');
    if (!submitBtn) return;

    const hasValidDate = /^\d{4}-\d{2}-\d{2}$/.test(dateStr)
        && !Number.isNaN(Date.parse(dateStr))
        && new Date(dateStr).toISOString().slice(0, 10) === dateStr;
    const today = new Date();
    const todayString = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');
    const isPastDate = hasValidDate && dateStr < todayString;

    submitBtn.disabled = isPastDate;
    submitBtn.title = isPastDate ? 'Je kan geen bookings toevoegen voor voorbijgegane dagen' : '';
}

// Toggle location description field based on whether extern (999) is selected
function toggleLocationDescription() {
     const checkboxes = document.querySelectorAll('input[name="location_ids[]"]');
     const descriptionField = document.getElementById('location_description');
     let externSelected = false;
     checkboxes.forEach(cb => {
         if (cb.checked && cb.value === '999') externSelected = true;
     });

     if (externSelected) {
         descriptionField.style.display = 'block';
         descriptionField.required = true;
     } else {
         descriptionField.style.display = 'none';
         descriptionField.required = false;
         descriptionField.value = '';
     }
 }

 // Toggle table selection based on whether Sociaal AI Lab Hillevliet (id=1) is selected
 function toggleTableSelection() {
     const checkboxes = document.querySelectorAll('input[name="location_ids[]"]');
     const tableSelection = document.getElementById('table_selection');
     let hillevlietSelected = false;
     checkboxes.forEach(cb => {
         if (cb.checked && cb.value === '1') hillevlietSelected = true;
     });

     if (hillevlietSelected) {
         tableSelection.style.display = 'block';
     } else {
         tableSelection.style.display = 'none';
         // Clear table selections if Hillevliet is deselected
         const tableCheckboxes = document.querySelectorAll('input[name="tables[]"]');
         tableCheckboxes.forEach(cb => {
             cb.checked = false;
         });
     }
 }

// Initialize form with selected day if present
document.addEventListener('DOMContentLoaded', function() {
    const selectedDay = '<?= $selectedDay ?>';
    if (selectedDay) {
        document.getElementById('booking_date').value = selectedDay;
        
        // Check if date is in past and disable booking if so
        checkIfPastDate(selectedDay);
    }
    
    // Initialize location description field visibility
    toggleLocationDescription();
    toggleTableSelection();
    
    // Listen for date field changes to enable/disable submit button
    const dateInput = document.getElementById('booking_date');
    if (dateInput) {
        const updatePastDateState = function() {
            checkIfPastDate(this.value);
        };
        dateInput.addEventListener('input', updatePastDateState);
        dateInput.addEventListener('change', updatePastDateState);
    }
    
    // Add form validation on submit
    const bookingForm = document.getElementById('bookingForm');
    if (bookingForm) {
        bookingForm.addEventListener('submit', function(e) {
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            
            // Check if end time is after start time
            if (startTime && endTime && startTime >= endTime) {
                e.preventDefault();
                alert('De eindtijd moet na de begintijd liggen!');
                return false;
            }
        });
        
        // Update end_time minimum when start_time changes
        const startTimeInput = document.getElementById('start_time');
        if (startTimeInput) {
            startTimeInput.addEventListener('change', function() {
                const startTime = this.value;
                if (startTime) {
                    // Set end time to 1 hour after start time
                    const [hours, minutes] = startTime.split(':');
                    const nextHour = String(parseInt(hours) + 1).padStart(2, '0');
                    document.getElementById('end_time').value = nextHour + ':00';
                    document.getElementById('end_time').min = startTime;
                }
            });
        }
        
        // Auto-fill staff name fields if user is logged in
        const loggedInName = '<?= htmlspecialchars($_SESSION['first_name'] ?? '') ?>';
        if (loggedInName) {
            const staffNameInputs = document.querySelectorAll('input[name="staff_name"]');
            staffNameInputs.forEach(input => {
                if (input.value.trim() === '') {
                    input.placeholder = 'Naam van personeel (standaard: ' + loggedInName + ')';
                    input.addEventListener('focus', function() {
                        if (this.value.trim() === '') {
                            this.value = loggedInName;
                        }
                    });
                    input.addEventListener('blur', function() {
                        if (this.value.trim() === loggedInName) {
                            this.value = '';
                        }
                    });
                }
            });
        }
    }
});

// Hardware selection system
let selectedHardware = {};

function addHardware() {
    const select = document.getElementById('hardware_select');
    const qtyInput = document.getElementById('hardware_qty');
    const hardwareId = select.value;
    const quantityValue = qtyInput.value;
    
    if (!hardwareId) {
        alert('Kies eerst een hardware item');
        return;
    }

    if (!/^[1-9][0-9]*$/.test(quantityValue)) {
        alert('Voer een positief geheel aantal in.');
        qtyInput.focus();
        return;
    }

    const qty = Number(quantityValue);
    if (!Number.isSafeInteger(qty)) {
        alert('De ingevoerde hoeveelheid is te groot.');
        qtyInput.focus();
        return;
    }
    
    const option = select.options[select.selectedIndex];
    const maxQty = parseInt(option.dataset.max) || 1;
    const hardwareName = option.text.split(' (max:')[0];
    
    if (qty > maxQty) {
        alert(`Maximaal ${maxQty} beschikbaar van dit item`);
        return;
    }
    
    selectedHardware[hardwareId] = {
        name: hardwareName,
        qty: qty,
        max: maxQty
    };
    
    updateHardwareDisplay();
    select.value = '';
    qtyInput.value = '1';
}

function removeHardware(hardwareId) {
    delete selectedHardware[hardwareId];
    updateHardwareDisplay();
}

function updateHardwareDisplay() {
    const container = document.getElementById('selected_hardware');
    container.innerHTML = '';
    
    let hasItems = false;
    for (const [hwId, hw] of Object.entries(selectedHardware)) {
        hasItems = true;
        const chip = document.createElement('div');
        chip.style.cssText = 'display: flex; align-items: center; gap: 0.5rem; padding: 0.7rem 1rem; background: #f0f5ff; border-radius: 10px; border: 2px solid #dfe8f3;';
        chip.innerHTML = `
            <span style="flex: 1; font-weight: 600; color: #00811F;">${hw.name}</span>
            <span style="background: #00811F; color: white; padding: 0.3rem 0.6rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">${hw.qty}x</span>
            <button type="button" onclick="removeHardware('${hwId}')" style="background: none; border: none; color: #ff6b6b; cursor: pointer; font-size: 1.2rem; padding: 0;">×</button>
        `;
        container.appendChild(chip);
    }
    
    // Update hidden JSON field
    const jsonData = [];
    for (const [hwId, hw] of Object.entries(selectedHardware)) {
        jsonData.push({ id: hwId, quantity: hw.qty });
    }
    document.getElementById('hardware_json').value = JSON.stringify(jsonData);
}

function formatEventDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr + 'T00:00:00');
    if (Number.isNaN(date.getTime())) return dateStr;
    return date.toLocaleDateString('nl-NL', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
    });
}

function setEventInfoText(id, value, fallback = '-') {
    const el = document.getElementById(id);
    if (!el) return;
    const text = normalizeEventText(value);
    el.textContent = text !== '' ? text : fallback;
}

function setEventInfoMultiline(id, value, fallback) {
    const el = document.getElementById(id);
    if (!el) return;
    const text = normalizeEventText(value);
    el.textContent = text !== '' ? text : fallback;
}

function normalizeEventText(value) {
    return (value || '')
        .toString()
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/p>\s*<p>/gi, '\n\n')
        .replace(/<[^>]*>/g, '')
        .replace(/\r\n/g, '\n')
        .trim();
}

function openEventInfoModal(eventData) {
    const modal = document.getElementById('eventInfoModal');
    if (!modal) return;

    const dateStart = formatEventDate(eventData.date);
    const dateEnd = eventData.end_date ? formatEventDate(eventData.end_date) : '';
    const dateText = dateEnd && dateEnd !== dateStart ? `${dateStart} t/m ${dateEnd}` : dateStart;
    const timeStart = (eventData.time || '').toString().slice(0, 5);
    const timeEnd = (eventData.time_end || '').toString().slice(0, 5);
    const timeText = timeStart ? `${timeStart}${timeEnd ? ' - ' + timeEnd : ''}` : 'Tijd volgt';

    setEventInfoText('eventInfoFieldTitle', eventData.title, '-');
    setEventInfoText('eventInfoFieldDate', dateText, '-');
    setEventInfoText('eventInfoFieldTime', timeText, 'Tijd volgt');
    setEventInfoText('eventInfoFieldLocation', eventData.location, 'Locatie volgt');
    setEventInfoText('eventInfoFieldHardware', eventData.hardware_request, 'Geen hardware opgegeven');
    setEventInfoText('eventInfoFieldStaff', eventData.staff_present, 'Niet ingevuld');
    setEventInfoMultiline('eventInfoFieldDescription', eventData.description, 'Geen omschrijving beschikbaar.');
    setEventInfoMultiline('eventInfoFieldMeerInfo', eventData.meer_info, 'Geen extra informatie beschikbaar.');

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeEventInfoModal() {
    const modal = document.getElementById('eventInfoModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

document.addEventListener('click', function(e) {
    const btn = e.target.closest('.js-event-info-btn');
    if (btn) {
        const raw = btn.getAttribute('data-event');
        if (!raw) return;
        try {
            const data = JSON.parse(raw);
            openEventInfoModal(data);
        } catch (err) {
            console.error('Kon eventinfo niet laden:', err);
        }
        return;
    }

    const modal = document.getElementById('eventInfoModal');
    if (modal && e.target === modal) {
        closeEventInfoModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEventInfoModal();
    }
});

// Prevent form submit if no hardware selected
document.getElementById('bookingForm').addEventListener('submit', function(e) {
     // Save selected tables before submission
     const tableCheckboxes = document.querySelectorAll('input[name="tables[]"]:checked');
     const selectedTables = Array.from(tableCheckboxes).map(cb => cb.value);
     document.getElementById('tables_json').value = JSON.stringify(selectedTables);
     
     // Hardware is now optional - no validation needed
 });
 
// Additional client-side validation for Event Responsibility checkbox
document.addEventListener('DOMContentLoaded', function() {
    const bookingForm = document.getElementById('bookingForm');
    if (!bookingForm) return;
    bookingForm.addEventListener('submit', function(e) {
        const checkbox = document.getElementById('event_responsibility');
        const err = document.getElementById('responsibilityError');
        if (checkbox && !checkbox.checked) {
            e.preventDefault();
            if (err) err.style.display = 'block';
            checkbox.focus();
            return false;
        }
        if (err) err.style.display = 'none';
        return true;
    });
    const checkbox = document.getElementById('event_responsibility');
    if (checkbox) {
        checkbox.addEventListener('change', function() {
            const err = document.getElementById('responsibilityError');
            if (err) err.style.display = this.checked ? 'none' : 'none';
        });
    }
});

/* MULTI-SELECT STAFF DROPDOWN */
function toggleStaffDropdown() {
    const trigger = document.getElementById('staffMultiSelectTrigger');
    const panel = document.getElementById('staffOptions');
    if (!trigger || !panel) return;

    const isOpen = !panel.hidden;
    panel.hidden = isOpen;
    trigger.setAttribute('aria-expanded', String(!isOpen));
}

function closeStaffDropdown(restoreFocus) {
    const trigger = document.getElementById('staffMultiSelectTrigger');
    const panel = document.getElementById('staffOptions');
    if (!trigger || !panel || panel.hidden) return;

    panel.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    if (restoreFocus) trigger.focus();
}

function updateStaffTags() {
    const container = document.getElementById('staffTags');
    const checked = document.querySelectorAll('#staffOptions input[type="checkbox"]:checked');
    container.innerHTML = '';
    if (checked.length === 0) {
        container.innerHTML = '<span class="placeholder">Selecteer personeelsleden</span>';
    } else {
        checked.forEach(cb => {
            const tag = document.createElement('span');
            tag.className = 'staff-tag';
            tag.textContent = cb.value;
            container.appendChild(tag);
        });
    }
}

document.addEventListener('click', function(e) {
    const ms = document.getElementById('staffMultiSelect');
    if (ms && !ms.contains(e.target)) {
        closeStaffDropdown(false);
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeStaffDropdown(true);
    }
});
</script>

<!-- STAFF SECTION -->
<section class="card form-card">
    <h2>Aanwezigheid op locatie toevoegen</h2>
    
    <form method="POST">
        <input type="hidden" name="staff_location_id" value="1">
        
            <div class="multi-select" id="staffMultiSelect">
                <button type="button" id="staffMultiSelectTrigger" class="multi-select-trigger" aria-expanded="false" aria-controls="staffOptions" onclick="toggleStaffDropdown()">
                    <span class="multi-select-tags" id="staffTags">
                        <span class="placeholder">Selecteer personeelsleden</span>
                    </span>
                    <span class="dropdown-arrow">&#9660;</span>
                </button>
                <div class="multi-select-options" id="staffOptions" hidden>
                    <?php foreach ($allStaffNameList as $name): ?>
                        <label class="multi-option">
                            <input type="checkbox" name="staff_names[]" value="<?= htmlspecialchars($name) ?>" onchange="updateStaffTags()">
                            <span><?= htmlspecialchars($name) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        
        <div class="row">
            <input type="time" name="staff_start_time" id="staff_start_time" placeholder="Start tijd (optioneel)">
            <input type="time" name="staff_end_time" id="staff_end_time" placeholder="Eind tijd (optioneel)">
        </div>
        
        <input type="date" name="staff_dates" required>
        
        <small style="color: #999; margin: -0.6rem 0 0 0; font-size: 0.85rem;">Kleur wordt automatisch toegewezen!</small>
        
        <button type="submit" class="btn">Opslaan</button>
    </form>
</section>

</body>
</html>
