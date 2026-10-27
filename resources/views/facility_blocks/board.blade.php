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
.fb-theme{--fb-surface:#fff;--fb-panel:#f5f8fa;--fb-soft:#eceff4;--fb-border:#d8dee9;--fb-text:#2e3440;--fb-muted:#4c566a;--fb-accent:#5e81ac;--fb-accent-hover:#4c6e99;--fb-on-accent:#fff;--fb-shadow:0 1px 2px rgba(46,52,64,.08);color:var(--fb-text);color-scheme:light}
.dark .fb-theme{--fb-surface:#2e3440;--fb-panel:#3b4252;--fb-soft:#434c5e;--fb-border:rgba(216,222,233,.15);--fb-text:#e5e9f0;--fb-muted:#d8dee9;--fb-accent:#88c0d0;--fb-accent-hover:#8fcede;--fb-on-accent:#2e3440;--fb-shadow:0 8px 24px rgba(0,0,0,.16);color-scheme:dark}
.fb-theme,.fb-theme *{box-sizing:border-box}
.fb-theme .hidden{display:none!important}
.fb-layout{display:grid;gap:1rem}
@media (min-width:1024px){.fb-layout{grid-template-columns:260px minmax(0,1fr)}}
.fb-panel,.fb-board-title,.fb-card,.fb-cal-wrap{background:var(--fb-surface);color:var(--fb-text);border:1px solid var(--fb-border);border-radius:14px;box-shadow:var(--fb-shadow)}
.fb-panel{padding:14px;align-self:start}
.fb-title{font-family:inherit;font-size:12px;font-weight:800;line-height:1.2;color:var(--fb-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px}
.fb-legend{display:grid;gap:7px}
.fb-leg{display:flex;align-items:center;gap:8px;font-family:inherit;font-size:12px;font-weight:700;line-height:1.3;color:var(--fb-text);background:var(--fb-panel);border:1px solid var(--fb-border);border-radius:9px;padding:8px 9px}
.fb-dot{width:14px;height:14px;flex:none;border-radius:4px;border:1px solid rgba(46,52,64,.22)}
.fb-count{margin-left:auto;background:var(--fb-soft);color:var(--fb-text);font-weight:800;font-size:11px;border-radius:999px;padding:3px 8px;min-width:26px;text-align:center}
.fb-board-title{font-size:15px;font-weight:800;letter-spacing:.01em;padding:12px 14px;margin-bottom:12px}
.fb-grid{display:grid;gap:.9rem;grid-template-columns:repeat(2,minmax(0,1fr))}
@media(min-width:640px){.fb-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(min-width:768px){.fb-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media(min-width:1024px){.fb-grid{grid-template-columns:repeat(5,minmax(0,1fr))}}
.fb-card{overflow:hidden;transition:transform .15s ease,box-shadow .15s ease}
.fb-card:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(0,0,0,.14)}
.fb-head{background:var(--fb-panel);color:var(--fb-text);padding:8px 10px;font-family:inherit;font-size:12px;font-weight:700;line-height:1.3;display:flex;justify-content:space-between;align-items:center;gap:8px}
.fb-badge{font-size:10px;font-weight:800;padding:3px 7px;border-radius:999px;background:var(--fb-soft);color:var(--fb-muted)}
.fb-tile{height:120px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;position:relative}
.fb-name{font-size:18px;font-weight:800;line-height:1.15;letter-spacing:.01em;padding:0 8px;text-align:center}
.tile-button{position:absolute;inset:0;border:0;background:transparent;cursor:pointer}
.tile-button:focus-visible,.fb-empty-link:focus-visible,.fb-select:focus-visible{outline:3px solid var(--fb-accent);outline-offset:2px}
.tile--ready{background:#5e81ac;color:#fff}
.tile--in_use{background:#8f6aad;color:#fff}
.tile--inspection{background:#ebcb8b;color:#2e3440}
.tile--dirty{background:#81a1c1;color:#2e3440}
.tile--blocked{background:#616e88;color:#fff}
.fb-code{margin-top:8px;font:800 11px/1 system-ui,Arial;letter-spacing:.06em;padding:4px 8px;border-radius:999px;background:rgba(46,52,64,.82);color:#eceff4}
.fb-cal-wrap{margin-top:16px;overflow:hidden}
.fb-cal-head{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:12px 14px;background:var(--fb-panel);border-bottom:1px solid var(--fb-border)}
.fb-cal-title{font-size:14px;font-weight:800;color:var(--fb-text)}
.fb-cal-tools{display:flex;gap:8px;align-items:center;color:var(--fb-muted)}
.fb-cal-tools label{font-size:12px;font-weight:700}
.fb-select{background:var(--fb-surface);color:var(--fb-text);border:1px solid var(--fb-border);border-radius:9px;padding:8px 10px;font-family:inherit;font-size:12px;font-weight:600;line-height:1.2}
.fb-empty-state,.fb-empty-calendar{padding:14px 16px;border:1px solid var(--fb-border);border-radius:10px;background:var(--fb-panel);color:var(--fb-text);font-family:inherit;font-size:13px;font-weight:600;line-height:1.5}
.fb-empty-state{margin:12px 0}
.fb-empty-state p{margin:4px 0 10px;color:var(--fb-muted)}
.fb-empty-link{display:inline-flex;align-items:center;border-radius:9px;padding:8px 12px;background:var(--fb-accent);color:var(--fb-on-accent);font-weight:700;text-decoration:none;transition:background .15s ease}
.fb-empty-link:hover{background:var(--fb-accent-hover);color:#fff}
#facility-calendar{padding:12px;color:var(--fb-text)}
#facility-calendar .fc{color:var(--fb-text);font-family:inherit}
#facility-calendar .fc-theme-standard .fc-scrollgrid,#facility-calendar .fc-theme-standard td,#facility-calendar .fc-theme-standard th{border-color:var(--fb-border)}
#facility-calendar .fc-theme-standard .fc-scrollgrid{background:var(--fb-surface)}
#facility-calendar .fc-col-header-cell{background:var(--fb-panel)}
#facility-calendar .fc-col-header-cell-cushion,#facility-calendar .fc-daygrid-day-number,#facility-calendar .fc-list-day-text,#facility-calendar .fc-list-day-side-text{color:var(--fb-text);text-decoration:none}
#facility-calendar .fc-daygrid-day,#facility-calendar .fc-timegrid-col{background:var(--fb-surface)}
#facility-calendar .fc-day-other .fc-daygrid-day-number{color:var(--fb-muted);opacity:.65}
#facility-calendar .fc-day-today{background:color-mix(in srgb,var(--fb-accent) 12%,var(--fb-surface))!important}
#facility-calendar .fc-toolbar{gap:10px;flex-wrap:wrap}
#facility-calendar .fc-toolbar-title{color:var(--fb-text);font-size:1.35rem;font-weight:700}
#facility-calendar .fc-button-primary{background:var(--fb-accent);border-color:var(--fb-accent);color:#fff;border-radius:8px;box-shadow:none}
#facility-calendar .fc-button-primary:hover,#facility-calendar .fc-button-primary:focus{background:var(--fb-accent-hover);border-color:var(--fb-accent-hover)}
#facility-calendar .fc-button-primary:disabled{background:var(--fb-soft);border-color:var(--fb-border);color:var(--fb-muted);opacity:1}
#facility-calendar .fc-button-primary:not(:disabled).fc-button-active,#facility-calendar .fc-button-primary:not(:disabled):active{background:var(--fb-panel);border-color:var(--fb-border);color:var(--fb-text)}
#facility-calendar .fc-list{border-color:var(--fb-border)}
#facility-calendar .fc-list-day-cushion{background:var(--fb-panel)}
#facility-calendar .fc-list-event:hover td{background:var(--fb-soft)}
.fc-modal-backdrop.fb-theme{position:fixed;inset:0;background:rgba(10,14,20,.72);display:none;align-items:center;justify-content:center;z-index:100;padding:1rem}
.fc-modal{width:100%;max-width:520px;background:var(--fb-surface);color:var(--fb-text);border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;border:1px solid var(--fb-border)}
.fc-modal-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--fb-border);background:var(--fb-panel)}
.fc-modal-title{font-size:14px;font-weight:800;color:var(--fb-text)}
.fc-modal-body{padding:14px 16px;font-family:inherit;font-size:13px;font-weight:600;line-height:1.5;color:var(--fb-text)}
.fc-close{background:transparent;border:0;border-radius:8px;font-size:16px;cursor:pointer;color:var(--fb-muted);padding:4px 8px}
.fc-close:hover{background:var(--fb-soft);color:var(--fb-text)}
.fc-row{display:grid;grid-template-columns:120px 1fr;gap:8px;margin-bottom:8px}
.fc-row b{color:var(--fb-muted)}
.rb-radio-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.rb-radio{display:flex;gap:8px;align-items:center;border:1px solid var(--fb-border);border-radius:10px;padding:9px 10px;background:var(--fb-panel)}
.rb-modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}
.rb-btn-primary,.rb-btn-secondary{border:0;border-radius:9px;padding:9px 12px;font-family:inherit;font-size:12px;font-weight:700;line-height:1.2;cursor:pointer}
.rb-btn-primary{background:var(--fb-accent);color:var(--fb-on-accent)}
.rb-btn-primary:hover{background:var(--fb-accent-hover)}
.rb-btn-secondary{background:var(--fb-soft);color:var(--fb-text)}
.rb-btn-secondary:hover{filter:brightness(.95)}
@media(max-width:640px){.fb-cal-head{align-items:flex-start;flex-direction:column}.fb-cal-tools{width:100%;justify-content:space-between}.fb-cal-tools select{max-width:65%}#facility-calendar{padding:8px}#facility-calendar .fc-toolbar-title{font-size:1.1rem}.rb-radio-grid{grid-template-columns:1fr}}
</style>

{{-- FullCalendar CSS/JS --}}
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.22/index.global.min.js"></script>

<div class="fb-layout fb-theme">
    {{-- ===== PANEL KIRI ===== --}}
    <aside class="fb-panel">
        <div class="fb-title">Facility Status</div>
        <div class="fb-legend">
            <div class="fb-leg"><span class="fb-dot" style="background:#8f6aad"></span>In Use <span class="fb-count">{{ $stats['in_use'] ?? 0 }}</span></div>
            <div class="fb-leg"><span class="fb-dot" style="background:#ebcb8b"></span>Inspection <span class="fb-count">{{ $stats['inspection'] ?? 0 }}</span></div>
            <div class="fb-leg"><span class="fb-dot" style="background:#81a1c1"></span>Dirty <span class="fb-count">{{ $stats['dirty'] ?? 0 }}</span></div>
            <div class="fb-leg"><span class="fb-dot" style="background:#616e88"></span>Blocked <span class="fb-count">{{ $stats['blocked'] ?? 0 }}</span></div>
            <div class="fb-leg"><span class="fb-dot" style="background:#5e81ac"></span>Ready <span class="fb-count">{{ $stats['ready'] ?? 0 }}</span></div>
        </div>
        <div class="mt-3" style="display:flex;justify-content:space-between;font-weight:900;">
            <span>TOTAL FACILITY</span>
            <span>{{ $stats['total'] ?? 0 }}</span>
        </div>
    </aside>

    {{-- ===== GRID + CALENDAR ===== --}}
    <main>
        <div class="fb-board-title">Facilities — Live Status</div>

        @if ($facilities->isEmpty())
            <div class="fb-empty-state" role="status">
                <strong>Belum ada fasilitas.</strong>
                <p>Tambahkan fasilitas agar status dan jadwal pemesanan tampil di halaman ini.</p>
                <a class="fb-empty-link" href="{{ \App\Filament\Resources\Facilities\FacilityResource::getUrl('create') }}">Tambah fasilitas</a>
            </div>
        @endif

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
                    <label for="facility-filter">Facility:</label>
                    <select id="facility-filter" class="fb-select">
                        <option value="">All Facilities</option>
                        @foreach($facilities as $f)
                            <option value="{{ (int)$f->id }}">{{ $nameMap[$f->id] ?? $f->name ?? ('#'.$f->id) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if (count($calendarEvents) === 0)
                <div class="fb-empty-calendar" role="status">Belum ada booking atau blok fasilitas dalam rentang kalender ini.</div>
            @endif
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

    <div id="{{ $modalId }}" class="fc-modal-backdrop fb-theme hidden" data-modal-backdrop wire:ignore>
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
<div id="fcModal" class="fc-modal-backdrop fb-theme hidden" wire:ignore>
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
            case 'PAID':         return '#8f6aad'; // in_use
            case 'INSPECTION':
            case 'VCI':          return '#ebcb8b'; // inspection
            case 'BLOCKED':
            case 'OOO':
            case 'MAINTENANCE':  return '#616e88'; // blocked
            case 'DIRTY':        return '#81a1c1'; // dirty
            default:             return '#5e81ac'; // ready/other
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
                textColor: ['INSPECTION', 'VCI', 'DIRTY'].includes((ev.status || '').toUpperCase()) ? '#2e3440' : '#fff',
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
