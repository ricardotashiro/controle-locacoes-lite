<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();

$conn = db();
$message = '';
$editingId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$creating = isset($_GET['create']);
$search = trim($_GET['search'] ?? '');
$client = null;


function booking_based_client_status(int $bookingsCount): string {
    if ($bookingsCount >= 10) {
        return 'vip';
    }

    if ($bookingsCount >= 3) {
        return 'frequente';
    }

    return 'novo';
}

function client_status_label(string $status): string {
    return [
        'novo' => 'Novo',
        'frequente' => 'Frequente',
        'vip' => 'VIP',
        'bloqueado' => 'Bloqueado',
    ][$status] ?? ucfirst($status);
}

function client_status_badge_class(string $status): string {
    return [
        'novo' => 'text-bg-secondary',
        'frequente' => 'text-bg-info',
        'vip' => 'text-bg-warning',
        'bloqueado' => 'text-bg-danger',
    ][$status] ?? 'text-bg-secondary';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['post_action'] ?? ''), ['toggle_client_block', 'delete_client'], true)) {
    verify_csrf_or_fail();
    $id = (int)($_POST['client_id'] ?? 0);
    $action = $_POST['block_action'] ?? 'block';

    if (($_POST['post_action'] ?? '') === 'delete_client') {
        $stmt = $conn->prepare('DELETE FROM clients WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        header('Location: clients.php');
        exit;
    }

    if ($id > 0) {
        if ($action === 'unblock') {
            $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM bookings WHERE client_id = ?');
            $countStmt->bind_param('i', $id);
            $countStmt->execute();
            $count = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $status = booking_based_client_status($count);
            $stmt = $conn->prepare('UPDATE clients SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $status, $id);
            $stmt->execute();
            $message = 'Cliente desbloqueado com sucesso.';
        } else {
            $status = 'bloqueado';
            $stmt = $conn->prepare('UPDATE clients SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $status, $id);
            $stmt->execute();
            $message = 'Cliente bloqueado com sucesso.';
        }
    }
}

if ($editingId > 0) {
    $stmt = $conn->prepare('SELECT * FROM clients WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $editingId);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array(($_POST['post_action'] ?? ''), ['toggle_client_block', 'delete_client'], true)) {
    verify_csrf_or_fail();
    $id = (int)($_POST['id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = normalizePhoneDigitsPhp($_POST['phone'] ?? '');
    $whatsapp = normalizePhoneDigitsPhp($_POST['whatsapp'] ?? '');
    $document = normalizeDocumentPhp($_POST['document'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $birthDate = trim($_POST['birth_date'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $birthDateParam = $birthDate !== '' ? $birthDate : null;

    if ($fullName !== '' && $phone !== '') {
        $duplicateStmt = $conn->prepare('SELECT id FROM clients WHERE phone = ? AND id <> ? LIMIT 1');
        $duplicateStmt->bind_param('si', $phone, $id);
        $duplicateStmt->execute();
        $duplicatePhone = $duplicateStmt->get_result()->fetch_assoc();

        $duplicateDocument = null;
        if ($document !== null) {
            $documentStmt = $conn->prepare('SELECT id FROM clients WHERE document = ? AND id <> ? LIMIT 1');
            $documentStmt->bind_param('si', $document, $id);
            $documentStmt->execute();
            $duplicateDocument = $documentStmt->get_result()->fetch_assoc();
        }

        if ($duplicatePhone) {
            $message = 'Já existe um cliente cadastrado com este telefone.';
        } elseif ($duplicateDocument) {
            $message = 'Já existe um cliente cadastrado com este CPF/RG.';
        } elseif ($id > 0) {
            $stmt = $conn->prepare('UPDATE clients SET full_name = ?, phone = ?, whatsapp = ?, document = ?, email = ?, birth_date = ?, address = ?, city = ?, notes = ? WHERE id = ?');
            $stmt->bind_param('sssssssssi', $fullName, $phone, $whatsapp, $document, $email, $birthDateParam, $address, $city, $notes, $id);
            $stmt->execute();
            $message = 'Cliente atualizado com sucesso.';
            $editingId = $id;
        } else {
            $stmt = $conn->prepare('INSERT INTO clients (full_name, phone, whatsapp, document, email, birth_date, address, city, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssssssss', $fullName, $phone, $whatsapp, $document, $email, $birthDateParam, $address, $city, $notes);
            $stmt->execute();
            if ($stmt->errno === 1062) {
                $message = 'Já existe um cliente cadastrado com este telefone ou CPF/RG.';
            } else {
                $editingId = (int)$conn->insert_id;
                $message = 'Cliente cadastrado com sucesso.';
            }
        }

        if ($editingId > 0) {
            $creating = false;
            $stmt = $conn->prepare('SELECT * FROM clients WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $editingId);
            $stmt->execute();
            $client = $stmt->get_result()->fetch_assoc();
        }
    }
}


$bookingCountExpr = 'COUNT(DISTINCT b.id)';
$computedStatusExpr = "CASE
    WHEN c.status = 'bloqueado' THEN 'bloqueado'
    WHEN {$bookingCountExpr} >= 10 THEN 'vip'
    WHEN {$bookingCountExpr} >= 3 THEN 'frequente'
    ELSE 'novo'
END";

if ($search !== '') {
    $term = '%' . $search . '%';
    $sql = "SELECT c.*, {$bookingCountExpr} AS bookings_count, MAX(b.checkin_datetime) AS last_booking, {$computedStatusExpr} AS computed_status
            FROM clients c
            LEFT JOIN bookings b
              ON (
                    b.client_id = c.id
                    OR (
                        TRIM(LOWER(COALESCE(b.guest_name, ''))) = TRIM(LOWER(COALESCE(c.full_name, '')))
                        AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(b.guest_phone, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.phone, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')
                    )
                 )
            GROUP BY c.id
            HAVING c.full_name LIKE ? OR c.phone LIKE ? OR COALESCE(c.document, '') LIKE ?
            ORDER BY c.full_name ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $term, $term, $term);
    $stmt->execute();
    $clients = $stmt->get_result();
} else {
    $sql = "SELECT c.*, {$bookingCountExpr} AS bookings_count, MAX(b.checkin_datetime) AS last_booking, {$computedStatusExpr} AS computed_status
            FROM clients c
            LEFT JOIN bookings b
              ON (
                    b.client_id = c.id
                    OR (
                        TRIM(LOWER(COALESCE(b.guest_name, ''))) = TRIM(LOWER(COALESCE(c.full_name, '')))
                        AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(b.guest_phone, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.phone, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')
                    )
                 )
            GROUP BY c.id
            ORDER BY c.full_name ASC";
    $clients = $conn->query($sql);
}

$showForm = $creating || $client;

$editingComputedStatus = 'novo';
$editingBookingsCount = 0;
if ($client) {
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM bookings WHERE client_id = ?');
    $countStmt->bind_param('i', $client['id']);
    $countStmt->execute();
    $editingBookingsCount = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $editingComputedStatus = ($client['status'] ?? '') === 'bloqueado'
        ? 'bloqueado'
        : booking_based_client_status($editingBookingsCount);
}

include __DIR__ . '/includes/header.php';
?>
<div class="row g-4">
    <?php if ($showForm): ?>
    <div class="col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h4 class="mb-3"><?= $client ? 'Editar cliente' : 'Novo cliente' ?></h4>
                <?php if ($message): ?><div class="alert <?= (str_contains($message, 'Já existe') ? 'alert-warning' : 'alert-success') ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
                <form method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" value="<?= (int)($client['id'] ?? 0) ?>">
                    <div class="mb-3"><label class="form-label">Nome *</label><input type="text" class="form-control" name="full_name" value="<?= htmlspecialchars($client['full_name'] ?? '') ?>" required></div>
                    <div class="mb-3"><label class="form-label">Telefone *</label><input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($client['phone'] ?? '') ?>" required></div>
                    <div class="mb-3"><label class="form-label">WhatsApp</label><input type="text" class="form-control" name="whatsapp" value="<?= htmlspecialchars($client['whatsapp'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="form-label">Documento</label><input type="text" class="form-control" name="document" value="<?= htmlspecialchars($client['document'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="form-label">E-mail</label><input type="email" class="form-control" name="email" value="<?= htmlspecialchars($client['email'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="form-label">Data de nascimento</label><input type="date" class="form-control" name="birth_date" value="<?= htmlspecialchars($client['birth_date'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="form-label">Endereço</label><input type="text" class="form-control" name="address" value="<?= htmlspecialchars($client['address'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="form-label">Cidade</label><input type="text" class="form-control" name="city" value="<?= htmlspecialchars($client['city'] ?? '') ?>"></div>
                    <?php if ($client): ?>
                    <div class="mb-3">
                        <label class="form-label">Status automático</label>
                        <div class="p-3 rounded border bg-light">
                            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <span class="badge <?= client_status_badge_class($editingComputedStatus) ?>"><?= htmlspecialchars(client_status_label($editingComputedStatus)) ?></span>
                                <small class="text-muted"><?= $editingBookingsCount ?> reserva(s)</small>
                            </div>
                            <small class="text-muted d-block mt-2">
                                Regras automáticas: Novo até 2 reservas, Frequente a partir de 3 reservas e VIP a partir de 10 reservas.
                                O bloqueio agora é manual pelo botão abaixo.
                            </small>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3"><label class="form-label">Observações</label><textarea class="form-control" name="notes" rows="3"><?= htmlspecialchars($client['notes'] ?? '') ?></textarea></div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button class="btn btn-primary">Salvar</button>
                        <a href="clients.php" class="btn btn-outline-dark">Cancelar</a>
                    </div>
                </form>
                <?php if ($client): ?>
                    <div class="mt-3 pt-3 border-top">
                        <form method="post" class="d-inline" onsubmit="return <?= (($client['status'] ?? '') !== 'bloqueado') ? "confirm('Deseja bloquear este cliente?')" : 'true' ?>">
                            <?= csrf_input() ?>
                            <input type="hidden" name="post_action" value="toggle_client_block">
                            <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
                            <?php if (($client['status'] ?? '') === 'bloqueado'): ?>
                                <input type="hidden" name="block_action" value="unblock">
                                <button type="submit" class="btn btn-outline-success">Desbloquear cliente</button>
                            <?php else: ?>
                                <input type="hidden" name="block_action" value="block">
                                <button type="submit" class="btn btn-outline-danger">Bloquear cliente</button>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?= $showForm ? 'col-lg-8' : 'col-lg-12' ?>">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                    <div>
                        <h4 class="mb-0">Clientes cadastrados</h4>
                        <span class="text-muted small">Status automático por quantidade de reservas. Bloqueio manual por botão.</span>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="clients.php?create=1" class="btn btn-success">+ Novo cliente</a>
                        <a href="bookings.php" class="btn btn-outline-primary">Reservas</a>
                    </div>
                </div>

                <?php if (!$showForm && $message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

                <form class="row g-2 mb-3" method="get">
                    <div class="col-md-8 col-lg-9">
                        <input type="text" class="form-control" name="search" placeholder="Buscar por nome, telefone ou documento" value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-4 col-lg-3 d-flex gap-2">
                        <button class="btn btn-outline-primary w-100">Buscar</button>
                        <a href="clients.php" class="btn btn-outline-dark w-100">Limpar</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Telefone</th>
                                <th>Status</th>
                                <th>Reservas</th>
                                <th>Última hospedagem</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $clients->fetch_assoc()): ?>
                            <?php $displayStatus = $row['computed_status'] ?? $row['status'] ?? 'novo'; ?>
                            <tr>
                                <td><?= htmlspecialchars($row['full_name']) ?></td>
                                <td><?= htmlspecialchars($row['phone']) ?></td>
                                <td><span class="badge <?= client_status_badge_class($displayStatus) ?>"><?= htmlspecialchars(client_status_label($displayStatus)) ?></span></td>
                                <td><?= (int)$row['bookings_count'] ?></td>
                                <td><?= $row['last_booking'] ? date('d/m/Y', strtotime($row['last_booking'])) : '-' ?></td>
                                <td class="text-end">
                                    <div class="dropdown row-actions">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Ações</button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a href="clients.php?edit=<?= (int)$row['id'] ?>" class="dropdown-item">Editar</a></li>
                                            <li>
                                                <form method="post" class="px-2" onsubmit="return <?= (($row['status'] ?? '') !== 'bloqueado') ? "confirm('Deseja bloquear este cliente?')" : 'true' ?>">
                                                    <?= csrf_input() ?>
                                                    <input type="hidden" name="post_action" value="toggle_client_block">
                                                    <input type="hidden" name="client_id" value="<?= (int)$row['id'] ?>">
                                                    <?php if (($row['status'] ?? '') === 'bloqueado'): ?>
                                                        <input type="hidden" name="block_action" value="unblock">
                                                        <button type="submit" class="dropdown-item text-success rounded">Desbloquear</button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="block_action" value="block">
                                                        <button type="submit" class="dropdown-item text-warning rounded">Bloquear</button>
                                                    <?php endif; ?>
                                                </form>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="post" class="px-2 pb-2" onsubmit="return confirm('Excluir este cliente?')">
                                                    <?= csrf_input() ?>
                                                    <input type="hidden" name="post_action" value="delete_client">
                                                    <input type="hidden" name="client_id" value="<?= (int)$row['id'] ?>">
                                                    <button type="submit" class="dropdown-item text-danger rounded">Excluir</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
