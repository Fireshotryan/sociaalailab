<?php

function bookingParsePositiveInteger($value, $fieldName)
{
    if (is_int($value)) {
        $number = $value;
    } elseif (is_string($value) && preg_match('/^[1-9][0-9]{0,6}$/D', $value)) {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false) {
            throw new InvalidArgumentException("{$fieldName} is ongeldig.");
        }
    } else {
        throw new InvalidArgumentException("{$fieldName} moet een positief geheel getal zijn.");
    }

    if ($number < 1 || $number > 1000000) {
        throw new InvalidArgumentException("{$fieldName} is ongeldig of te groot.");
    }

    return $number;
}

function validateBookingHardwareJson($hardwareJson, $hardware)
{
    if (!is_string($hardwareJson) || trim($hardwareJson) === '') {
        throw new InvalidArgumentException('Hardwaregegevens ontbreken of zijn ongeldig.');
    }

    try {
        $items = json_decode($hardwareJson, false, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new InvalidArgumentException('Hardwaregegevens bevatten geen geldige JSON.');
    }

    if (!is_array($items)) {
        throw new InvalidArgumentException('Hardwaregegevens moeten een lijst met hardware-items zijn.');
    }

    $inventory = [];
    foreach ($hardware as $hardwareItem) {
        $id = bookingParsePositiveInteger($hardwareItem['id'] ?? null, 'Inventaris-ID');
        $quantity = bookingParsePositiveInteger($hardwareItem['quantity'] ?? null, 'Inventarisvoorraad');
        $inventory[$id] = $hardwareItem + ['id' => $id, 'quantity' => $quantity];
    }

    $validated = [];
    $seenIds = [];
    foreach ($items as $item) {
        if (!$item instanceof stdClass) {
            throw new InvalidArgumentException('Elk hardware-item moet een object met een ID en hoeveelheid zijn.');
        }

        $properties = get_object_vars($item);
        if (count($properties) !== 2 || !array_key_exists('id', $properties) || !array_key_exists('quantity', $properties)) {
            throw new InvalidArgumentException('Elk hardware-item moet uitsluitend een ID en hoeveelheid bevatten.');
        }

        $id = bookingParsePositiveInteger($properties['id'], 'Hardware-ID');
        $quantity = bookingParsePositiveInteger($properties['quantity'], 'Hardwarehoeveelheid');
        if (!isset($inventory[$id])) {
            throw new InvalidArgumentException('Een aangevraagd hardware-item bestaat niet in de inventaris.');
        }
        if (isset($seenIds[$id])) {
            throw new InvalidArgumentException('Een hardware-item mag maar één keer in de aanvraag voorkomen.');
        }
        if ($quantity > $inventory[$id]['quantity']) {
            throw new InvalidArgumentException('Je kunt maximaal ' . $inventory[$id]['quantity'] . ' van ' . $inventory[$id]['name'] . ' aanvragen.');
        }

        $seenIds[$id] = true;
        $validated[] = ['id' => $id, 'quantity' => $quantity];
    }

    return $validated;
}

function bookingTimeToSeconds($time)
{
    if (!is_string($time) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D', $time)) {
        throw new RuntimeException('Een opgeslagen boeking bevat een ongeldige tijd.');
    }

    $parts = array_map('intval', explode(':', $time));
    return ($parts[0] * 3600) + ($parts[1] * 60) + ($parts[2] ?? 0);
}

function bookingIntervalsOverlap($dateA, $startA, $endA, $dateB, $startB, $endB)
{
    if ($dateA !== $dateB) {
        return false;
    }

    return bookingTimeToSeconds($startA) < bookingTimeToSeconds($endB)
        && bookingTimeToSeconds($endA) > bookingTimeToSeconds($startB);
}

function validateBookingPeriod($date, $startTime, $endTime)
{
    if (!is_string($date) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)) {
        throw new InvalidArgumentException('Kies een geldige boekingsdatum.');
    }
    if (!is_string($startTime) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $startTime)
        || !is_string($endTime) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $endTime)) {
        throw new InvalidArgumentException('Vul een geldige begin- en eindtijd in.');
    }

    $startDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $startTime);
    $startErrors = DateTimeImmutable::getLastErrors();
    $endDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $endTime);
    $endErrors = DateTimeImmutable::getLastErrors();

    if (!$startDateTime || !$endDateTime
        || ($startErrors !== false && ($startErrors['warning_count'] > 0 || $startErrors['error_count'] > 0))
        || ($endErrors !== false && ($endErrors['warning_count'] > 0 || $endErrors['error_count'] > 0))
        || $startDateTime->format('Y-m-d H:i') !== $date . ' ' . $startTime
        || $endDateTime->format('Y-m-d H:i') !== $date . ' ' . $endTime) {
        throw new InvalidArgumentException('Kies een geldige datum en geldige begin- en eindtijd.');
    }

    if ($endDateTime <= $startDateTime) {
        throw new InvalidArgumentException('De eindtijd moet na de begintijd liggen.');
    }

    return [
        'date' => $date,
        'start_time' => $startDateTime->format('H:i:s'),
        'end_time' => $endDateTime->format('H:i:s'),
    ];
}

function getBookingHardwareAvailabilityError($requestedHardware, $existingBookings, $bookingDate, $startTime, $endTime, $hardware)
{
    $inventory = [];
    foreach ($hardware as $hardwareItem) {
        $id = bookingParsePositiveInteger($hardwareItem['id'] ?? null, 'Inventaris-ID');
        $quantity = bookingParsePositiveInteger($hardwareItem['quantity'] ?? null, 'Inventarisvoorraad');
        $inventory[$id] = $hardwareItem + ['id' => $id, 'quantity' => $quantity];
    }

    $requested = [];
    foreach ($requestedHardware as $item) {
        $id = bookingParsePositiveInteger($item['id'] ?? null, 'Hardware-ID');
        $quantity = bookingParsePositiveInteger($item['quantity'] ?? null, 'Hardwarehoeveelheid');
        if (!isset($inventory[$id])) {
            throw new InvalidArgumentException('Een aangevraagd hardware-item bestaat niet in de inventaris.');
        }
        if (isset($requested[$id])) {
            throw new InvalidArgumentException('Een hardware-item mag maar één keer in de aanvraag voorkomen.');
        }
        $requested[$id] = ['quantity' => $quantity, 'events' => []];
    }

    $periodStart = bookingTimeToSeconds($startTime);
    $periodEnd = bookingTimeToSeconds($endTime);
    if ($periodEnd <= $periodStart) {
        throw new InvalidArgumentException('De eindtijd moet na de begintijd liggen.');
    }

    foreach ($existingBookings as $booking) {
        if (!bookingIntervalsOverlap(
            $booking['booking_date'],
            $booking['start_time'],
            $booking['end_time'],
            $bookingDate,
            $startTime,
            $endTime
        )) {
            continue;
        }

        if ($booking['hardware_ids'] === null || $booking['hardware_ids'] === '') {
            continue;
        }

        try {
            $storedItems = json_decode($booking['hardware_ids'], false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Een bestaande boeking bevat ongeldige hardwaregegevens.');
        }
        if (!is_array($storedItems)) {
            throw new RuntimeException('Een bestaande boeking bevat ongeldige hardwaregegevens.');
        }

        $bookingStart = max($periodStart, bookingTimeToSeconds($booking['start_time']));
        $bookingEnd = min($periodEnd, bookingTimeToSeconds($booking['end_time']));
        foreach ($storedItems as $storedItem) {
            if (!$storedItem instanceof stdClass || !property_exists($storedItem, 'id') || !property_exists($storedItem, 'quantity')) {
                throw new RuntimeException('Een bestaande boeking bevat ongeldige hardwaregegevens.');
            }

            try {
                $id = bookingParsePositiveInteger($storedItem->id, 'Opgeslagen hardware-ID');
                $quantity = bookingParsePositiveInteger($storedItem->quantity, 'Opgeslagen hardwarehoeveelheid');
            } catch (InvalidArgumentException $exception) {
                throw new RuntimeException('Een bestaande boeking bevat ongeldige hardwaregegevens.');
            }

            if (isset($requested[$id])) {
                $requested[$id]['events'][] = ['time' => $bookingStart, 'delta' => $quantity];
                $requested[$id]['events'][] = ['time' => $bookingEnd, 'delta' => -$quantity];
            }
        }
    }

    foreach ($requested as $id => $request) {
        $events = $request['events'];
        $events[] = ['time' => $periodStart, 'delta' => $request['quantity']];
        $events[] = ['time' => $periodEnd, 'delta' => -$request['quantity']];

        usort($events, function ($a, $b) {
            if ($a['time'] !== $b['time']) {
                return $a['time'] <=> $b['time'];
            }

            return $a['delta'] <=> $b['delta'];
        });

        $reserved = 0;
        $maximumReserved = 0;
        foreach ($events as $event) {
            $reserved += $event['delta'];
            $maximumReserved = max($maximumReserved, $reserved);
        }

        if ($maximumReserved > $inventory[$id]['quantity']) {
            $existingPeak = max(0, $maximumReserved - $request['quantity']);
            $available = max(0, $inventory[$id]['quantity'] - $existingPeak);
            return 'Onvoldoende voorraad voor ' . $inventory[$id]['name'] . ': nog ' . $available . ' beschikbaar voor deze periode.';
        }
    }

    return null;
}