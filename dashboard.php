<?php
require_once __DIR__ . '/auth.php';
require_login();

include __DIR__ . '/includes/header.php';
?>
<div class="agenda-hero mb-3">
    <div>
        <span class="agenda-hero-badge"><i class="bi bi-stars"></i> Agenda visual</span>
        <h2 class="agenda-hero-title mb-1">Calendário de ocupação</h2>
        <p class="agenda-hero-text mb-0">Visual mais limpo, com bordas suaves, modo mês e lista, e cartões que expandem ao passar o mouse.</p>
    </div>
    <div class="agenda-hero-chip"><i class="bi bi-info-circle"></i> Clique em uma reserva para ver os detalhes</div>
</div>

<div class="card border-0 shadow-sm agenda-only-card agenda-calendar-shell">
    <div class="card-body agenda-only-body">
        <div id="bookingCalendar"></div>
    </div>
</div>

<div class="modal fade" id="bookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detalhes da reserva</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="bookingModalBody"></div>
            <div class="modal-footer">
                <a href="bookings.php" id="editBookingBtn" class="btn btn-primary"><i class="bi bi-pencil-square"></i> Editar</a>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
