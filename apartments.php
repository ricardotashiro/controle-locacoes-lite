<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();

$conn = db();
$message = '';
$error = '';
$editingId = isset($_GET['edit']) ? (int)($_GET['edit']) : 0;
$creating = isset($_GET['create']);
$apartment = null;

if ($editingId > 0) {
    $stmt = $conn->prepare('SELECT * FROM apartments WHERE id = ?');
    $stmt->bind_param('i', $editingId);
    $stmt->execute();
    $apartment = $stmt->get_result()->fetch_assoc();
    if ($apartment) {
        $apartment['weekday_daily_rate'] = normalizeDailyRateValuePhp($apartment['weekday_daily_rate'] ?? $apartment['default_daily_rate'] ?? 0);
        $apartment['weekend_daily_rate'] = normalizeDailyRateValuePhp($apartment['weekend_daily_rate'] ?? $apartment['default_daily_rate'] ?? 0);
        $apartment['holiday_daily_rate'] = normalizeDailyRateValuePhp($apartment['holiday_daily_rate'] ?? $apartment['weekend_daily_rate'] ?? $apartment['default_daily_rate'] ?? 0);
        $apartment['default_daily_rate'] = normalizeDailyRateValuePhp($apartment['default_daily_rate'] ?? 0);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['post_action'])) {
    verify_csrf_or_fail();
    $formType = $_POST['form_type'] ?? 'apartment';

    if ($formType === 'daily_rates') {
        $apartmentId = (int)($_POST['apartment_id'] ?? 0);
        $calendarMonth = preg_match('/^\d{4}-\d{2}$/', $_POST['calendar_month'] ?? '') ? $_POST['calendar_month'] : date('Y-m');
        $dailyRates = $_POST['daily_rates'] ?? [];

        if ($apartmentId <= 0) {
            $error = 'Selecione uma unidade válida para salvar a agenda de valores.';
        } else {
            $monthStart = DateTime::createFromFormat('Y-m-d', $calendarMonth . '-01');
            $monthEnd = $monthStart ? (clone $monthStart)->modify('last day of this month') : null;
            if (!$monthStart || !$monthEnd) {
                $error = 'Mês inválido para salvar os valores.';
            } else {
                $deleteStmt = $conn->prepare('DELETE FROM apartment_daily_rates WHERE apartment_id = ? AND rate_date BETWEEN ? AND ?');
                $monthStartStr = $monthStart->format('Y-m-d');
                $monthEndStr = $monthEnd->format('Y-m-d');
                $deleteStmt->bind_param('iss', $apartmentId, $monthStartStr, $monthEndStr);
                $deleteStmt->execute();

                $insertStmt = $conn->prepare('INSERT INTO apartment_daily_rates (apartment_id, rate_date, daily_rate) VALUES (?, ?, ?)');
                foreach ($dailyRates as $date => $rawRate) {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) {
                        continue;
                    }
                    if ($date < $monthStartStr || $date > $monthEndStr) {
                        continue;
                    }
                    $rate = normalizeDailyRateValuePhp(parseMoneyValuePhp($rawRate));
                    if ($rate <= 0) {
                        continue;
                    }
                    $insertStmt->bind_param('isd', $apartmentId, $date, $rate);
                    $insertStmt->execute();
                }

                $message = 'Agenda de valores manuais salva com sucesso.';
                $editingId = $apartmentId;
            }
        }
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['type'] ?? '');
        $color = trim($_POST['color'] ?? '#0ea5e9');
        $weekdayRate = parseMoneyValuePhp($_POST['weekday_daily_rate'] ?? '0');
        $weekendRate = parseMoneyValuePhp($_POST['weekend_daily_rate'] ?? '0');
        $holidayRate = parseMoneyValuePhp($_POST['holiday_daily_rate'] ?? '0');
        $notes = trim($_POST['notes'] ?? '');

        if ($name !== '') {
            if ($id > 0) {
                $stmt = $conn->prepare('UPDATE apartments SET name = ?, type = ?, color = ?, weekday_daily_rate = ?, weekend_daily_rate = ?, holiday_daily_rate = ?, default_daily_rate = ?, notes = ? WHERE id = ?');
                $stmt->bind_param('sssddddsi', $name, $type, $color, $weekdayRate, $weekendRate, $holidayRate, $weekdayRate, $notes, $id);
                $stmt->execute();
                $message = 'Unidade atualizada com sucesso.';
                $editingId = $id;
            } else {
                $stmt = $conn->prepare('INSERT INTO apartments (name, type, color, weekday_daily_rate, weekend_daily_rate, holiday_daily_rate, default_daily_rate, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('sssdddds', $name, $type, $color, $weekdayRate, $weekendRate, $holidayRate, $weekdayRate, $notes);
                $stmt->execute();
                $message = 'Unidade cadastrada com sucesso.';
                $editingId = (int)$conn->insert_id;
            }
            $creating = false;
        }
    }

    if ($editingId > 0) {
        $stmt = $conn->prepare('SELECT * FROM apartments WHERE id = ?');
        $stmt->bind_param('i', $editingId);
        $stmt->execute();
        $apartment = $stmt->get_result()->fetch_assoc();
        if ($apartment) {
            $apartment['weekday_daily_rate'] = normalizeDailyRateValuePhp($apartment['weekday_daily_rate'] ?? $apartment['default_daily_rate'] ?? 0);
            $apartment['weekend_daily_rate'] = normalizeDailyRateValuePhp($apartment['weekend_daily_rate'] ?? $apartment['default_daily_rate'] ?? 0);
            $apartment['holiday_daily_rate'] = normalizeDailyRateValuePhp($apartment['holiday_daily_rate'] ?? $apartment['weekend_daily_rate'] ?? $apartment['default_daily_rate'] ?? 0);
            $apartment['default_daily_rate'] = normalizeDailyRateValuePhp($apartment['default_daily_rate'] ?? 0);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['post_action'] ?? '') === 'delete_apartment')) {
    verify_csrf_or_fail();
    $id = (int)($_POST['apartment_id'] ?? 0);
    $stmt = $conn->prepare('DELETE FROM apartments WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    header('Location: apartments.php');
    exit;
}

$calendarMonth = preg_match('/^\d{4}-\d{2}$/', $_GET['calendar_month'] ?? '') ? $_GET['calendar_month'] : date('Y-m');
$monthStart = DateTime::createFromFormat('Y-m-d', $calendarMonth . '-01') ?: new DateTime(date('Y-m-01'));
$monthTitle = strftime('%B de %Y', $monthStart->getTimestamp());
$prevMonth = (clone $monthStart)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $monthStart)->modify('+1 month')->format('Y-m');
$firstWeekday = (int)$monthStart->format('N');
$daysInMonth = (int)$monthStart->format('t');

$manualRatesMap = [];
if ($editingId > 0) {
    $monthEnd = (clone $monthStart)->modify('last day of this month')->format('Y-m-d');
    $monthStartStr = $monthStart->format('Y-m-d');
    $stmt = $conn->prepare('SELECT rate_date, daily_rate FROM apartment_daily_rates WHERE apartment_id = ? AND rate_date BETWEEN ? AND ?');
    $stmt->bind_param('iss', $editingId, $monthStartStr, $monthEnd);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $manualRatesMap[$row['rate_date']] = normalizeDailyRateValuePhp($row['daily_rate']);
    }
}

$showForm = $creating || $apartment;
$apartments = $conn->query('SELECT * FROM apartments ORDER BY name ASC');
include __DIR__ . '/includes/header.php';
?>
<div class="row g-4">
    <?php if ($showForm): ?>
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <h4><?= $apartment ? 'Editar unidade' : 'Nova unidade' ?></h4>
                <p class="text-muted">Defina valores de semana, fim de semana e feriado para cálculo automático.</p>
                <?php if ($message): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" value="<?= (int)($apartment['id'] ?? 0) ?>">
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input type="text" class="form-control" name="name" placeholder="Ex.: Apartamento 01" value="<?= htmlspecialchars($apartment['name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tipo</label>
                        <input type="text" class="form-control" name="type" placeholder="Ex.: Quarto / Apartamento" value="<?= htmlspecialchars($apartment['type'] ?? '') ?>">
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Cor na agenda</label>
                            <input type="color" class="form-control form-control-color w-100" name="color" value="<?= htmlspecialchars($apartment['color'] ?? '#0ea5e9') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Diária semanal</label>
                            <input type="text" class="form-control" name="weekday_daily_rate" value="<?= htmlspecialchars(number_format((float)($apartment['weekday_daily_rate'] ?? $apartment['default_daily_rate'] ?? 0), 2, ',', '.')) ?>">
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Diária final de semana</label>
                            <input type="text" class="form-control" name="weekend_daily_rate" value="<?= htmlspecialchars(number_format((float)($apartment['weekend_daily_rate'] ?? $apartment['default_daily_rate'] ?? 0), 2, ',', '.')) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Diária feriado</label>
                            <input type="text" class="form-control" name="holiday_daily_rate" value="<?= htmlspecialchars(number_format((float)($apartment['holiday_daily_rate'] ?? $apartment['weekend_daily_rate'] ?? $apartment['default_daily_rate'] ?? 0), 2, ',', '.')) ?>">
                            <div class="form-text">Na reserva, o modo manual usa primeiro a agenda da unidade e depois a agenda geral.</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Observações</label>
                        <textarea class="form-control" name="notes" rows="4"><?= htmlspecialchars($apartment['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button class="btn btn-primary">Salvar</button>
                        <a href="apartments.php" class="btn btn-outline-dark">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>

    </div>
    <?php endif; ?>

    <div class="<?= $showForm ? 'col-lg-8' : 'col-lg-12' ?>">


        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h4 class="mb-0">Unidades cadastradas</h4>
                        <span class="text-muted small">Valores separados por semana, final de semana e feriado para cálculo automático.</span>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="apartments.php?create=1" class="btn btn-success">+ Nova unidade</a>
                        <a href="bookings.php" class="btn btn-outline-primary">Reservas</a>
                    </div>
                </div>

                <?php if (!$showForm && $message): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>
                <?php if (!$showForm && $error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form class="row g-2 mb-3" method="get">
                    <div class="col-md-8 col-lg-9">
                        <input type="text" class="form-control" name="search" placeholder="Buscar unidade por nome ou tipo" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                    </div>
                    <div class="col-md-4 col-lg-3 d-flex gap-2">
                        <button class="btn btn-outline-primary w-100">Buscar</button>
                        <a href="apartments.php" class="btn btn-outline-dark w-100">Limpar</a>
                    </div>
                </form>

                <?php
                $search = trim($_GET['search'] ?? '');
                $apartmentRows = [];
                if ($search !== '') {
                    $term = '%' . $search . '%';
                    $stmt = $conn->prepare('SELECT * FROM apartments WHERE name LIKE ? OR type LIKE ? ORDER BY name ASC');
                    $stmt->bind_param('ss', $term, $term);
                    $stmt->execute();
                    $result = $stmt->get_result();
                } else {
                    $result = $apartments;
                }
                while ($row = $result->fetch_assoc()) {
                    $apartmentRows[] = $row;
                }

                $manualRatesCountMap = [];
                $manualCountResult = $conn->query('SELECT apartment_id, COUNT(*) AS total FROM apartment_daily_rates WHERE apartment_id > 0 GROUP BY apartment_id');
                if ($manualCountResult) {
                    while ($manualCountRow = $manualCountResult->fetch_assoc()) {
                        $manualRatesCountMap[(int)$manualCountRow['apartment_id']] = (int)$manualCountRow['total'];
                    }
                }

                ?>

                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>Cor</th>
                                <th>Semanal</th>
                                <th>Final de semana</th>
                                <th>Feriado</th>
                                <th>Observações</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($apartmentRows as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['name']) ?></td>
                                    <td><?= htmlspecialchars($row['type']) ?></td>
                                    <td><span class="color-pill" style="background: <?= htmlspecialchars($row['color'] ?: '#0ea5e9') ?>"></span> <?= htmlspecialchars($row['color'] ?: '#0ea5e9') ?></td>
                                    <td>R$ <?= number_format(normalizeDailyRateValuePhp($row['weekday_daily_rate'] ?? 0), 2, ',', '.') ?></td>
                                    <td>R$ <?= number_format(normalizeDailyRateValuePhp($row['weekend_daily_rate'] ?? 0), 2, ',', '.') ?></td>
                                    <td>R$ <?= number_format(normalizeDailyRateValuePhp($row['holiday_daily_rate'] ?? $row['weekend_daily_rate'] ?? 0), 2, ',', '.') ?></td>
                                    <td><?= nl2br(htmlspecialchars($row['notes'])) ?></td>
                                    <td class="text-end">
                                        <div class="dropdown row-actions">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Ações</button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li><a href="apartments.php?edit=<?= (int)$row['id'] ?>" class="dropdown-item">Editar</a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form method="post" class="px-2 pb-2" onsubmit="return confirm('Deseja excluir esta unidade?')">
                                                        <?= csrf_input() ?>
                                                        <input type="hidden" name="post_action" value="delete_apartment">
                                                        <input type="hidden" name="apartment_id" value="<?= (int)$row['id'] ?>">
                                                        <button type="submit" class="dropdown-item text-danger rounded">Excluir</button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
