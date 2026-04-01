<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$conn = db();
$apartmentId = isset($_GET['apartment_id']) ? (int)$_GET['apartment_id'] : 0;
$params = [];
$types = '';
$events = [];

$sqlBookings = 'SELECT b.*, a.name apartment_name, a.color apartment_color FROM bookings b INNER JOIN apartments a ON a.id = b.apartment_id WHERE b.status IN ("confirmada", "hospedado", "finalizada")';
if ($apartmentId > 0) {
    $sqlBookings .= ' AND b.apartment_id = ?';
    $params[] = $apartmentId;
    $types .= 'i';
}

$stmt = $conn->prepare($sqlBookings);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $baseColor = $row['apartment_color'] ?: '#0ea5e9';
    if ($row['status'] === 'confirmada') {
        $bg = $baseColor;
    } elseif ($row['status'] === 'hospedado') {
        $bg = '#10b981';
    } elseif ($row['status'] === 'finalizada') {
        $bg = '#3b82f6';
    } else {
        $bg = '#94a3b8';
    }

    $events[] = [
        'id' => 'booking-' . (int)$row['id'],
        'title' => $row['apartment_name'] . ' • ' . $row['guest_name'],
        'start' => $row['checkin_datetime'],
        'end' => $row['checkout_datetime'],
        'backgroundColor' => $bg,
        'borderColor' => $bg,
        'textColor' => '#ffffff',
        'extendedProps' => [
            'event_kind' => 'booking',
            'booking_id' => (int)$row['id'],
            'apartment_id' => (int)$row['apartment_id'],
            'apartment_name' => $row['apartment_name'],
            'guest_name' => $row['guest_name'],
            'guest_phone' => $row['guest_phone'],
            'guest_document' => $row['guest_document'],
            'checkin_datetime' => $row['checkin_datetime'],
            'checkout_datetime' => $row['checkout_datetime'],
            'daily_rate' => $row['daily_rate'],
            'total_amount' => $row['total_amount'],
            'status' => $row['status'],
            'notes' => $row['notes'],
            'apartment_color' => $row['apartment_color'],
        ]
    ];
}

echo json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
