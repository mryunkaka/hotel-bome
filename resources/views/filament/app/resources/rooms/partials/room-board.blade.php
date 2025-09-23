@php
    use App\Models\Room;

    $mapCodeToLabel = fn(string $code) => match ($code) {
        Room::ST_OCC => 'occupied',
        Room::ST_LS  => 'long_stay',   // TAMBAH
        Room::ST_RS  => 'reserved',    // TAMBAH
        Room::ST_ED  => 'exp_dep',
        Room::ST_VC  => 'vacant_clean',
        Room::ST_VD  => 'vacant_dirty',
        Room::ST_VCI => 'inspection', // VCI
        Room::ST_HU  => 'house_use',
        Room::ST_OOO => 'oo',
        default       => 'vacant_clean',
    };

    $rightBadge = fn(string $code) => match ($code) {
        Room::ST_OCC => 'OCC',
        Room::ST_LS  => 'LS',   // TAMBAH
        Room::ST_RS  => 'RS',   // TAMBAH
        Room::ST_ED  => 'ED',
        Room::ST_VCI => 'VCI',
        default       => '',
    };
@endphp

<style>
/* ===== Layout dasar: kiri sempit, kanan fleksibel ===== */
.rb-layout{display:grid;gap:1rem}
@media (min-width:1024px){.rb-layout{grid-template-columns:260px 1fr}}
/* ===== Kartu tile ===== */
.rb-card{border-radius:12px;overflow:hidden;background:#fff;border:1px solid #e5e7eb;box-shadow:0 1px 2px rgba(0,0,0,.06)}
.rb-head{background:#f3f4f6;padding:6px 10px;font:700 10px/1 system-ui,Arial;display:flex;justify-content:space-between;align-items:center;letter-spacing:.2px}
.rb-badge{font-size:10px;font-weight:800;padding:2px 6px;border-radius:4px;background:rgba(0,0,0,.15);color:#111}
.rb-badge.muted{opacity:.35}
.rb-tile{height:140px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;position:relative}
.rb-num{font-size:40px;font-weight:900;line-height:1;letter-spacing:.4px;text-shadow:0 1px 0 rgba(0,0,0,.15)}
.rb-guest{margin-top:6px;font-size:11px;text-transform:uppercase;font-weight:800;letter-spacing:.3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:100%;padding:0 8px}
/* ===== Warna status (VC ≠ VCI) ===== */
.tile--vc   {background:#1E88E5; color:#fff;}        /* Vacant Clean → biru solid */
.tile--vci  {background:#FDE047; color:#111;}        /* VC Inspection → kuning terang */
.tile--occ  {background:#6C2AB2; color:#fff;}        /* Occupied → ungu */
.tile--ls   {background:#EF4444; color:#fff;}        /* Long Stay → merah */          /* TAMBAH */
.tile--rs   {background:#0D9488; color:#fff;}        /* Reserved → teal */            /* TAMBAH */
.tile--ed   {background:#22C55E; color:#fff;}        /* Exp Dep → hijau */
.tile--vd   {background:#93C5FD; color:#111;}        /* Vacant Dirty → biru muda */
.tile--hu   {background:#F59E0B; color:#111;}        /* House Use → oranye */
.tile--oo   {background:#6B7280; color:#fff;}        /* Out of Order → abu tua */
.rb-tile a,.tile-link{display:block;width:100%;height:100%;background:transparent;border:none;cursor:pointer}
/* ===== Panel kiri ===== */
.rb-panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;box-shadow:0 1px 2px rgba(0,0,0,.06)}
.rb-title{font:800 12px/1.2 system-ui,Arial;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px}
.rb-legend{display:grid;grid-template-columns:1fr;gap:6px}
@media (min-width:480px){.rb-legend{grid-template-columns:1fr}}
.rb-leg{display:flex;align-items:center;gap:8px;font:700 11px/1.2 system-ui,Arial;color:#111;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:6px 8px}
.rb-dot{width:14px;height:14px;border-radius:3px;border:1px solid rgba(0,0,0,.15)}
.rb-count{margin-left:auto;background:#111;color:#fff;font-weight:800;font-size:10px;border-radius:6px;padding:2px 8px}
/* ===== Header papan ===== */
.rb-board-title{font:900 14px/1.2 system-ui,Arial;text-align:center;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:8px;margin-bottom:10px}
/* ===== Grid kamar ===== */
/* ===== Grid kamar: kunci 5 kolom di desktop ===== */
.rb-grid{
  display:grid;
  gap:.9rem; /* sedikit diperlebar */
  grid-template-columns:repeat(2,minmax(0,1fr));
}
@media (min-width:640px){  /* sm */
  .rb-grid{grid-template-columns:repeat(3,minmax(0,1fr));}
}
@media (min-width:768px){  /* md */
  .rb-grid{grid-template-columns:repeat(4,minmax(0,1fr));}
}
@media (min-width:1024px){ /* lg */
  .rb-grid{grid-template-columns:repeat(5,minmax(0,1fr));}
}
/* KUNCI tetap 5 kolom di resolusi lebih besar */
@media (min-width:1280px){
  .rb-grid{grid-template-columns:repeat(5,minmax(0,1fr));}
}

/* Hover & focus state */
.rb-card:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(2,6,23,.08);transition:.15s}
.rb-tile .cta{opacity:0;position:absolute;inset:auto 8px 8px auto;background:rgba(255,255,255,.2);backdrop-filter:blur(2px);color:#111;border-radius:6px;padding:2px 6px;font:800 10px/1 system-ui}
.rb-card:hover .cta{opacity:1}
/* Kode status di tengah tile */
.rb-code{margin-top:6px;font:900 12px/1 system-ui,Arial;letter-spacing:.6px;padding:2px 8px;border-radius:999px;background:rgba(255,255,255,.85);color:#111;box-shadow:0 1px 2px rgba(0,0,0,.2)}
/* ===== Modal ===== */
[x-cloak]{display:none!important;}
.rb-modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.45);display:flex;align-items:center;justify-content:center;z-index:70;padding:1rem}
.rb-modal{width:100%;max-width:560px;background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;border:1px solid #e5e7eb}
.rb-modal-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #e5e7eb;background:#f8fafc}
.rb-modal-title{font:800 14px/1.2 system-ui,Arial;color:#0f172a}
.rb-close{background:transparent;border:none;font-size:16px;cursor:pointer;color:#334155}
.rb-modal-body{padding:14px 16px}
.rb-radio-grid{display:grid;grid-template-columns:1fr;gap:8px}
@media(min-width:480px){.rb-radio-grid{grid-template-columns:1fr 1fr}}
.rb-radio{display:flex;align-items:center;gap:8px;border:1px solid #e5e7eb;border-radius:10px;padding:8px 10px;background:#fff}
.rb-radio:hover{background:#f8fafc}
.rb-modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
.rb-btn-primary{background:#0ea5e9;color:#fff;border:none;border-radius:10px;padding:8px 12px;font:800 12px/1 system-ui;cursor:pointer}
.rb-btn-primary:hover{background:#0284c7}
.rb-btn-secondary{background:#e5e7eb;color:#111;border:none;border-radius:10px;padding:8px 12px;font:800 12px/1 system-ui;cursor:pointer}
</style>

<div class="rb-layout">
    {{-- ===================== KIRI: panel status ===================== --}}
    <aside class="rb-panel">
        <div class="rb-title">Room Status</div>

        <div class="rb-legend">
            <div class="rb-leg"><span class="rb-dot" style="background:#6C2AB2"></span> Occupied <span class="rb-count">{{ $stats['occupied'] ?? 0 }}</span></div>
            <div class="rb-leg"><span class="rb-dot" style="background:#EF4444"></span> Long Stay <span class="rb-count">{{ $stats['long_stay'] ?? 0 }}</span></div> <!-- PINDAH NAIK biar dekat OCC -->
            <div class="rb-leg"><span class="rb-dot" style="background:#0D9488"></span> Reserved <span class="rb-count">{{ $stats['reserved'] ?? 0 }}</span></div>    <!-- TAMBAH -->
            <div class="rb-leg"><span class="rb-dot" style="background:#22C55E"></span> Exp Departure <span class="rb-count">{{ $stats['exp_dep'] ?? 0 }}</span></div>
            <div class="rb-leg"><span class="rb-dot" style="background:#1E88E5"></span> Vacant Clean <span class="rb-count">{{ $stats['vacant_clean'] ?? 0 }}</span></div>
            <div class="rb-leg"><span class="rb-dot" style="background:#FDE047"></span> VC Inspection <span class="rb-count">{{ $stats['inspection'] ?? 0 }}</span></div>
            <div class="rb-leg"><span class="rb-dot" style="background:#93C5FD"></span> Vacant Dirty <span class="rb-count">{{ $stats['vacant_dirty'] ?? 0 }}</span></div>
            <div class="rb-leg"><span class="rb-dot" style="background:#F59E0B"></span> House Use <span class="rb-count">{{ $stats['house_use'] ?? 0 }}</span></div>
            <div class="rb-leg"><span class="rb-dot" style="background:#6B7280"></span> Out of Order <span class="rb-count">{{ $stats['oo'] ?? 0 }}</span></div>
        </div>

        <div class="mt-3 rb-btn" style="justify-content:space-between;">
            <span style="font-weight:900">TOTAL ROOM</span>
            <span style="font-weight:900">{{ $total }}</span>
        </div>
    </aside>

    {{-- ===================== KANAN: grid kamar ===================== --}}
    <main>
        <div class="rb-grid">
            @forelse ($rooms as $room)
                @php
                    $code   = (string) ($room->status ?? '');
                    $label  = $mapCodeToLabel($code);
                    $tile   = match ($label) {
                        'occupied'     => 'tile--occ',
                        'long_stay'    => 'tile--ls',   // TAMBAH
                        'reserved'     => 'tile--rs',   // TAMBAH
                        'exp_dep'      => 'tile--ed',
                        'vacant_clean' => 'tile--vc',
                        'inspection'   => 'tile--vci',
                        'vacant_dirty' => 'tile--vd',
                        'house_use'    => 'tile--hu',
                        'oo'           => 'tile--oo',
                        default        => 'tile--vc',
                    };
                    $badge  = $rightBadge($code);
                    $muted  = $badge === '' ? 'muted' : '';
                    $type   = strtoupper((string) ($room->type ?? ''));
                    $roomNo = $room->room_no ?? $room->id;

                    // ===================== FIX: isi $editUrl tanpa bergantung pada class RoomResource =====================
                    $editUrl = null;
                    $candidates = [
                        \App\Filament\Resources\RoomResource::class,
                        \App\Filament\Resources\Rooms\RoomResource::class,
                        \App\Filament\Resources\KamarResource::class,
                    ];
                    foreach ($candidates as $cls) {
                        if (class_exists($cls)) {
                            $editUrl = $cls::getUrl('edit', ['record' => $room]);
                            break;
                        }
                    }
                    if (! $editUrl) {
                        try {
                            $editUrl = route('filament.admin.resources.rooms.edit', ['record' => $room]);
                        } catch (\Throwable $e) {
                            $editUrl = '#';
                        }
                    }
                    // ================================================================================================
                @endphp

                <div class="rb-card group" x-data="{ open: false }" wire:key="room-tile-{{ $room->id }}">
                    <div class="rb-head">
                        <span>{{ $type }}</span>
                        <span class="rb-badge {{ $muted }}">{{ $badge ?: ' ' }}</span>
                    </div>

                    <div class="rb-tile {{ $tile }}">
                        <button type="button" class="tile-link" @click="open = true" aria-label="Open room {{ $roomNo }}">
                            <div class="rb-num">{{ $roomNo }}</div>
                            {{-- <div class="rb-code">{{ $code }}</div> --}}
                            <span class="cta">Open</span>
                        </button>
                    </div>

                    {{-- ========= Modal (teleport ke body agar tidak ikut re-render) ========= --}}
                    <template x-teleport="body">
                        <div
                            x-show="open"
                            x-cloak
                            wire:ignore
                            class="rb-modal-backdrop"
                            @keydown.escape.window="open = false"
                            @click.self.stop="open = false"
                            x-transition.opacity
                        >
                            <div class="rb-modal" x-transition.scale.origin.center>
                                <div class="rb-modal-head">
                                    <div class="rb-modal-title">Update Status — Room {{ $roomNo }}</div>
                                    <button type="button" class="rb-close" @click="open = false">✕</button>
                                </div>

                                <form method="POST" action="{{ route('rooms.quick-status', $room) }}" class="rb-modal-body">
                                    @csrf
                                    @method('PATCH')

                                    <div class="rb-radio-grid">
                                        @php
                                            $opts = [
                                                \App\Models\Room::ST_OCC => 'Occupied (OCC)',
                                                \App\Models\Room::ST_LS  => 'Long Stay (LS)',        // TAMBAH
                                                \App\Models\Room::ST_RS  => 'Reserved (RS)',         // TAMBAH
                                                \App\Models\Room::ST_ED  => 'Expected Departure (ED)',
                                                \App\Models\Room::ST_VC  => 'Vacant Clean (VC)',
                                                \App\Models\Room::ST_VCI => 'VC Inspection (VCI)',
                                                \App\Models\Room::ST_VD  => 'Vacant Dirty (VD)',
                                                \App\Models\Room::ST_HU  => 'House Use (HU)',
                                                \App\Models\Room::ST_OOO => 'Out of Order (OOO)',
                                            ];
                                        @endphp

                                        @foreach ($opts as $val => $text)
                                            <label class="rb-radio">
                                                <input type="radio" name="status" value="{{ $val }}" {{ $code === $val ? 'checked' : '' }}>
                                                <span>{{ $text }}</span>
                                            </label>
                                        @endforeach
                                    </div>

                                    <div class="rb-modal-actions">
                                        <button type="submit" class="rb-btn-primary" @click="open = false">Save</button>
                                        <button type="button" class="rb-btn-secondary" @click="open = false">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </template>
                    {{-- ========= /Modal ========= --}}
                </div>
            @empty
                <div class="col-span-full text-sm text-gray-500">Belum ada data kamar.</div>
            @endforelse
        </div>
    </main>
</div>
