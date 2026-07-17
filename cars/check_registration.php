<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::check();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = Database::getInstance();
$businessId = Auth::user('business_id');
$registrationNo = normalizeRegistrationNo(get('registration_no', ''));

if (!isValidRegistrationNo($registrationNo)) {
    http_response_code(422);
    echo json_encode([
        'available' => false,
        'valid' => false,
        'registration_no' => $registrationNo,
        'message' => 'Enter a complete registration number like GJ05AA0001.',
    ]);
    exit;
}

$car = findCarByRegistrationNo($db, $businessId, $registrationNo);

if (!$car) {
    echo json_encode([
        'available' => true,
        'valid' => true,
        'registration_no' => $registrationNo,
        'message' => 'Registration number is available.',
    ]);
    exit;
}

$status = ucwords(strtolower(str_replace('_', ' ', (string) ($car['status'] ?? ''))));
$ownership = strtoupper((string) ($car['ownership_type'] ?? 'OWNED')) === 'COMMISSION' ? 'Commission car' : 'Business car';
$vehicle = trim((string) ($car['make'] ?? '') . ' ' . (string) ($car['model'] ?? ''));

echo json_encode([
    'available' => false,
    'valid' => true,
    'registration_no' => $registrationNo,
    'message' => 'Duplicate registration number. This car is already in the system.',
    'car' => [
        'id' => $car['id'],
        'registration_no' => $car['registration_no'],
        'vehicle' => $vehicle,
        'status' => $status,
        'ownership' => $ownership,
        'url' => APP_URL . (strtoupper((string) ($car['ownership_type'] ?? 'OWNED')) === 'COMMISSION'
            ? 'cars/commission_view.php?id=' . rawurlencode($car['id'])
            : 'cars/view.php?id=' . rawurlencode($car['id'])),
    ],
]);
