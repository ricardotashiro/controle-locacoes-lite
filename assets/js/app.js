document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initCalendar();
    if (!window.__bookingPageEnhanced) {
        initBookingForm();
    }
});

function initSidebar() {
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const closeTargets = document.querySelectorAll('[data-sidebar-close]');
    const body = document.body;
    if (!toggle) return;

    const closeSidebar = () => body.classList.remove('sidebar-open');
    const openSidebar = () => body.classList.add('sidebar-open');

    toggle.addEventListener('click', () => {
        body.classList.toggle('sidebar-open');
    });

    closeTargets.forEach((el) => el.addEventListener('click', closeSidebar));
    document.querySelectorAll('.sidebar-link').forEach((link) => link.addEventListener('click', closeSidebar));
    window.addEventListener('resize', () => {
        if (window.innerWidth > 991) closeSidebar();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeSidebar();
    });
}

function initCalendar() {
    const calendarEl = document.getElementById('bookingCalendar');
    if (!calendarEl) return;

    const apartmentFilter = document.getElementById('calendarApartmentFilter');
    const modalEl = document.getElementById('bookingModal');
    const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
    const modalBody = document.getElementById('bookingModalBody');
    const editBookingBtn = document.getElementById('editBookingBtn');
    const contractBtn = document.getElementById('contractBtn');
    const modalTitle = modalEl?.querySelector('.modal-title');

    const holidayCache = new Map();


    function removeWeekButtons() {
        document.querySelectorAll('.fc-timeGridWeek-button, .fc-listWeek-button, [data-view="timeGridWeek"], [data-view="listWeek"]').forEach((btn) => btn.remove());
        document.querySelectorAll('.fc-toolbar-chunk').forEach((chunk) => {
            if (!chunk.textContent.trim()) chunk.remove();
        });
    }

    const calendar = new FullCalendar.Calendar(calendarEl, {
        locale: 'pt-br',
        timeZone: 'America/Sao_Paulo',
        initialView: 'dayGridMonth',
        selectable: true,
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,listMonth'
        },
        buttonText: {
            today: 'Hoje',
            month: 'Mês',
            listMonth: 'Lista'
        },
        views: {
            dayGridMonth: { dayMaxEventRows: 4 },
            listMonth: { buttonText: 'Lista' }
        },
        datesSet() {
            removeWeekButtons();
        },
        eventSources: [
            {
                events(fetchInfo, success, failure) {
                    const params = new URLSearchParams();
                    if (apartmentFilter && apartmentFilter.value) {
                        params.set('apartment_id', apartmentFilter.value);
                    }

                    fetch(`api/events.php?${params.toString()}`)
                        .then((r) => r.json())
                        .then((events) => success(events))
                        .catch(failure);
                }
            },
            {
                events(fetchInfo, success) {
                    const years = [fetchInfo.start.getUTCFullYear(), fetchInfo.end.getUTCFullYear()];
                    const seen = new Set();
                    let events = [];
                    years.forEach((year) => {
                        if (!holidayCache.has(year)) {
                            holidayCache.set(year, getBrazilHolidays(year));
                        }
                        holidayCache.get(year).forEach((holiday) => {
                            if (!seen.has(holiday.date)) {
                                seen.add(holiday.date);
                                events.push({
                                    id: `holiday-${holiday.date}`,
                                    title: holiday.name,
                                    start: holiday.date,
                                    allDay: true,
                                    display: 'background',
                                    backgroundColor: 'rgba(239, 68, 68, 0.08)',
                                    extendedProps: {
                                        event_kind: 'holiday',
                                        holiday_name: holiday.name,
                                        holiday_date: holiday.date,
                                    }
                                });
                            }
                        });
                    });
                    success(events);
                }
            }
        ],
        eventContent: function(arg) {
            const p = arg.event.extendedProps || {};
            if (p.event_kind === 'block') {
                return {
                    html: `
                    <div class="fc-custom-event fc-custom-event-block">
                        <div class="fc-custom-event-unit">${escapeHtml(p.apartment_name || '')}</div>
                        <div class="fc-custom-event-guest">${escapeHtml(p.reason || 'Agenda fechada')}</div>
                        <div class="fc-custom-event-time">${formatTimeOnly(p.start_datetime)} • ${formatTimeOnly(p.end_datetime)}</div>
                    </div>`
                };
            }

            return {
                html: `
                <div class="fc-custom-event">
                    <div class="fc-custom-event-unit">${escapeHtml(p.apartment_name || '')}</div>
                    <div class="fc-custom-event-guest">${escapeHtml(p.guest_name || '')}</div>
                    <div class="fc-custom-event-time">${formatTimeOnly(p.checkin_datetime)} • ${formatTimeOnly(p.checkout_datetime)}</div>
                </div>`
            };
        },
        eventClick: function(info) {
            const e = info.event;
            const p = e.extendedProps || {};
            if (!modal || !modalBody) return;

            if (p.event_kind === 'block') {
                if (modalTitle) modalTitle.textContent = 'Detalhes do fechamento';
                modalBody.innerHTML = `
                    <div class="booking-detail-grid">
                        <div class="detail-card">
                            <div class="detail-label">Unidade</div>
                            <div class="detail-value">${escapeHtml(p.apartment_name || '-')}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">Motivo</div>
                            <div class="detail-value">${escapeHtml(p.reason || 'Agenda fechada')}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">Início</div>
                            <div class="detail-value">${formatDateTime(p.start_datetime)}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">Fim</div>
                            <div class="detail-value">${formatDateTime(p.end_datetime)}</div>
                        </div>
                        <div class="detail-card wide">
                            <div class="detail-label">Observações</div>
                            <div class="detail-value">${escapeHtml(p.notes || '-')}</div>
                        </div>
                    </div>`;
                editBookingBtn.href = `bookings.php`;
                editBookingBtn.textContent = 'Abrir cadastros';
                contractBtn.classList.add('d-none');
                modal.show();
                return;
            }

            if (modalTitle) modalTitle.textContent = 'Detalhes da reserva';
            editBookingBtn.textContent = 'Editar';
            const statusLabel = statusMap(p.status);
            modalBody.innerHTML = `
                <div class="booking-detail-grid">
                    <div class="detail-card">
                        <div class="detail-label">Unidade</div>
                        <div class="detail-value">${escapeHtml(p.apartment_name ?? '-')}</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Hóspede</div>
                        <div class="detail-value">${escapeHtml(p.guest_name ?? '-')}</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Telefone</div>
                        <div class="detail-value">${escapeHtml(p.guest_phone || '-')}</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Documento</div>
                        <div class="detail-value">${escapeHtml(p.guest_document || '-')}</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Check-in</div>
                        <div class="detail-value">${formatDateTime(p.checkin_datetime)}</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Check-out</div>
                        <div class="detail-value">${formatDateTime(p.checkout_datetime)}</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Status</div>
                        <div class="detail-value">${statusLabel}</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Valor total</div>
                        <div class="detail-value">R$ ${numberBr(p.total_amount)}</div>
                    </div>
                    <div class="detail-card wide">
                        <div class="detail-label">Observações</div>
                        <div class="detail-value">${escapeHtml(p.notes || '-')}</div>
                    </div>
                </div>`;

            editBookingBtn.href = `bookings.php?edit=${p.booking_id || ''}`;
            if (contractBtn) {
                contractBtn.classList.add('d-none');
            }
            modal.show();
        },
        select: function(selectionInfo) {
            const start = selectionInfo.startStr.substring(0, 10);
            const apartmentId = apartmentFilter && apartmentFilter.value ? `&apartment_id=${encodeURIComponent(apartmentFilter.value)}` : '';
            window.location.href = `bookings.php?action=new&start=${start}${apartmentId}`;
        },
        eventDidMount: function(info) {
            const p = info.event.extendedProps || {};
            info.el.setAttribute('title', `${p.apartment_name} | ${p.guest_name} | ${formatDateTime(p.checkin_datetime)} até ${formatDateTime(p.checkout_datetime)}`);
        },
        dayCellDidMount: function(info) {
            const holidays = holidayCache.get(info.date.getFullYear()) || getBrazilHolidays(info.date.getFullYear());
            holidayCache.set(info.date.getFullYear(), holidays);
            const key = formatDateKey(info.date);
            const holiday = holidays.find((item) => item.date === key);
            if (!holiday) return;

            info.el.classList.add('holiday-day-cell');
            const top = info.el.querySelector('.fc-daygrid-day-top');
            if (top && !top.querySelector('.holiday-badge')) {
                const badge = document.createElement('span');
                badge.className = 'holiday-badge';
                badge.textContent = 'Feriado';
                badge.title = holiday.name;
                top.appendChild(badge);
            }

            const frame = info.el.querySelector('.fc-daygrid-day-frame');
            if (frame && !frame.querySelector('.holiday-name')) {
                const holidayName = document.createElement('div');
                holidayName.className = 'holiday-name';
                holidayName.textContent = holiday.name;
                holidayName.title = holiday.name;
                frame.appendChild(holidayName);
            }

            info.el.setAttribute('title', holiday.name);
        }
    });

    if (apartmentFilter) {
        apartmentFilter.addEventListener('change', function() {
            calendar.refetchEvents();
        });
    }

    calendar.render();
    removeWeekButtons();
}

function initBookingForm() {
    return;
}

function getBrazilHolidays(year) {
    const easter = getEasterDate(year);
    const format = (d) => `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}-${String(d.getUTCDate()).padStart(2, '0')}`;
    const addDays = (date, days) => {
        const d = new Date(date.getTime());
        d.setUTCDate(d.getUTCDate() + days);
        return d;
    };

    return [
        { date: `${year}-01-01`, name: 'Confraternização Universal' },
        { date: format(addDays(easter, -48)), name: 'Carnaval' },
        { date: format(addDays(easter, -47)), name: 'Carnaval' },
        { date: format(addDays(easter, -2)), name: 'Sexta-feira Santa' },
        { date: format(easter), name: 'Páscoa' },
        { date: `${year}-04-21`, name: 'Tiradentes' },
        { date: `${year}-05-01`, name: 'Dia do Trabalhador' },
        { date: format(addDays(easter, 60)), name: 'Corpus Christi' },
        { date: `${year}-09-07`, name: 'Independência do Brasil' },
        { date: `${year}-10-12`, name: 'Nossa Senhora Aparecida' },
        { date: `${year}-11-02`, name: 'Finados' },
        { date: `${year}-11-15`, name: 'Proclamação da República' },
        { date: `${year}-11-20`, name: 'Dia da Consciência Negra' },
        { date: `${year}-12-25`, name: 'Natal' },
    ];
}

function getEasterDate(year) {
    const a = year % 19;
    const b = Math.floor(year / 100);
    const c = year % 100;
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
    return new Date(Date.UTC(year, month - 1, day, 12, 0, 0));
}

function formatDateKey(date) {
    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
}

function formatDateTime(value) {
    if (!value) return '-';
    const dt = new Date(value.replace(' ', 'T'));
    if (isNaN(dt)) return value;
    return dt.toLocaleString('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function formatTimeOnly(value) {
    if (!value) return '--:--';
    const dt = new Date(value.replace(' ', 'T'));
    if (isNaN(dt)) return value;
    return dt.toLocaleTimeString('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function numberBr(value) {
    const n = parseFloat(value || 0);
    return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function statusMap(status) {
    const map = {
        confirmada: 'Confirmada',
        hospedado: 'Hospedado',
        finalizada: 'Finalizada',
        cancelada: 'Cancelada'
    };
    return map[status] || status || '-';
}

function escapeHtml(str) {
    return String(str)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;')
        .replaceAll('\n', '<br>');
}
