<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();

$appConfig = require __DIR__ . '/config.php';
$bookingValueAutoEnabled = array_key_exists('booking_value_auto_enabled', $appConfig) ? (bool)$appConfig['booking_value_auto_enabled'] : true;

function bookingFormPostedPhp(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (($_POST['form_type'] ?? 'booking') === 'booking');
}

function bookingFieldValuePhp(array $booking, string $postKey, $bookingValue = null, $default = '') {
    if (bookingFormPostedPhp() && array_key_exists($postKey, $_POST)) {
        return $_POST[$postKey];
    }
    if ($bookingValue !== null) {
        return $bookingValue;
    }
    return $default;
}

function bookingDateValuePhp(array $booking, string $postKey, string $bookingDateTimeKey, string $default = ''): string {
    if (bookingFormPostedPhp() && array_key_exists($postKey, $_POST)) {
        return (string)$_POST[$postKey];
    }
    if (!empty($booking[$bookingDateTimeKey])) {
        return date('Y-m-d', strtotime((string)$booking[$bookingDateTimeKey]));
    }
    return $default;
}

function bookingTimeValuePhp(array $booking, string $postKey, string $bookingDateTimeKey, string $default = ''): string {
    if (bookingFormPostedPhp() && array_key_exists($postKey, $_POST)) {
        return (string)$_POST[$postKey];
    }
    if (!empty($booking[$bookingDateTimeKey])) {
        return date('H:i', strtotime((string)$booking[$bookingDateTimeKey]));
    }
    return $default;
}

function bookingUseManualRatePhp(array $booking): int {
    if (bookingFormPostedPhp()) {
        $posted = $_POST['pricing_mode'] ?? $_POST['use_manual_rate'] ?? '0';
        return (string)$posted === '1' ? 1 : 0;
    }
    return ((string)($booking['use_manual_rate'] ?? '0') === '1') ? 1 : 0;
}

function findClientByPhone(mysqli $conn, string $phone, int $ignoreId = 0): ?array {
    $phone = normalizePhoneDigitsPhp($phone);
    if ($phone === '') {
        return null;
    }

    if ($ignoreId > 0) {
        $stmt = $conn->prepare('SELECT * FROM clients WHERE phone = ? AND id <> ? LIMIT 1');
        $stmt->bind_param('si', $phone, $ignoreId);
    } else {
        $stmt = $conn->prepare('SELECT * FROM clients WHERE phone = ? LIMIT 1');
        $stmt->bind_param('s', $phone);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function findClientByDocument(mysqli $conn, ?string $document, int $ignoreId = 0): ?array {
    $document = normalizeDocumentPhp($document);
    if ($document === null) {
        return null;
    }

    if ($ignoreId > 0) {
        $stmt = $conn->prepare('SELECT * FROM clients WHERE document = ? AND id <> ? LIMIT 1');
        $stmt->bind_param('si', $document, $ignoreId);
    } else {
        $stmt = $conn->prepare('SELECT * FROM clients WHERE document = ? LIMIT 1');
        $stmt->bind_param('s', $document);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function findClientByNamePhone(mysqli $conn, string $name, string $phone): ?array {
    $phoneDigits = normalizePhoneDigitsPhp($phone);
    $normalizedName = normalizePersonNamePhp($name);

    if ($phoneDigits !== '') {
        $stmt = $conn->prepare("SELECT * FROM clients WHERE phone = ? OR whatsapp = ? LIMIT 1");
        $stmt->bind_param('ss', $phoneDigits, $phoneDigits);
        $stmt->execute();
        $match = $stmt->get_result()->fetch_assoc();
        if ($match) {
            return $match;
        }
    }

    if ($normalizedName !== '') {
        $stmt = $conn->prepare("SELECT * FROM clients WHERE TRIM(LOWER(full_name)) = ? LIMIT 1");
        $stmt->bind_param('s', $normalizedName);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    return null;
}


function bookingBasedClientStatusPhp(int $bookingsCount): string {
    if ($bookingsCount >= 10) {
        return 'vip';
    }

    if ($bookingsCount >= 3) {
        return 'frequente';
    }

    return 'novo';
}

function syncClientStatusPhp(mysqli $conn, int $clientId): void {
    if ($clientId <= 0) {
        return;
    }

    $stmt = $conn->prepare('SELECT status FROM clients WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    if (!$client) {
        return;
    }

    if (($client['status'] ?? '') === 'bloqueado') {
        return;
    }

    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM bookings WHERE client_id = ?');
    $countStmt->bind_param('i', $clientId);
    $countStmt->execute();
    $bookingsCount = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $status = bookingBasedClientStatusPhp($bookingsCount);

    $updateStmt = $conn->prepare('UPDATE clients SET status = ? WHERE id = ?');
    $updateStmt->bind_param('si', $status, $clientId);
    $updateStmt->execute();
}

function resolveAutomaticBookingStatusPhp(string $checkin, string $checkout, ?string $currentStatus = null): string {
    $currentStatus = (string)($currentStatus ?? '');
    if ($currentStatus === 'cancelada') {
        return 'cancelada';
    }

    $now = time();
    $checkinTs = strtotime($checkin);
    $checkoutTs = strtotime($checkout);

    if ($checkinTs === false || $checkoutTs === false) {
        return 'confirmada';
    }

    if ($now >= $checkoutTs) {
        return 'finalizada';
    }

    if ($now >= $checkinTs && $now < $checkoutTs) {
        return 'hospedado';
    }

    return 'confirmada';
}

function syncAllBookingStatusesPhp(mysqli $conn): void {
    $result = $conn->query("SELECT id, checkin_datetime, checkout_datetime, status FROM bookings");
    if (!$result) {
        return;
    }

    $stmt = $conn->prepare('UPDATE bookings SET status = ? WHERE id = ?');
    while ($row = $result->fetch_assoc()) {
        $currentStatus = (string)($row['status'] ?? '');
        if ($currentStatus === 'cancelada') {
            continue;
        }

        $newStatus = resolveAutomaticBookingStatusPhp(
            (string)($row['checkin_datetime'] ?? ''),
            (string)($row['checkout_datetime'] ?? ''),
            $currentStatus
        );

        if ($newStatus !== $currentStatus) {
            $bookingId = (int)($row['id'] ?? 0);
            $stmt->bind_param('si', $newStatus, $bookingId);
            $stmt->execute();
        }
    }
}

function getBrazilHolidaysPhp(int $year): array {
    $easter = easter_date($year);
    $easterDate = new DateTime('@' . $easter);
    $easterDate->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $format = fn(DateTime $d) => $d->format('Y-m-d');
    $addDays = function(DateTime $date, int $days): DateTime {
        $d = clone $date;
        $d->modify(($days >= 0 ? '+' : '') . $days . ' days');
        return $d;
    };
    return [
        ['date' => sprintf('%04d-01-01', $year), 'name' => 'Confraternização Universal'],
        ['date' => $format($addDays($easterDate, -48)), 'name' => 'Carnaval'],
        ['date' => $format($addDays($easterDate, -47)), 'name' => 'Carnaval'],
        ['date' => $format($addDays($easterDate, -2)), 'name' => 'Sexta-feira Santa'],
        ['date' => $format($easterDate), 'name' => 'Páscoa'],
        ['date' => sprintf('%04d-04-21', $year), 'name' => 'Tiradentes'],
        ['date' => sprintf('%04d-05-01', $year), 'name' => 'Dia do Trabalhador'],
        ['date' => $format($addDays($easterDate, 60)), 'name' => 'Corpus Christi'],
        ['date' => sprintf('%04d-09-07', $year), 'name' => 'Independência do Brasil'],
        ['date' => sprintf('%04d-10-12', $year), 'name' => 'Nossa Senhora Aparecida'],
        ['date' => sprintf('%04d-11-02', $year), 'name' => 'Finados'],
        ['date' => sprintf('%04d-11-15', $year), 'name' => 'Proclamação da República'],
        ['date' => sprintf('%04d-11-20', $year), 'name' => 'Dia da Consciência Negra'],
        ['date' => sprintf('%04d-12-25', $year), 'name' => 'Natal'],
    ];
}

function getChargeableBookingDateRangePhp(string $checkin, string $checkout): array {
    $checkin = trim($checkin);
    $checkout = trim($checkout);
    if ($checkin === '' || $checkout === '') return [];

    $startDate = substr($checkin, 0, 10);
    $endDate = substr($checkout, 0, 10);
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
    if (!$start || !$end) return [];

    $checkinDateTime = new DateTimeImmutable($checkin);
    $checkoutDateTime = new DateTimeImmutable($checkout);
    if ($checkoutDateTime <= $checkinDateTime) return [];

    $chargeUntil = $end;
    if ($end > $start) {
        $chargeUntil = $end->modify('-1 day');
    }

    if ($chargeUntil < $start) {
        return [$start];
    }

    $items = [];
    $cursor = $start;
    while ($cursor <= $chargeUntil) {
        $items[] = $cursor;
        $cursor = $cursor->modify('+1 day');
    }
    return $items;
}

function calculateBookingPricingPhp(string $checkin, string $checkout, array $rates): array {
    $dateRange = getChargeableBookingDateRangePhp($checkin, $checkout);
    if (empty($dateRange)) return ['items' => [], 'total' => 0.0, 'daily_rate' => 0.0, 'charged_days' => 0];

    $items = [];
    $total = 0.0;

    foreach ($dateRange as $cursor) {
        $item = calculateAutomaticRateItemPhp(DateTime::createFromImmutable($cursor), $rates);
        $items[] = $item;
        $total += (float)($item['rate'] ?? 0);
    }

    return [
        'items' => $items,
        'total' => round($total, 2),
        'daily_rate' => $items[0]['rate'] ?? 0.0,
        'charged_days' => count($items),
    ];
}

function calculateAutomaticRateItemPhp(DateTime $cursor, array $rates): array {
    $year = (int)$cursor->format('Y');
    $dateKey = $cursor->format('Y-m-d');
    $holiday = null;
    foreach (getBrazilHolidaysPhp($year) as $h) {
        if ($h['date'] === $dateKey) {
            $holiday = $h;
            break;
        }
    }

    $dow = (int)$cursor->format('w');
    $rate = (float)($rates['weekday'] ?? 0);
    $type = 'semanal';
    $source = 'auto';
    $detail = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'][$dow] ?? '';

    if ($holiday) {
        $rate = (float)($rates['holiday'] ?? $rates['weekend'] ?? $rates['weekday'] ?? 0);
        $type = 'feriado';
        $detail = $holiday['name'] ?? 'Feriado';
    } elseif ($dow === 0 || $dow === 6) {
        $rate = (float)($rates['weekend'] ?? $rates['weekday'] ?? 0);
        $type = 'final de semana';
        $detail = $dow === 0 ? 'Domingo' : 'Sábado';
    }

    return [
        'label' => $cursor->format('d/m'),
        'date' => $dateKey,
        'type' => $type,
        'rate' => round($rate, 2),
        'detail' => $detail,
        'source' => $source,
    ];
}

function normalizeRateDateKeyPhp($value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    return substr($value, 0, 10);
}

function calculateBookingPricingFromAgendaPhp(string $checkin, string $checkout, array $rates, array $manualRatesMap): array {
    $dateRange = getChargeableBookingDateRangePhp($checkin, $checkout);
    if (empty($dateRange)) return ['items' => [], 'total' => 0.0, 'daily_rate' => 0.0, 'manual_days' => 0, 'missing_manual_days' => 0, 'charged_days' => 0];

    $items = [];
    $total = 0.0;
    $manualDays = 0;
    $missingManualDays = 0;

    foreach ($dateRange as $cursor) {
        $dateKey = $cursor->format('Y-m-d');
        $manualEntry = $manualRatesMap[$dateKey] ?? null;
        $manualValue = 0.0;
        $manualSource = 'manual';
        $manualDetail = 'Agenda manual';

        if (is_array($manualEntry)) {
            $manualValue = round((float)($manualEntry['daily_rate'] ?? 0), 2);
            $manualSource = (string)($manualEntry['source'] ?? 'manual');
            $manualDetail = (string)($manualEntry['detail'] ?? 'Agenda manual');
        } elseif ($manualEntry !== null) {
            $manualValue = round((float)$manualEntry, 2);
        }

        if ($manualValue > 0) {
            $items[] = [
                'label' => $cursor->format('d/m'),
                'date' => $dateKey,
                'type' => 'manual',
                'rate' => $manualValue,
                'detail' => $manualDetail,
                'source' => $manualSource,
            ];
            $total += $manualValue;
            $manualDays++;
        } else {
            $items[] = [
                'label' => $cursor->format('d/m'),
                'date' => $dateKey,
                'type' => 'manual_missing',
                'rate' => 0.0,
                'detail' => 'Sem valor cadastrado na agenda manual',
                'source' => 'manual_missing',
            ];
            $missingManualDays++;
        }
    }

    return [
        'items' => $items,
        'total' => round($total, 2),
        'daily_rate' => 0.0,
        'manual_days' => $manualDays,
        'missing_manual_days' => $missingManualDays,
        'charged_days' => count($items),
    ];
}

$conn = db();
$message = '';
$error = '';
$manualAppliedMessage = '';
$editingId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$pageAction = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? '')));
$incomingFormType = strtolower(trim((string)($_POST['form_type'] ?? '')));
$showBookingForm = $pageAction === 'new' || $editingId > 0 || $incomingFormType === 'booking';
$showBlockForm = false;
$showHistory = isset($_GET['history']) && (int)$_GET['history'] === 1;
$booking = [];

if ($editingId > 0) {
    $stmt = $conn->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $editingId);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc() ?: [];
}


$prefillStart = $_GET['start'] ?? '';
$prefillApartmentId = isset($_GET['apartment_id']) ? (int)$_GET['apartment_id'] : 0;
if (empty($booking) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefillStart)) {
    $booking = [
        'apartment_id' => $prefillApartmentId,
        'checkin_datetime' => $prefillStart . ' 14:00:00',
        'checkout_datetime' => date('Y-m-d 12:00:00', strtotime($prefillStart . ' +1 day')),
        'status' => 'confirmada'
    ];
    $showBookingForm = true;
} elseif ($showBookingForm && empty($booking)) {
    $today = date('Y-m-d');
    $booking = [
        'apartment_id' => $prefillApartmentId,
        'checkin_datetime' => $today . ' 14:00:00',
        'checkout_datetime' => date('Y-m-d 12:00:00', strtotime($today . ' +1 day')),
        'status' => 'confirmada'
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['post_action'])) {
    verify_csrf_or_fail();

    $id = (int)($_POST['id'] ?? 0);
    $apartmentId = (int)($_POST['apartment_id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0);
    $guestName = trim($_POST['guest_name'] ?? '');
    $guestPhone = normalizePhoneDigitsPhp($_POST['guest_phone'] ?? '');
    $guestDocument = normalizeDocumentPhp($_POST['guest_document'] ?? '');
    $guestWhatsapp = normalizePhoneDigitsPhp($_POST['guest_whatsapp'] ?? '');
    $guestEmail = trim($_POST['guest_email'] ?? '');
    $guestBirthDate = trim($_POST['guest_birth_date'] ?? '');
    $guestAddress = trim($_POST['guest_address'] ?? '');
    $guestCity = trim($_POST['guest_city'] ?? '');
    $clientNotes = trim($_POST['client_notes'] ?? '');
    $checkinDate = $_POST['checkin_date'] ?? '';
    $checkinTime = $_POST['checkin_time'] ?? '';
    $checkoutDate = $_POST['checkout_date'] ?? '';
    $checkoutTime = $_POST['checkout_time'] ?? '';
    $entryAmount = parseMoneyValuePhp($_POST['entry_amount'] ?? '0');
    $notes = trim($_POST['notes'] ?? '');
    $statusInput = trim((string)($_POST['status'] ?? 'automatico'));
    $status = 'confirmada';

    $checkin = $checkinDate . ' ' . $checkinTime . ':00';
    $checkout = $checkoutDate . ' ' . $checkoutTime . ':00';

    if (!$apartmentId || $guestName === '' || $guestPhone === '' || !$checkinDate || !$checkinTime || !$checkoutDate || !$checkoutTime) {
        $error = 'Preencha os campos obrigatórios da reserva e do cliente.';
        $showBookingForm = true;
    } elseif (strtotime($checkout) <= strtotime($checkin)) {
        $error = 'O check-out deve ser posterior ao check-in.';
        $showBookingForm = true;
    } else {
        if ($clientId <= 0) {
            $existingClient = findClientByPhone($conn, $guestPhone);
            if (!$existingClient && $guestDocument !== null) {
                $existingClient = findClientByDocument($conn, $guestDocument);
            }
            if (!$existingClient) {
                $existingClient = findClientByNamePhone($conn, $guestName, $guestPhone);
            }
            if ($existingClient) {
                $clientId = (int)$existingClient['id'];
            }
        }

        if ($clientId > 0) {
            $duplicatePhoneClient = findClientByPhone($conn, $guestPhone, $clientId);
            $duplicateDocumentClient = $guestDocument !== null ? findClientByDocument($conn, $guestDocument, $clientId) : null;

            if ($duplicatePhoneClient) {
                $clientId = (int)$duplicatePhoneClient['id'];
            } elseif ($duplicateDocumentClient) {
                $clientId = (int)$duplicateDocumentClient['id'];
            }

            $stmt = $conn->prepare('SELECT status FROM clients WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $clientId);
            $stmt->execute();
            $clientStatusRow = $stmt->get_result()->fetch_assoc() ?: [];
            $currentClientStatus = (string)($clientStatusRow['status'] ?? 'novo');

            if ($currentClientStatus === 'bloqueado' && $id <= 0) {
                $error = 'Este cliente está bloqueado e não pode fazer novas reservas.';
                $showBookingForm = true;
            } else {
                $stmt = $conn->prepare('UPDATE clients SET full_name = ?, phone = ?, whatsapp = ?, document = ?, email = ?, birth_date = ?, address = ?, city = ?, notes = ? WHERE id = ?');
                $birthDateParam = $guestBirthDate !== '' ? $guestBirthDate : null;
                $stmt->bind_param('sssssssssi', $guestName, $guestPhone, $guestWhatsapp, $guestDocument, $guestEmail, $birthDateParam, $guestAddress, $guestCity, $clientNotes, $clientId);
                $stmt->execute();
                if ($stmt->errno === 1062) {
                    $error = 'Já existe um cliente com este telefone ou CPF/RG.';
                    $showBookingForm = true;
                }
            }
        } else {
            $initialClientStatus = 'novo';
            $stmt = $conn->prepare('INSERT INTO clients (full_name, phone, whatsapp, document, email, birth_date, address, city, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $birthDateParam = $guestBirthDate !== '' ? $guestBirthDate : null;
            $stmt->bind_param('ssssssssss', $guestName, $guestPhone, $guestWhatsapp, $guestDocument, $guestEmail, $birthDateParam, $guestAddress, $guestCity, $initialClientStatus, $clientNotes);
            $stmt->execute();
            if ($stmt->errno === 1062) {
                $fallbackClient = findClientByPhone($conn, $guestPhone);
                if (!$fallbackClient && $guestDocument !== null) {
                    $fallbackClient = findClientByDocument($conn, $guestDocument);
                }
                if ($fallbackClient) {
                    $clientId = (int)$fallbackClient['id'];
                } else {
                    $error = 'Já existe um cliente com este telefone ou CPF/RG.';
                    $showBookingForm = true;
                }
            } else {
                $clientId = (int)$conn->insert_id;
            }
        }

        if ($error === '') {
            $status = ($id > 0 && $statusInput === 'cancelada')
                ? 'cancelada'
                : resolveAutomaticBookingStatusPhp($checkin, $checkout);

            $sqlConflict = 'SELECT id FROM bookings WHERE apartment_id = ? AND status IN ("confirmada", "hospedado") AND (? < checkout_datetime AND ? > checkin_datetime)';
            if ($id > 0) {
                $sqlConflict .= ' AND id <> ?';
                $stmt = $conn->prepare($sqlConflict);
                $stmt->bind_param('issi', $apartmentId, $checkin, $checkout, $id);
            } else {
                $stmt = $conn->prepare($sqlConflict);
                $stmt->bind_param('iss', $apartmentId, $checkin, $checkout);
            }
            $stmt->execute();
            $conflict = $stmt->get_result()->fetch_assoc();

            $rateStmt = $conn->prepare('SELECT weekday_daily_rate, weekend_daily_rate, holiday_daily_rate, default_daily_rate FROM apartments WHERE id = ? LIMIT 1');
            $rateStmt->bind_param('i', $apartmentId);
            $rateStmt->execute();
            $rateRow = $rateStmt->get_result()->fetch_assoc() ?: [];
            $rates = [
                'weekday' => normalizeDailyRateValuePhp($rateRow['weekday_daily_rate'] ?? $rateRow['default_daily_rate'] ?? 0),
                'weekend' => normalizeDailyRateValuePhp($rateRow['weekend_daily_rate'] ?? $rateRow['default_daily_rate'] ?? 0),
                'holiday' => normalizeDailyRateValuePhp($rateRow['holiday_daily_rate'] ?? $rateRow['weekend_daily_rate'] ?? $rateRow['default_daily_rate'] ?? 0),
            ];

            $pricing = calculateBookingPricingPhp($checkin, $checkout, $rates);
            $dailyRate = (float)($pricing['daily_rate'] ?? 0);
            $totalAmount = max((float)$pricing['total'] - $entryAmount, 0.0);

            if ($conflict) {
                $error = 'Este apartamento/quarto já está indisponível nesse período.';
                $showBookingForm = true;
            } else {
                if ($id > 0) {
                    $stmt = $conn->prepare('UPDATE bookings SET apartment_id = ?, client_id = ?, guest_name = ?, guest_phone = ?, guest_document = ?, checkin_datetime = ?, checkout_datetime = ?, daily_rate = ?, entry_amount = ?, total_amount = ?, status = ?, notes = ? WHERE id = ?');
                    $stmt->bind_param('iisssssddsssi', $apartmentId, $clientId, $guestName, $guestPhone, $guestDocument, $checkin, $checkout, $dailyRate, $entryAmount, $totalAmount, $status, $notes, $id);
                    $stmt->execute();
                    $editingId = $id;
                    $message = 'Reserva atualizada com sucesso.';
                } else {
                    $stmt = $conn->prepare('INSERT INTO bookings (apartment_id, client_id, guest_name, guest_phone, guest_document, checkin_datetime, checkout_datetime, daily_rate, entry_amount, total_amount, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->bind_param('iisssssdddss', $apartmentId, $clientId, $guestName, $guestPhone, $guestDocument, $checkin, $checkout, $dailyRate, $entryAmount, $totalAmount, $status, $notes);
                    $stmt->execute();
                    $editingId = (int)$conn->insert_id;
                    $message = 'Reserva criada com sucesso.';
                }

                $stmt = $conn->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
                $stmt->bind_param('i', $editingId);
                $stmt->execute();
                $booking = $stmt->get_result()->fetch_assoc() ?: [];
                $showBookingForm = true;
                syncClientStatusPhp($conn, $clientId);
            }
        }
    }
}

syncAllBookingStatusesPhp($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['post_action'])) {
    verify_csrf_or_fail();
    $postAction = $_POST['post_action'] ?? '';

    if ($postAction === 'delete_booking') {
        $id = (int)($_POST['booking_id'] ?? 0);
        $clientIdToSync = 0;
        $stmt = $conn->prepare('SELECT client_id FROM bookings WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $clientIdToSync = (int)($stmt->get_result()->fetch_assoc()['client_id'] ?? 0);

        $stmt = $conn->prepare('DELETE FROM bookings WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        syncClientStatusPhp($conn, $clientIdToSync);
        header('Location: bookings.php');
        exit;
    }
}

$apartments = $conn->query('SELECT * FROM apartments ORDER BY name ASC');
$clients = $conn->query('SELECT * FROM clients ORDER BY full_name ASC');
$clientRows = [];
while ($clientRow = $clients->fetch_assoc()) {
    $clientRows[] = $clientRow;
}
$bookingsQuery = 'SELECT b.*, a.name apartment_name, c.full_name client_name FROM bookings b INNER JOIN apartments a ON a.id = b.apartment_id LEFT JOIN clients c ON c.id = b.client_id';
if (!$showHistory) {
    $bookingsQuery .= ' WHERE b.checkout_datetime >= NOW()';
}
$bookingsQuery .= ' ORDER BY b.checkin_datetime DESC';
$bookings = $conn->query($bookingsQuery);


$manualRatesRows = [];
$manualRatesResult = $conn->query('SELECT id, apartment_id, rate_date, daily_rate FROM apartment_daily_rates ORDER BY rate_date ASC, id ASC');
if ($manualRatesResult) {
    while ($manualRow = $manualRatesResult->fetch_assoc()) {
        $manualRatesRows[] = [
            'id' => (int)$manualRow['id'],
            'apartment_id' => (int)$manualRow['apartment_id'],
            'rate_date' => $manualRow['rate_date'],
            'daily_rate' => normalizeDailyRateValuePhp($manualRow['daily_rate']),
        ];
    }
}

$selectedClient = null;
if ($booking && !empty($booking['client_id'])) {
    $stmt = $conn->prepare('SELECT * FROM clients WHERE id = ? LIMIT 1');
    $cid = (int)$booking['client_id'];
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $selectedClient = $stmt->get_result()->fetch_assoc();
}
if (!$selectedClient && $booking && (!empty($booking['guest_name']) || !empty($booking['guest_phone']))) {
    $selectedClient = findClientByNamePhone($conn, (string)($booking['guest_name'] ?? ''), (string)($booking['guest_phone'] ?? ''));
    if ($selectedClient && empty($booking['client_id']) && !empty($booking['id'])) {
        $stmt = $conn->prepare('UPDATE bookings SET client_id = ? WHERE id = ?');
        $clientIdAuto = (int)$selectedClient['id'];
        $bookingIdAuto = (int)$booking['id'];
        $stmt->bind_param('ii', $clientIdAuto, $bookingIdAuto);
        $stmt->execute();
        $booking['client_id'] = $clientIdAuto;
    }
}

$selectedClientIdValue = 0;
if (bookingFormPostedPhp() && array_key_exists('client_id', $_POST)) {
    $selectedClientIdValue = (int)($_POST['client_id'] ?? 0);
} elseif ($selectedClient && isset($selectedClient['id'])) {
    $selectedClientIdValue = (int)$selectedClient['id'];
} elseif ($booking && isset($booking['client_id'])) {
    $selectedClientIdValue = (int)$booking['client_id'];
}

include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 mb-4">
    <a class="btn <?= $showBookingForm ? 'btn-primary' : 'btn-outline-primary' ?>" href="bookings.php?action=new"><i class="bi bi-plus-lg"></i> Nova reserva</a>
    <a class="btn <?= !$showBookingForm ? 'btn-dark' : 'btn-outline-dark' ?>" href="bookings.php"><i class="bi bi-list-ul"></i> Ver reservas</a>
</div>

<div class="row g-4">
<?php if ($showBookingForm): ?>
    <div class="col-12 col-xl-8">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <h4 class="mb-0"><?= !empty($booking['id']) ? 'Editar reserva' : 'Nova reserva' ?></h4>
                    <span class="text-muted small">Modo lite com cálculo automático por diária, final de semana e feriado.</span>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="post" id="bookingForm">
                    <?php if (function_exists('csrf_input')): ?>
                        <?= csrf_input() ?>
                    <?php endif; ?>
                    <input type="hidden" name="id" value="<?= (int)($booking['id'] ?? 0) ?>">
                    <input type="hidden" id="client_id" name="client_id" value="<?= $selectedClientIdValue ?>">

                    <div class="mb-3">
                        <label class="form-label">Cliente identificado</label>
                        <div class="form-control bg-light d-flex align-items-center justify-content-between gap-2" id="client_match_box" style="min-height: 44px;">
                            <span id="client_match_text"><?= !empty($selectedClient['id']) ? 'Cliente encontrado: ' . htmlspecialchars($selectedClient['full_name']) . ' - ' . htmlspecialchars($selectedClient['phone']) : 'Novo cliente será cadastrado automaticamente se não existir.' ?></span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="clear_client_match">Limpar vínculo</button>
                        </div>
                        <div class="form-text">Digite nome e telefone. O sistema busca automaticamente no banco e preenche o restante.</div>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-7 mb-3">
                            <label class="form-label">Nome do cliente *</label>
                            <input type="text" id="guest_name" name="guest_name" list="client_name_suggestions" class="form-control" value="<?= htmlspecialchars((string)bookingFieldValuePhp($booking, 'guest_name', $selectedClient['full_name'] ?? $booking['guest_name'] ?? '', '')) ?>" required>
                        </div>
                        <div class="col-md-5 mb-3">
                            <label class="form-label">Telefone *</label>
                            <input type="text" id="guest_phone" name="guest_phone" list="client_phone_suggestions" class="form-control" value="<?= htmlspecialchars((string)bookingFieldValuePhp($booking, 'guest_phone', $selectedClient['phone'] ?? $booking['guest_phone'] ?? '', '')) ?>" required>
                        </div>
                    </div>

                    <datalist id="client_name_suggestions">
                        <?php foreach ($clientRows as $client): ?>
                            <option value="<?= htmlspecialchars($client['full_name']) ?>"><?= htmlspecialchars($client['phone']) ?></option>
                        <?php endforeach; ?>
                    </datalist>
                    <datalist id="client_phone_suggestions">
                        <?php foreach ($clientRows as $client): ?>
                            <option value="<?= htmlspecialchars($client['phone']) ?>"><?= htmlspecialchars($client['full_name']) ?></option>
                            <?php if (!empty($client['whatsapp'])): ?><option value="<?= htmlspecialchars($client['whatsapp']) ?>"><?= htmlspecialchars($client['full_name']) ?></option><?php endif; ?>
                        <?php endforeach; ?>
                    </datalist>

                    <div class="accordion mb-3" id="clientExtraAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#clientExtraFields">Dados opcionais do cliente</button>
                            </h2>
                            <div id="clientExtraFields" class="accordion-collapse collapse">
                                <div class="accordion-body">
                                    <div class="row g-2">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">WhatsApp</label>
                                            <input type="text" id="guest_whatsapp" name="guest_whatsapp" class="form-control" value="<?= htmlspecialchars((string)bookingFieldValuePhp($booking, 'guest_whatsapp', $selectedClient['whatsapp'] ?? '', '')) ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Documento</label>
                                            <input type="text" id="guest_document" name="guest_document" class="form-control" value="<?= htmlspecialchars((string)bookingFieldValuePhp($booking, 'guest_document', $selectedClient['document'] ?? $booking['guest_document'] ?? '', '')) ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">E-mail</label>
                                            <input type="email" id="guest_email" name="guest_email" class="form-control" value="<?= htmlspecialchars((string)bookingFieldValuePhp($booking, 'guest_email', $selectedClient['email'] ?? '', '')) ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Data de nascimento</label>
                                            <input type="date" id="guest_birth_date" name="guest_birth_date" class="form-control" value="<?= htmlspecialchars((string)bookingFieldValuePhp($booking, 'guest_birth_date', $selectedClient['birth_date'] ?? '', '')) ?>">
                                        </div>
                                        <div class="col-md-8 mb-3">
                                            <label class="form-label">Endereço</label>
                                            <input type="text" id="guest_address" name="guest_address" class="form-control" value="<?= htmlspecialchars((string)bookingFieldValuePhp($booking, 'guest_address', $selectedClient['address'] ?? '', '')) ?>">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Cidade</label>
                                            <input type="text" id="guest_city" name="guest_city" class="form-control" value="<?= htmlspecialchars((string)bookingFieldValuePhp($booking, 'guest_city', $selectedClient['city'] ?? '', '')) ?>">
                                        </div>
                                        <div class="col-12 mb-0">
                                            <label class="form-label">Observações do cliente</label>
                                            <textarea id="client_notes" name="client_notes" class="form-control" rows="3"><?= htmlspecialchars((string)bookingFieldValuePhp($booking, 'client_notes', $selectedClient['notes'] ?? '', '')) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Apartamento / quarto *</label>
                            <select class="form-select" id="apartmentSelect" name="apartment_id" required>
                                <option value="">Selecione</option>
                                <?php while ($apartment = $apartments->fetch_assoc()): ?>
                                    <?php
                                    $selectedApartmentId = (int)bookingFieldValuePhp($booking, 'apartment_id', (int)($booking['apartment_id'] ?? 0), 0);
                                    $apartmentId = (int)$apartment['id'];
                                    ?>
                                    <option value="<?= $apartmentId ?>"
                                            data-weekday-rate="<?= htmlspecialchars((string)normalizeDailyRateValuePhp($apartment['weekday_daily_rate'] ?? $apartment['default_daily_rate'] ?? 0)) ?>"
                                            data-weekend-rate="<?= htmlspecialchars((string)normalizeDailyRateValuePhp($apartment['weekend_daily_rate'] ?? $apartment['default_daily_rate'] ?? 0)) ?>"
                                            data-holiday-rate="<?= htmlspecialchars((string)normalizeDailyRateValuePhp($apartment['holiday_daily_rate'] ?? $apartment['weekend_daily_rate'] ?? $apartment['default_daily_rate'] ?? 0)) ?>"
                                            <?= $selectedApartmentId === $apartmentId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($apartment['name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <div class="form-text" id="rateHint">Selecione uma unidade para ver a diária semanal, a de final de semana e a de feriado.</div>
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-6 mb-3">
                            <label class="form-label">Data check-in *</label>
                            <input type="date" id="checkin_date" name="checkin_date" class="form-control" value="<?= htmlspecialchars(bookingDateValuePhp($booking, 'checkin_date', 'checkin_datetime')) ?>" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Hora check-in *</label>
                            <input type="time" id="checkin_time" name="checkin_time" class="form-control" value="<?= htmlspecialchars(bookingTimeValuePhp($booking, 'checkin_time', 'checkin_datetime', '14:00')) ?>" required>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-6 mb-3">
                            <label class="form-label">Data check-out *</label>
                            <input type="date" id="checkout_date" name="checkout_date" class="form-control" value="<?= htmlspecialchars(bookingDateValuePhp($booking, 'checkout_date', 'checkout_datetime')) ?>" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Hora check-out *</label>
                            <input type="time" id="checkout_time" name="checkout_time" class="form-control" value="<?= htmlspecialchars(bookingTimeValuePhp($booking, 'checkout_time', 'checkout_datetime', '12:00')) ?>" required>
                        </div>
                    </div>

                    <div class="info-strip mb-3">
                        <i class="bi bi-info-circle"></i>
                        <span>O valor total é calculado automaticamente pelas datas da reserva, considerando diária comum, final de semana e feriado. O último dia não é cobrado quando houver pernoite.</span>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Valor de entrada</label>
                            <input type="text" id="entry_amount" name="entry_amount" class="form-control" inputmode="decimal" value="<?= htmlspecialchars((string)bookingFieldValuePhp($booking, 'entry_amount', number_format(normalizeDailyRateValuePhp((float)($booking['entry_amount'] ?? 0)), 2, ',', '.'), '0,00')) ?>">
                            <div class="form-text">Esse valor será descontado do saldo total da reserva.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Valor total</label>
                            <input type="text" id="total_amount" name="total_amount" class="form-control" value="<?= htmlspecialchars((string)bookingFieldValuePhp($booking, 'total_amount', number_format(normalizeDailyRateValuePhp((float)($booking['total_amount'] ?? 0)), 2, ',', '.'), '0,00')) ?>" readonly>
                            <div class="form-text" id="holidayTotalNote">O valor total já considera o desconto da entrada.</div>
                        </div>
                    </div>

                    <div class="auto-price-summary mb-3" id="autoPriceSummary">Preencha as datas para ver o resumo visual das diárias.</div>

                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <?php $statusFieldValue = (string)bookingFieldValuePhp($booking, 'status', $booking['status'] ?? 'automatico', 'automatico'); ?>
                        <?php if (!empty($booking['id'])): ?>
                            <select name="status" class="form-select">
                                <option value="automatico" <?= $statusFieldValue !== 'cancelada' ? 'selected' : '' ?>>Automático</option>
                                <option value="cancelada" <?= $statusFieldValue === 'cancelada' ? 'selected' : '' ?>>Cancelada</option>
                            </select>
                            <div class="form-text">Confirmada, hospedado e finalizada são definidos automaticamente pelas datas. Na edição você só precisa marcar cancelada quando necessário.</div>
                        <?php else: ?>
                            <input type="hidden" name="status" value="automatico">
                            <input type="text" class="form-control" value="Automático" readonly>
                            <div class="form-text">Ao criar a reserva o sistema define o status sozinho pelas datas. Cancelamento só pode ser marcado na edição.</div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Observações</label>
                        <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars((string)bookingFieldValuePhp($booking, 'notes', $booking['notes'] ?? '', '')) ?></textarea>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <button class="btn btn-primary">Salvar reserva</button>
                        <a href="bookings.php" class="btn btn-outline-dark">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="col-12">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h4 class="mb-0"><?= $showHistory ? 'Reservas cadastradas (com histórico)' : 'Reservas cadastradas' ?></h4>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if ($showHistory): ?>
                            <a href="bookings.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-eye-slash"></i> Ocultar histórico</a>
                        <?php else: ?>
                            <a href="bookings.php?history=1" class="btn btn-outline-dark btn-sm"><i class="bi bi-clock-history"></i> Ver histórico</a>
                        <?php endif; ?>
                        <a href="clients.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-people"></i> Clientes</a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Telefone</th>
                                <th>Unidade</th>
                                <th>Período</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($bookings && $bookings->num_rows > 0): ?>
                                <?php while ($row = $bookings->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['guest_name']) ?></td>
                                        <td><?= htmlspecialchars($row['guest_phone']) ?></td>
                                        <td><?= htmlspecialchars($row['apartment_name']) ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($row['checkin_datetime'])) ?><br><small class="text-muted">até <?= date('d/m/Y H:i', strtotime($row['checkout_datetime'])) ?></small></td>
                                        <td>R$ <?= moneyBr((float)$row['total_amount']) ?></td>
                                        <td><span class="badge text-bg-secondary"><?= htmlspecialchars($row['status']) ?></span></td>
                                        <td class="text-end">
                                            <div class="dropdown row-actions">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Ações</button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><a href="bookings.php?edit=<?= (int)$row['id'] ?>" class="dropdown-item">Editar</a></li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <form method="post" class="px-2 pb-2" onsubmit="return confirm('Excluir esta reserva?')">
                                                            <?= csrf_input() ?>
                                                            <input type="hidden" name="post_action" value="delete_booking">
                                                            <input type="hidden" name="booking_id" value="<?= (int)$row['id'] ?>">
                                                            <button type="submit" class="dropdown-item text-danger rounded">Excluir</button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4"><?= $showHistory ? "Nenhuma reserva cadastrada." : "Nenhuma reserva ativa ou futura cadastrada." ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const clientIdInput = document.getElementById('client_id');
    const nameInput = document.getElementById('guest_name');
    const phoneInput = document.getElementById('guest_phone');
    const matchText = document.getElementById('client_match_text');
    const clearBtn = document.getElementById('clear_client_match');
    const apartmentSelect = document.getElementById('apartmentSelect');
    const checkinDate = document.getElementById('checkin_date');
    const checkoutDate = document.getElementById('checkout_date');
    const checkinTime = document.getElementById('checkin_time');
    const checkoutTime = document.getElementById('checkout_time');
    const entryAmountInput = document.getElementById('entry_amount');
    const totalAmountInput = document.getElementById('total_amount');
    const autoPriceSummary = document.getElementById('autoPriceSummary');
    const holidayTotalNote = document.getElementById('holidayTotalNote');
    const rateHint = document.getElementById('rateHint');

    const clients = <?= json_encode(array_map(function ($client) {
        return [
            'id' => (int)$client['id'],
            'name' => $client['full_name'] ?? '',
            'phone' => $client['phone'] ?? '',
            'whatsapp' => $client['whatsapp'] ?? '',
            'document' => $client['document'] ?? '',
            'email' => $client['email'] ?? '',
            'birth_date' => $client['birth_date'] ?? '',
            'address' => $client['address'] ?? '',
            'city' => $client['city'] ?? '',
            'status' => $client['status'] ?? 'novo',
            'notes' => $client['notes'] ?? '',
        ];
    }, $clientRows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const fieldMap = {
        guest_name: 'name',
        guest_phone: 'phone',
        guest_whatsapp: 'whatsapp',
        guest_document: 'document',
        guest_email: 'email',
        guest_birth_date: 'birth_date',
        guest_address: 'address',
        guest_city: 'city',
        client_notes: 'notes'
    };

    function normalizeName(value) {
        return (value || '').toString().trim().toLowerCase().replace(/\s+/g, ' ');
    }

    function normalizePhone(value) {
        return (value || '').toString().replace(/\D+/g, '');
    }

    function fillClientFields(client, keepTypedPrimary = true) {
        if (!clientIdInput) return;
        clientIdInput.value = client ? String(client.id) : '0';
        if (!client) {
            if (matchText) matchText.textContent = 'Novo cliente será cadastrado automaticamente se não existir.';
            return;
        }
        Object.keys(fieldMap).forEach(function (fieldId) {
            const input = document.getElementById(fieldId);
            if (!input) return;
            if (keepTypedPrimary && (fieldId === 'guest_name' || fieldId === 'guest_phone')) return;
            input.value = client[fieldMap[fieldId]] || '';
        });
        if (matchText) {
            matchText.textContent = 'Cliente encontrado: ' + client.name + ' - ' + client.phone + (client.status === 'bloqueado' ? ' (bloqueado)' : '');
        }
    }

    function findClient() {
        if (!clientIdInput || !nameInput || !phoneInput) return;
        const typedName = normalizeName(nameInput.value);
        const typedPhone = normalizePhone(phoneInput.value);
        let client = null;

        if (typedPhone) {
            client = clients.find(c => typedPhone === normalizePhone(c.phone) || typedPhone === normalizePhone(c.whatsapp));
        }
        if (!client && typedName && typedPhone) {
            client = clients.find(c => normalizeName(c.name) === typedName && (typedPhone === normalizePhone(c.phone) || typedPhone === normalizePhone(c.whatsapp)));
        }
        if (!client && typedName) {
            client = clients.find(c => normalizeName(c.name) === typedName);
        }

        fillClientFields(client, true);
    }

    function formatMoneyBr(value) {
        const number = Number(value || 0);
        return number.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function parseMoneyBr(value) {
        const normalized = String(value || '').replace(/[^\d,.-]/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
        const number = Number(normalized);
        return Number.isFinite(number) ? number : 0;
    }

    function getBrazilHolidays(year) {
        const fixed = {
            [`${year}-01-01`]: 'Confraternização Universal',
            [`${year}-04-21`]: 'Tiradentes',
            [`${year}-05-01`]: 'Dia do Trabalhador',
            [`${year}-09-07`]: 'Independência do Brasil',
            [`${year}-10-12`]: 'Nossa Senhora Aparecida',
            [`${year}-11-02`]: 'Finados',
            [`${year}-11-15`]: 'Proclamação da República',
            [`${year}-11-20`]: 'Dia da Consciência Negra',
            [`${year}-12-25`]: 'Natal'
        };

        function easterDate(y) {
            const a = y % 19;
            const b = Math.floor(y / 100);
            const c = y % 100;
            const d = Math.floor(b / 4);
            const e = b % 4;
            const f = Math.floor((b + 8) / 25);
            const g = Math.floor((b - f + 1) / 3);
            const h = (19 * a + b - d - g + 15) % 30;
            const i = Math.floor(c / 4);
            const k = c % 4;
            const l = (32 + 2 * e + 2 * i - h - k) % 7;
            const m = Math.floor((a + 11 * h + 22 * l) / 451);
            const month = Math.floor((h + l - 7 * m + 114) / 31);
            const day = ((h + l - 7 * m + 114) % 31) + 1;
            return new Date(Date.UTC(y, month - 1, day));
        }

        const easter = easterDate(year);
        function addDays(base, days) {
            const d = new Date(base.getTime());
            d.setUTCDate(d.getUTCDate() + days);
            return d;
        }
        function key(date) {
            return date.toISOString().slice(0, 10);
        }

        fixed[key(addDays(easter, -48))] = 'Carnaval';
        fixed[key(addDays(easter, -47))] = 'Carnaval';
        fixed[key(addDays(easter, -2))] = 'Sexta-feira Santa';
        fixed[key(easter)] = 'Páscoa';
        fixed[key(addDays(easter, 60))] = 'Corpus Christi';

        return fixed;
    }

    function parseDateOnly(value) {
        if (!value) return null;
        const parts = value.split('-').map(Number);
        if (parts.length !== 3) return null;
        return new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
    }

    function cloneDate(date) {
        return new Date(date.getTime());
    }

    function getChargeableDateRange(start, end, chargeSameDay) {
        const dates = [];
        const cursor = cloneDate(start);
        let chargeUntil = cloneDate(end);
        if (!chargeSameDay) {
            chargeUntil.setUTCDate(chargeUntil.getUTCDate() - 1);
        }
        while (cursor.getTime() <= chargeUntil.getTime()) {
            dates.push(cloneDate(cursor));
            cursor.setUTCDate(cursor.getUTCDate() + 1);
        }
        return dates;
    }

    function refreshRateHint() {
        if (!rateHint || !apartmentSelect) return;
        const option = apartmentSelect.options[apartmentSelect.selectedIndex];
        if (!option || !option.value) {
            rateHint.textContent = 'Selecione uma unidade para ver a diária semanal, a de final de semana e a de feriado.';
            return;
        }
        rateHint.textContent = 'Semanal: R$ ' + formatMoneyBr(option.dataset.weekdayRate || 0)
            + ' • Final de semana: R$ ' + formatMoneyBr(option.dataset.weekendRate || 0)
            + ' • Feriado: R$ ' + formatMoneyBr(option.dataset.holidayRate || 0);
    }

    function calculateAutoPrice() {
        if (!apartmentSelect || !checkinDate || !checkoutDate || !totalAmountInput || !autoPriceSummary || !holidayTotalNote) return;
        refreshRateHint();
        totalAmountInput.setAttribute('readonly', 'readonly');

        const option = apartmentSelect.options[apartmentSelect.selectedIndex];
        if (!option || !option.value || !checkinDate.value || !checkoutDate.value) {
            autoPriceSummary.innerHTML = 'Preencha as datas para ver o resumo visual das diárias.';
            holidayTotalNote.textContent = 'O valor total muda automaticamente conforme as datas.';
            totalAmountInput.value = formatMoneyBr(entryAmountInput ? parseMoneyBr(entryAmountInput.value) : 0);
            return;
        }

        const weekdayRate = Number(option.dataset.weekdayRate || 0);
        const weekendRate = Number(option.dataset.weekendRate || 0);
        const holidayRate = Number(option.dataset.holidayRate || 0);
        const start = parseDateOnly(checkinDate.value);
        const end = parseDateOnly(checkoutDate.value);

        if (!start || !end || end < start) {
            autoPriceSummary.innerHTML = 'O check-out não pode ser anterior ao check-in.';
            totalAmountInput.value = formatMoneyBr(entryAmountInput ? parseMoneyBr(entryAmountInput.value) : 0);
            holidayTotalNote.textContent = 'Corrija as datas para calcular o total.';
            return;
        }

        const bookingDates = getChargeableDateRange(start, end, start.getTime() === end.getTime() && checkinTime && checkoutTime && checkoutTime.value > checkinTime.value);
        if (!bookingDates.length) {
            autoPriceSummary.innerHTML = 'Corrija as datas para calcular o total.';
            totalAmountInput.value = formatMoneyBr(entryAmountInput ? parseMoneyBr(entryAmountInput.value) : 0);
            holidayTotalNote.textContent = 'Corrija as datas para calcular o total.';
            return;
        }

        let total = 0;
        const entryAmount = entryAmountInput ? parseMoneyBr(entryAmountInput.value) : 0;
        let holidayDays = 0;
        let weekendDays = 0;
        const cards = [];
        const holidaysCache = {};

        bookingDates.forEach(function (cursor) {
            const year = cursor.getUTCFullYear();
            if (!holidaysCache[year]) holidaysCache[year] = getBrazilHolidays(year);

            const y = cursor.getUTCFullYear();
            const m = String(cursor.getUTCMonth() + 1).padStart(2, '0');
            const d = String(cursor.getUTCDate()).padStart(2, '0');
            const key = `${y}-${m}-${d}`;
            const dow = cursor.getUTCDay();

            let type = 'semanal';
            let rate = weekdayRate;
            let detail = '';
            let chipClass = 'weekday';

            if (holidaysCache[year][key]) {
                type = 'feriado';
                rate = holidayRate || weekendRate || weekdayRate;
                detail = holidaysCache[year][key];
                chipClass = 'holiday';
                holidayDays++;
            } else if (dow === 0 || dow === 6) {
                type = 'final de semana';
                rate = weekendRate || weekdayRate;
                detail = dow === 0 ? 'Domingo' : 'Sábado';
                chipClass = 'weekend';
                weekendDays++;
            } else {
                detail = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'][dow];
            }

            total += rate;
            cards.push(`<span class="price-chip ${chipClass}">${d}/${m} · ${type} · R$ ${formatMoneyBr(rate)}${detail ? ' · ' + detail : ''}</span>`);
        });

        const grandTotal = Math.max(total - entryAmount, 0);
        totalAmountInput.value = formatMoneyBr(grandTotal);
        autoPriceSummary.innerHTML =
            `<div class="mb-2"><strong>Hospedagem:</strong> R$ ${formatMoneyBr(total)} · <strong>Entrada paga:</strong> R$ ${formatMoneyBr(entryAmount)} · <strong>Saldo total:</strong> R$ ${formatMoneyBr(grandTotal)} · <strong>${cards.length}</strong> diária(s) · <strong>${weekendDays}</strong> fim(ns) de semana · <strong>${holidayDays}</strong> feriado(s)</div>` +
            cards.join('');
        holidayTotalNote.textContent = `${cards.length} diária(s) somadas automaticamente. Entrada descontada: R$ ${formatMoneyBr(entryAmount)}.`;
    }

    if (nameInput) {
        nameInput.addEventListener('input', findClient);
        nameInput.addEventListener('blur', findClient);
    }
    if (phoneInput) {
        phoneInput.addEventListener('input', findClient);
        phoneInput.addEventListener('blur', findClient);
    }
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (clientIdInput) clientIdInput.value = '0';
            if (matchText) matchText.textContent = 'Novo cliente será cadastrado automaticamente se não existir.';
        });
    }

    [apartmentSelect, checkinDate, checkoutDate, checkinTime, checkoutTime, entryAmountInput].forEach(function (el) {
        if (!el) return;
        el.addEventListener('change', calculateAutoPrice);
        el.addEventListener('input', calculateAutoPrice);
        el.addEventListener('blur', calculateAutoPrice);
    });

    findClient();
    refreshRateHint();
    calculateAutoPrice();
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
