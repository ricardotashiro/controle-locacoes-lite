<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$conn = db();

$totalApartments = (int)($conn->query('SELECT COUNT(*) total FROM apartments')->fetch_assoc()['total'] ?? 0);
$totalActive = (int)($conn->query("SELECT COUNT(*) total FROM bookings WHERE NOW() BETWEEN checkin_datetime AND checkout_datetime AND status IN ('confirmada','hospedado')")->fetch_assoc()['total'] ?? 0);
$totalFuture = (int)($conn->query("SELECT COUNT(*) total FROM bookings WHERE checkin_datetime > NOW() AND status IN ('confirmada','hospedado')")->fetch_assoc()['total'] ?? 0);
$totalCheckinsToday = (int)($conn->query("SELECT COUNT(*) total FROM bookings WHERE DATE(checkin_datetime) = CURDATE() AND status IN ('confirmada','hospedado')")->fetch_assoc()['total'] ?? 0);
$totalCheckoutsToday = (int)($conn->query("SELECT COUNT(*) total FROM bookings WHERE DATE(checkout_datetime) = CURDATE() AND status IN ('confirmada','hospedado')")->fetch_assoc()['total'] ?? 0);
$totalGuestsToday = $totalCheckinsToday + $totalCheckoutsToday;
$availableNow = max(0, $totalApartments - $totalActive);
$occupancyPercent = $totalApartments > 0 ? (int)round(($totalActive / $totalApartments) * 100) : 0;
$availabilityPercent = $totalApartments > 0 ? (int)round(($availableNow / $totalApartments) * 100) : 0;

$todayEntries = $conn->query("SELECT b.*, a.name apartment_name, a.color apartment_color FROM bookings b INNER JOIN apartments a ON a.id = b.apartment_id WHERE DATE(b.checkin_datetime) = CURDATE() AND b.status IN ('confirmada','hospedado') ORDER BY b.checkin_datetime ASC");
$todayExits = $conn->query("SELECT b.*, a.name apartment_name, a.color apartment_color FROM bookings b INNER JOIN apartments a ON a.id = b.apartment_id WHERE DATE(b.checkout_datetime) = CURDATE() AND b.status IN ('confirmada','hospedado') ORDER BY b.checkout_datetime ASC");
$upcomingBookings = $conn->query("SELECT b.*, a.name apartment_name, a.color apartment_color FROM bookings b INNER JOIN apartments a ON a.id = b.apartment_id WHERE b.checkin_datetime >= NOW() AND b.status IN ('confirmada','hospedado') ORDER BY b.checkin_datetime ASC LIMIT 6");

include __DIR__ . '/includes/header.php';
?>
<div class="home-v2-stack">
    <section class="home-v2-hero mb-4">
        <div class="home-v2-hero-content">
            <div class="home-v2-eyebrow">
                <span class="home-v2-dot"></span>
                Visão geral da operação
            </div>
            <h2 class="home-v2-title">Painel principal simplificado para o modo lite.</h2>
            <p class="home-v2-text">
                Veja rapidamente ocupação, disponibilidade, entradas, saídas e próximas reservas.
            </p>

            <div class="home-v2-chip-row">
                <span class="home-v2-chip"><i class="bi bi-building"></i> <?= $totalApartments ?> unidades</span>
                <span class="home-v2-chip"><i class="bi bi-house-check"></i> <?= $availableNow ?> disponíveis agora</span>
                <span class="home-v2-chip"><i class="bi bi-calendar2-week"></i> <?= $totalFuture ?> reservas futuras</span>
            </div>
        </div>

        <div class="home-v2-hero-side">
            <div class="home-v2-kpi-card">
                <div class="home-v2-kpi-label">Ocupação atual</div>
                <div class="home-v2-kpi-value"><?= $occupancyPercent ?>%</div>
                <div class="home-v2-progress">
                    <span style="width: <?= max(6, min(100, $occupancyPercent)) ?>%"></span>
                </div>
                <small><?= $totalActive ?> unidade(s) ocupada(s) neste momento.</small>
            </div>
            <div class="home-v2-kpi-card home-v2-kpi-soft">
                <div class="home-v2-kpi-label">Disponibilidade</div>
                <div class="home-v2-kpi-value"><?= $availabilityPercent ?>%</div>
                <div class="home-v2-progress success">
                    <span style="width: <?= max(6, min(100, $availabilityPercent)) ?>%"></span>
                </div>
                <small><?= $availableNow ?> unidade(s) livre(s) para novas reservas.</small>
            </div>
        </div>
    </section>

    <section class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xxl-2">
            <div class="card stat-card home-v2-stat-card h-100">
                <div class="card-body">
                    <div class="home-v2-stat-top">
                        <div>
                            <div class="stat-label">Unidades</div>
                            <div class="stat-value"><?= $totalApartments ?></div>
                        </div>
                        <div class="stat-icon stat-primary"><i class="bi bi-door-open"></i></div>
                    </div>
                    <div class="home-v2-stat-note">Base total cadastrada</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xxl-2">
            <div class="card stat-card home-v2-stat-card h-100">
                <div class="card-body">
                    <div class="home-v2-stat-top">
                        <div>
                            <div class="stat-label">Ocupados</div>
                            <div class="stat-value text-danger"><?= $totalActive ?></div>
                        </div>
                        <div class="stat-icon stat-danger"><i class="bi bi-house-lock"></i></div>
                    </div>
                    <div class="home-v2-stat-note">Hospedagens em andamento</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xxl-2">
            <div class="card stat-card home-v2-stat-card h-100">
                <div class="card-body">
                    <div class="home-v2-stat-top">
                        <div>
                            <div class="stat-label">Disponíveis</div>
                            <div class="stat-value text-success"><?= $availableNow ?></div>
                        </div>
                        <div class="stat-icon stat-success"><i class="bi bi-check2-circle"></i></div>
                    </div>
                    <div class="home-v2-stat-note">Prontas para locação</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xxl-2">
            <div class="card stat-card home-v2-stat-card h-100">
                <div class="card-body">
                    <div class="home-v2-stat-top">
                        <div>
                            <div class="stat-label">Entradas hoje</div>
                            <div class="stat-value text-primary"><?= $totalCheckinsToday ?></div>
                        </div>
                        <div class="stat-icon stat-primary"><i class="bi bi-box-arrow-in-right"></i></div>
                    </div>
                    <div class="home-v2-stat-note">Check-ins programados</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xxl-2">
            <div class="card stat-card home-v2-stat-card h-100">
                <div class="card-body">
                    <div class="home-v2-stat-top">
                        <div>
                            <div class="stat-label">Saídas hoje</div>
                            <div class="stat-value"><?= $totalCheckoutsToday ?></div>
                        </div>
                        <div class="stat-icon stat-warning"><i class="bi bi-box-arrow-right"></i></div>
                    </div>
                    <div class="home-v2-stat-note">Check-outs do dia</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xxl-2">
            <div class="card stat-card home-v2-stat-card h-100">
                <div class="card-body">
                    <div class="home-v2-stat-top">
                        <div>
                            <div class="stat-label">Movimentações</div>
                            <div class="stat-value text-info"><?= $totalGuestsToday ?></div>
                        </div>
                        <div class="stat-icon stat-info"><i class="bi bi-arrow-left-right"></i></div>
                    </div>
                    <div class="home-v2-stat-note">Entradas e saídas do dia</div>
                </div>
            </div>
        </div>
    </section>

    <section class="row g-3 mb-4">
        <div class="col-md-4">
            <a href="bookings.php?action=new" class="home-v2-action-box text-decoration-none">
                <i class="bi bi-plus-circle"></i>
                <div>
                    <strong>Nova reserva</strong>
                    <small>Cadastre uma nova hospedagem.</small>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="dashboard.php" class="home-v2-action-box text-decoration-none">
                <i class="bi bi-calendar3"></i>
                <div>
                    <strong>Abrir agenda</strong>
                    <small>Veja a ocupação no calendário.</small>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="clients.php" class="home-v2-action-box text-decoration-none">
                <i class="bi bi-people"></i>
                <div>
                    <strong>Clientes</strong>
                    <small>Cadastre e edite os clientes.</small>
                </div>
            </a>
        </div>
    </section>

    <section class="row g-4">
        <div class="col-xl-4">
            <div class="card h-100 home-v2-panel">
                <div class="card-body">
                    <div class="home-v2-section-head compact">
                        <div>
                            <div class="home-section-title">Entradas de hoje</div>
                            <div class="home-section-subtitle">Quem chega no dia atual.</div>
                        </div>
                        <span class="home-v2-counter"><?= $totalCheckinsToday ?></span>
                    </div>
                    <div class="timeline-list home-v2-list-scroll">
                        <?php if ($todayEntries->num_rows): while ($row = $todayEntries->fetch_assoc()): ?>
                            <div class="timeline-item">
                                <span class="timeline-color" style="background: <?= htmlspecialchars($row['apartment_color']) ?>"></span>
                                <div>
                                    <div class="fw-bold"><?= htmlspecialchars($row['guest_name']) ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($row['apartment_name']) ?> • <?= date('H:i', strtotime($row['checkin_datetime'])) ?></div>
                                </div>
                            </div>
                        <?php endwhile; else: ?>
                            <div class="empty-state-mini">Nenhuma entrada programada para hoje.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100 home-v2-panel">
                <div class="card-body">
                    <div class="home-v2-section-head compact">
                        <div>
                            <div class="home-section-title">Saídas de hoje</div>
                            <div class="home-section-subtitle">Quem encerra a hospedagem hoje.</div>
                        </div>
                        <span class="home-v2-counter muted"><?= $totalCheckoutsToday ?></span>
                    </div>
                    <div class="timeline-list home-v2-list-scroll">
                        <?php if ($todayExits->num_rows): while ($row = $todayExits->fetch_assoc()): ?>
                            <div class="timeline-item">
                                <span class="timeline-color" style="background: <?= htmlspecialchars($row['apartment_color']) ?>"></span>
                                <div>
                                    <div class="fw-bold"><?= htmlspecialchars($row['guest_name']) ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($row['apartment_name']) ?> • <?= date('H:i', strtotime($row['checkout_datetime'])) ?></div>
                                </div>
                            </div>
                        <?php endwhile; else: ?>
                            <div class="empty-state-mini">Nenhuma saída programada para hoje.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100 home-v2-panel">
                <div class="card-body">
                    <div class="home-v2-section-head compact">
                        <div>
                            <div class="home-section-title">Próximas reservas</div>
                            <div class="home-section-subtitle">Próximos check-ins confirmados.</div>
                        </div>
                        <span class="home-v2-counter accent"><?= $totalFuture ?></span>
                    </div>
                    <div class="timeline-list home-v2-list-scroll">
                        <?php if ($upcomingBookings->num_rows): while ($row = $upcomingBookings->fetch_assoc()): ?>
                            <div class="timeline-item">
                                <span class="timeline-color" style="background: <?= htmlspecialchars($row['apartment_color']) ?>"></span>
                                <div>
                                    <div class="fw-bold"><?= htmlspecialchars($row['guest_name']) ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($row['apartment_name']) ?> • <?= date('d/m H:i', strtotime($row['checkin_datetime'])) ?></div>
                                </div>
                            </div>
                        <?php endwhile; else: ?>
                            <div class="empty-state-mini">Nenhuma reserva futura cadastrada.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
