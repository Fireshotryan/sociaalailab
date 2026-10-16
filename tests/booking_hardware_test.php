<?php
require __DIR__ . '/../booking_hardware.php';

$hardware = [
    ['id' => 1, 'name' => 'Werkstation', 'quantity' => 1],
    ['id' => 2, 'name' => 'Laptop', 'quantity' => 2],
];
$assertions = 0;

function assertSameValue($expected, $actual, $label)
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertInvalidHardware($json, $hardware, $label)
{
    global $assertions;
    $assertions++;
    try {
        validateBookingHardwareJson($json, $hardware);
    } catch (InvalidArgumentException $exception) {
        return;
    }
    throw new RuntimeException($label . ': expected validation failure');
}

function assertInvalidPeriod($date, $startTime, $endTime, $label)
{
    global $assertions;
    $assertions++;
    try {
        validateBookingPeriod($date, $startTime, $endTime);
    } catch (InvalidArgumentException $exception) {
        return;
    }
    throw new RuntimeException($label . ': expected validation failure');
}

$valid = validateBookingHardwareJson('[{"id":"1","quantity":1}]', $hardware);
assertSameValue([['id' => 1, 'quantity' => 1]], $valid, 'Valid hardware item');
assertSameValue(
    [['id' => 2, 'quantity' => 2]],
    validateBookingHardwareJson('[{"id":2,"quantity":2}]', $hardware),
    'Two units accepted when configured inventory has two'
);
assertSameValue([], validateBookingHardwareJson('[]', $hardware), 'Empty hardware list');

assertInvalidPeriod('2026-10-20', '15:00', '14:00', 'End before start');
assertInvalidPeriod('2026-10-20', '14:00', '14:00', 'Start equals end');
assertSameValue(
    ['date' => '2026-10-20', 'start_time' => '14:00:00', 'end_time' => '15:00:00'],
    validateBookingPeriod('2026-10-20', '14:00', '15:00'),
    'Valid booking period'
);
foreach ([
    [null, '14:00', '15:00', 'Missing date'],
    ['2026-02-30', '14:00', '15:00', 'Invalid calendar date'],
    ['2026-10-20', null, '15:00', 'Missing start time'],
    ['2026-10-20', '14:00', null, 'Missing end time'],
    ['2026-10-20', '14:0', '15:00', 'Malformed start time'],
    ['2026-10-20', '14:00', '25:00', 'Out-of-range end time'],
] as [$date, $start, $end, $label]) {
    assertInvalidPeriod($date, $start, $end, $label);
}

foreach ([
    [null, 'Missing JSON'],
    ['{bad', 'Malformed JSON'],
    ['{}', 'Unexpected top-level structure'],
    ['[1]', 'Unexpected item structure'],
    ['[{"id":1,"quantity":0}]', 'Zero quantity'],
    ['[{"id":1,"quantity":-1}]', 'Negative quantity'],
    ['[{"id":1,"quantity":-100}]', 'Large negative quantity'],
    ['[{"id":1,"quantity":1.5}]', 'Fractional quantity'],
    ['[{"id":1,"quantity":0.5}]', 'Positive fractional quantity'],
    ['[{"id":1,"quantity":-0.5}]', 'Negative fractional quantity'],
    ['[{"id":1,"quantity":true}]', 'Boolean quantity'],
    ['[{"id":1,"quantity":""}]', 'Empty quantity'],
    ['[{"id":1,"quantity":"-1"}]', 'Negative numeric string'],
    ['[{"id":1,"quantity":"0"}]', 'Zero numeric string'],
    ['[{"id":1,"quantity":"1.5"}]', 'Fractional numeric string'],
    ['[{"id":1,"quantity":"1x"}]', 'Invalid numeric string'],
    ['[{"id":1,"quantity":99999999999999999999}]', 'Overflow quantity'],
    ['[{"id":99,"quantity":1}]', 'Unknown hardware ID'],
    ['[{"id":1,"quantity":1},{"id":"1","quantity":1}]', 'Duplicate hardware ID'],
    ['[{"id":1,"quantity":2}]', 'Quantity above configured stock'],
    ['[{"id":1,"quantity":1,"extra":true}]', 'Unexpected item property'],
] as [$json, $label]) {
    assertInvalidHardware($json, $hardware, $label);
}

$overlappingBookings = [
    ['booking_date' => '2026-10-20', 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'location_id' => 2, 'hardware_ids' => '[{"id":1,"quantity":1}]'],
];
$request = [['id' => 1, 'quantity' => 1]];
assertSameValue(
    false,
    getBookingHardwareAvailabilityError($request, $overlappingBookings, '2026-10-20', '10:30:00', '11:30:00', $hardware) === null,
    'Overlapping booking at another location reduces shared stock'
);
assertSameValue(
    null,
    getBookingHardwareAvailabilityError($request, $overlappingBookings, '2026-10-20', '11:00:00', '12:00:00', $hardware),
    'Adjacent period does not overlap'
);
assertSameValue(
    null,
    getBookingHardwareAvailabilityError($request, $overlappingBookings, '2026-10-21', '10:30:00', '11:30:00', $hardware),
    'Different date does not overlap'
);

$multipleLocations = [
    ['booking_date' => '2026-10-20', 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'location_id' => 2, 'hardware_ids' => '[{"id":2,"quantity":1}]'],
    ['booking_date' => '2026-10-20', 'start_time' => '10:15:00', 'end_time' => '10:45:00', 'location_id' => 999, 'hardware_ids' => '[{"id":2,"quantity":1}]'],
];
assertSameValue(
    false,
    getBookingHardwareAvailabilityError([['id' => 2, 'quantity' => 1]], $multipleLocations, '2026-10-20', '10:30:00', '10:40:00', $hardware) === null,
    'Reservations from all locations are summed'
);

echo "OK ({$assertions} assertions)\n";