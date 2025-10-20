{{-- resources/views/facility_blocks/board.blade.php --}}
@php
    /**
     * Data yang diharapkan:
     * - $facilities: Collection<Facility{id, name}>
     * - $nameMap: array<int,string>
     * - $buckets: ['in_use'=>int[], 'dirty'=>int[], 'inspection'=>int[], 'blocked'=>int[], 'ready'=>int[]]
     * - $stats:    ['in_use'=>int, 'dirty'=>int, 'inspection'=>int, 'blocked'=>int, 'ready'=>int, 'total'=>int]
     * - $calendarEvents: array<{
     *      id:int, title:string, start:string, end:string,
     *      facility_id:int, facility_name?:string, guest_name?:string, status?:string
     *   }>
     */
    $calendarEvents = $calendarEvents ?? [];

    $statusOf = function (int $fid) use ($buckets) {
        if (in_array($fid, $buckets['in_use'] ?? [], true))     return 'in_use';
        if (in_array($fid, $buckets['inspection'] ?? [], true)) return 'inspection';
        if (in_array($fid, $buckets['dirty'] ?? [], true))      return 'dirty';
        if (in_array($fid, $buckets['blocked'] ?? [], true))    return 'blocked';
        return 'ready';
    };

    $badgeOf = fn(string $st) => match ($st) {
        'in_use'     => 'IN USE',
        'inspection' => 'INSPECTION',
        'dirty'      => 'DIRTY',
        'blocked'    => 'BLOCKED',
        default      => '',
    };
@endphp

<style>
/* ===== Utilities ===== */
.hidden{display:none!important;}
/* ===== Layout ===== */
.fb-layout{display:grid;gap:1rem}
@media (min-width:1024px){.fb-layout{grid-template-columns:260px 1fr}}

/* ===== Card ===== */
.fb-card{border-radius:12px;overflow:hidden;background:#fff;border:1px solid #e5e7eb;box-shadow:0 1px 2px rgba(0,0,0,.06)}
.fb-head{background:#f3f4f6;padding:6px 10px;font:700 11px/1.2 system-ui,Arial;display:flex;justify-content:space-between;align-items:center}
.fb-badge{font-size:10px;font-weight:800;padding:2px 6px;border-radius:4px;background:rgba(0,0,0,.15);color:#111}
.fb-tile{height:120px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;position:relative}
.fb-name{font-size:18px;font-weight:900;line-height:1.1;letter-spacing:.2px;padding:0 8px;text-align:center}
.tile-button{position:absolute;inset:0;border:0;background:transparent;cursor:pointer}

/* ===== Colors ===== */
.tile--ready      {background:#1E88E5; color:#fff;}
.tile--in_use     {background:#6C2AB2; color:#fff;}
.tile--inspection {background:#FDE047; color:#111;}
.tile--dirty      {background:#93C5FD; color:#111;}
.tile--blocked    {background:#6B7280; color:#fff;}

/* ===== Left panel ===== */
.fb-panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;box-shadow:0 1px 2px rgba(0,0,0,.06)}
.fb-title{font:800 12px/1.2 system-ui,Arial;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px}
.fb-legend{display:grid;gap:6px}
.fb-leg{display:flex;align-items:center;gap:8px;font:700 11px/1.2 system-ui,Arial;color:#111;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:6px 8px}
.fb-dot{width:14px;height:14px;border-radius:3px;border:1px solid rgba(0,0,0,.15)}
.fb-count{margin-left:auto;background:#111;color:#fff;font-weight:800;font-size:10px;border-radius:6px;padding:2px 8px}

/* ===== Board header & grid ===== */
.fb-board-title{font:900 14px/1.2 system-ui,Arial;text-align:center;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:8px;margin-bottom:10px}
.fb-grid{display:grid;gap:.9rem;grid-template-columns:repeat(2,minmax(0,1fr))}
@media(min-width:640px){.fb-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(min-width:768px){.fb-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media(min-width:1024px){.fb-grid{grid-template-columns:repeat(5,minmax(0,1fr))}}
@media(min-width:1280px){.fb-grid{grid-template-columns:repeat(5,minmax(0,1fr))}}

/* ===== Misc ===== */
.fb-card:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(2,6,23,.08);transition:.15s}
.fb-code{margin-top:8px;font:900 12px/1 system-ui,Arial;letter-spacing:.6px;padding:2px 8px;border-radius:999px;background:rgba(255,255,255,.85);color:#111;box-shadow:0 1px 2px rgba(0,0,0,.2)}

/* ===== Calendar wrapper ===== */
.fb-cal-wrap{margin-top:16px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}
.fb-cal-head{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:10px 12px;background:#f8fafc;border-bottom:1px solid #e5e7eb}
.fb-cal-title{font:800 13px/1.2 system-ui,Arial;color:#0f172a}
.fb-cal-tools{display:flex;gap:8px;align-items:center}
.fb-select{border:1px solid #e5e7eb;border-radius:8px;padding:6px 10px;font:600 12px/1 system-ui}
#facility-calendar{padding:12px}

/* ===== Modal ===== */
.fc-modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.45);display:none;align-items:center;justify-content:center;z-index:100;padding:1rem}
.fc-modal{width:100%;max-width:520px;background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;border:1px solid #e5e7eb}
.fc-modal-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #e5e7eb;background:#f8fafc}
.fc-modal-title{font:800 14px/1.2 system-ui,Arial;color:#0f172a}
.fc-modal-body{padding:14px 16px;font:600 12px/1.4 system-ui,Arial;color:#0f172a}
.fc-close{background:transparent;border:none;font-size:16px;cursor:pointer;color:#334155}
.fc-row{display:grid;grid-template-columns:120px 1fr;gap:8px;margin-bottom:8px}
.fc-row b{color:#475569}
.rb-radio-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.rb-radio{display:flex;gap:8px;align-items:center;border:1px solid #e5e7eb;border-radius:10px;padding:8px 10px}
.rb-modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
.rb-btn-primary{background:#0ea5e9;color:#fff;border:none;border-radius:10px;padding:8px 12px;font:800 12px/1 system-ui;cursor:pointer}
.rb-btn-secondary{background:#e5e7eb;color:#111;border:none;border-radius:10px;padding:8px 12px;font:800 12px/1 system-ui;cursor:pointer}
</style>

{{-- FullCalendar CSS/JS --}}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<div class="fb-layout">
    {{-- ===== PANEL KIRI ===== --}}
    <aside class="fb-panel">
        <div class="fb-title">Facility Status</div>
        <div class="fb-legend">
            <div class="fb-leg"><span class="fb-dot" style="background:#6C2AB2"></span>In Use <span class="fb-count">{{ $stats['in_use'] ?? 0 }}</span></div>
            <div class="fb-leg"><span class="fb-dot" style="background:#FDE047"></span>Inspection <span class="fb-count">{{ $stats['inspection'] ?? 0 }}</span></div>
            <div class="fb-leg"><span class="fb-dot" style="background:#93C5FD"></span>Dirty <span class="fb-count">{{ $stats['dirty'] ?? 0 }}</span></div>
            <div class="fb-leg"><span class="fb-dot" style="background:#6B7280"></span>Blocked <span class="fb-count">{{ $stats['blocked'] ?? 0 }}</span></div>
            <div class="fb-leg"><span class="fb-dot" style="background:#1E88E5"></span>Ready <span class="fb-count">{{ $stats['ready'] ?? 0 }}</span></div>
        </div>
        <div class="mt-3" style="display:flex;justify-content:space-between;font-weight:900;">
            <span>TOTAL FACILITY</span>
            <span>{{ $stats['total'] ?? 0 }}</span>
        </div>
    </aside>

    {{-- ===== GRID + CALENDAR ===== --}}
    <main>
        <div class="fb-board-title">Facilities — Live Status</div>

        <div class="fb-grid">
            @forelse ($facilities as $f)
                @php
                    $st    = $statusOf((int) $f->id);
                    $class = match ($st) {
                        'in_use'     => 'tile--in_use',
                        'inspection' => 'tile--inspection',
                        'dirty'      => 'tile--dirty',
                        'blocked'    => 'tile--blocked',
                        default      => 'tile--ready',
                    };
                    $badge = $badgeOf($st);
                    $name  = $nameMap[$f->id] ?? $f->name ?? ('#'.$f->id);
                @endphp

                <div class="fb-card" data-facility-id="{{ (int)$f->id }}" wire:key="facility-{{ $f->id }}">
                    <div class="fb-head">
                        <span>{{ $name }}</span>
                        <span class="fb-badge">{{ $badge ?: ' ' }}</span>
                    </div>

                    <div class="fb-tile {{ $class }}">
                        <button type="button"
                                class="tile-button js-open-facility-modal"
                                data-target="modal-facility-{{ (int)$f->id }}"
                                aria-label="Open modal {{ $name }}"></button>

                        <div class="fb-name">{{ $name }}</div>
                        <div class="fb-code">{{ strtoupper(str_replace('_',' ', $st)) }}</div>
                    </div>
                </div>
            @empty
                <div class="col-span-full text-sm text-gray-500">Belum ada facility.</div>
            @endforelse
        </div>

        <div class="fb-cal-wrap">
            <div class="fb-cal-head">
                <div class="fb-cal-title">Calendar — Bookings & Blocks</div>
                <div class="fb-cal-tools">
                    <label for="facility-filter" style="font:700 12px/1 system-ui;margin-right:6px;color:#334155">Facility:</label>
                    <select id="facility-filter" class="fb-select">
                        <option value="">All Facilities</option>
                        @foreach($facilities as $f)
                            <option value="{{ (int)$f->id }}">{{ $nameMap[$f->id] ?? $f->name ?? ('#'.$f->id) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div id="facility-calendar" wire:ignore></div>
        </div>
    </main>
</div>

{{-- ===== MODAL PER FACILITY ===== --}}
@foreach ($facilities as $f)
    @php
        $st      = $statusOf((int) $f->id);
        $name    = $nameMap[$f->id] ?? $f->name ?? ('#'.$f->id);
        $modalId = 'modal-facility-'.(int)$f->id;
    @endphp

    <div id="{{ $modalId }}" class="fc-modal-backdrop hidden" data-modal-backdrop wire:ignore>
        <div class="fc-modal">
            <div class="fc-modal-head">
                <div class="fc-modal-title">Ubah Status — {{ $name }}</div>
                <button type="button" class="fc-close js-close-modal" data-target="{{ $modalId }}">✕</button>
            </div>

            <div class="fc-modal-body">
                @php
                    $options = [
                        'ready'      => 'Ready',
                        'inspection' => 'Inspection',
                        'blocked'    => 'Blocked (OOO/Maintenance)',
                    ];
                @endphp

                <div class="rb-radio-grid">
                    @foreach ($options as $val => $label)
                        <label class="rb-radio">
                            {{-- name unik per modal agar mudah dicari --}}
                            <input type="radio"
                                   class="qs-status"
                                   name="status-{{ (int)$f->id }}"
                                   value="{{ $val }}"
                                   {{ $st === $val ? 'checked' : '' }}>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="rb-modal-actions">
                    <button type="button"
                            class="rb-btn-primary js-qs-submit"
                            data-action="{{ route('facilities.quick-status', $f) }}"
                            data-modal="{{ $modalId }}"
                            data-facility="{{ (int)$f->id }}">
                        Save
                    </button>
                    <button type="button" class="rb-btn-secondary js-close-modal" data-target="{{ $modalId }}">Cancel</button>
                </div>
            </div>
        </div>
    </div>
@endforeach

{{-- ===== Modal Detail Event (kalender) ===== --}}
<div id="fcModal" class="fc-modal-backdrop hidden" wire:ignore>
    <div class="fc-modal">
        <div class="fc-modal-head">
            <div class="fc-modal-title" id="fcModalTitle">Booking Detail</div>
            <button class="fc-close js-close-modal" data-target="fcModal">✕</button>
        </div>
        <div class="fc-modal-body">
            <div class="fc-row"><b>Facility</b><div id="fcFacility"></div></div>
            <div class="fc-row"><b>Guest</b><div id="fcGuest"></div></div>
            <div class="fc-row"><b>Status</b><div id="fcStatus"></div></div>
            <div class="fc-row"><b>Start</b><div id="fcStart"></div></div>
            <div class="fc-row"><b>End</b><div id="fcEnd"></div></div>
        </div>
    </div>
</div>

<script>
(function () {
    // ===== Modal open/close =====
    function openModal(id){ var el = document.getElementById(id); if(el){ el.classList.remove('hidden'); el.style.display='flex'; } }
    function closeModal(id){ var el = document.getElementById(id); if(el){ el.style.display='none'; el.classList.add('hidden'); } }

    document.addEventListener('click', function(e){
        var trigger = e.target.closest('.js-open-facility-modal');
        if (!trigger) return;
        var target = trigger.getAttribute('data-target');
        if (target) openModal(target);
    });

    document.addEventListener('click', function(e){
        var closer = e.target.closest('.js-close-modal');
        if (!closer) return;
        var target = closer.getAttribute('data-target');
        if (target) closeModal(target);
    });

    document.querySelectorAll('[data-modal-backdrop]').forEach(function(backdrop){
        backdrop.addEventListener('click', function(e){
            if (e.target === backdrop) closeModal(backdrop.id);
        });
    });

    // ===== Submit Quick Status (buat form sementara) =====
    function submitQuickStatus(actionUrl, statusValue) {
        const fm = document.createElement('form');
        fm.method = 'POST';
        fm.action = actionUrl;

        const t = document.createElement('input');
        t.type = 'hidden'; t.name = '_token'; t.value = '{{ csrf_token() }}';
        fm.appendChild(t);

        const m = document.createElement('input');
        m.type = 'hidden'; m.name = '_method'; m.value = 'PATCH';
        fm.appendChild(m);

        const s = document.createElement('input');
        s.type = 'hidden'; s.name = 'status'; s.value = statusValue;
        fm.appendChild(s);

        document.body.appendChild(fm);
        fm.submit();
    }

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.js-qs-submit');
        if (!btn) return;

        const action  = btn.getAttribute('data-action');
        const modalId = btn.getAttribute('data-modal');
        const fid     = btn.getAttribute('data-facility');

        const modal = document.getElementById(modalId);
        const picked = modal
            ? modal.querySelector('input.qs-status[name="status-'+fid+'"]:checked')
            : null;

        if (!picked) { alert('Pilih status terlebih dahulu'); return; }

        submitQuickStatus(action, picked.value);
        if (modalId) closeModal(modalId);
    });

    // ===== FullCalendar =====
    const rawEvents = @json($calendarEvents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    const nameMap   = @json($nameMap ?? [], JSON_UNESCAPED_UNICODE);

    function colorFor(status) {
        switch ((status || '').toUpperCase()) {
            case 'CONFIRM':
            case 'PAID':         return '#6C2AB2'; // in_use
            case 'INSPECTION':
            case 'VCI':          return '#FDE047'; // inspection
            case 'BLOCKED':
            case 'OOO':
            case 'MAINTENANCE':  return '#6B7280'; // blocked
            case 'DIRTY':        return '#93C5FD'; // dirty
            default:             return '#1E88E5'; // ready/other
        }
    }

    function mapEvents(facilityId) {
        return rawEvents
            .filter((ev) => !facilityId || String(ev.facility_id) === String(facilityId))
            .map((ev) => ({
                id: String(ev.id ?? Math.random()),
                title: ev.title ?? ((nameMap[ev.facility_id] || ev.facility_name || '#' + ev.facility_id) + (ev.guest_name ? ' — ' + ev.guest_name : '')),
                start: ev.start,
                end: ev.end,
                extendedProps: {
                    facility_id: ev.facility_id,
                    facility_name: nameMap[ev.facility_id] || ev.facility_name || '#' + ev.facility_id,
                    guest_name: ev.guest_name || '',
                    status: ev.status || '',
                },
                backgroundColor: colorFor(ev.status),
                borderColor: colorFor(ev.status),
            }));
    }

    let calendar;

    function initCalendar(facilityId) {
        const el = document.getElementById('facility-calendar');
        if (!el) return;
        if (calendar) { calendar.destroy(); calendar = null; }

        calendar = new FullCalendar.Calendar(el, {
            initialView: 'dayGridMonth',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
            navLinks: true,
            selectable: false,
            nowIndicator: true,
            height: 'auto',
            slotMinTime: '06:00:00',
            slotMaxTime: '24:00:00',
            events: mapEvents(facilityId),
            eventClick(info) {
                const ev = info.event, ex = ev.extendedProps || {};
                document.getElementById('fcModalTitle').textContent = ev.title || 'Booking Detail';
                document.getElementById('fcFacility').textContent   = ex.facility_name || '-';
                document.getElementById('fcGuest').textContent      = ex.guest_name || '-';
                document.getElementById('fcStatus').textContent     = ex.status || '-';
                document.getElementById('fcStart').textContent      = ev.start ? ev.start.toLocaleString() : '-';
                document.getElementById('fcEnd').textContent        = ev.end ? ev.end.toLocaleString() : '-';
                openModal('fcModal');
            },
        });

        calendar.render();
        setTimeout(() => calendar.updateSize(), 50);
    }

    function initCalendarWhenVisible(facilityId) {
        const el = document.getElementById('facility-calendar');
        if (!el) return;

        const tryInit = () => {
            if (el.getBoundingClientRect().width < 80) { requestAnimationFrame(tryInit); return; }
            initCalendar(facilityId || '');

            if (window.ResizeObserver) {
                const ro = new ResizeObserver(() => { if (calendar) calendar.updateSize(); });
                ro.observe(el);
            }
            window.addEventListener('resize', () => calendar && calendar.updateSize(), { passive: true });
        };

        requestAnimationFrame(tryInit);
    }

    const sel = document.getElementById('facility-filter');
    if (sel) sel.addEventListener('change', () => { initCalendar(sel.value || ''); setTimeout(() => calendar && calendar.updateSize(), 10); });

    const boot = () => initCalendarWhenVisible('');
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
</script>
