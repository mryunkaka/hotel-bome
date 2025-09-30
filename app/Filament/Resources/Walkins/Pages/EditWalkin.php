<?php

namespace App\Filament\Resources\Walkins\Pages;

use App\Models\Room;
use Illuminate\Support\Carbon;
use App\Models\ReservationGuest;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Filament\Actions\RestoreAction;
use Illuminate\Support\Facades\Auth;
use Filament\Actions\ForceDeleteAction;
use Illuminate\Support\Facades\Session;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\Action as FormAction;
use Illuminate\Validation\ValidationException;
use App\Filament\Resources\Walkins\WalkinResource;
use App\Filament\Resources\ReservationGuests\ReservationGuestResource;

class EditWalkin extends EditRecord
{
    protected static string $resource = WalkinResource::class;

    /** Menyimpan state form terakhir yang akan dipakai saat upsert RG di afterSave(). */
    protected array $lastSavedFormData = [];

    /** Saat true, afterSave tidak akan redirect (dipakai ketika check-in). */
    protected bool $skipPostSaveRedirect = false;

    public function getHeading(): string
    {
        $no = $this->getRecord()->reservation_no;
        return $no ? ('Walk-in. No : ' . $no) : 'Edit Walk-in';
    }

    /**
     * Setelah disimpan:
     * - upsert satu ReservationGuest dari field yang ada di form Walk-in
     * - sinkron expected_checkin/checkout RG dari header
     * - reload halaman (hanya untuk Save biasa) agar tombol berubah jadi "Check In Guest Now"
     */
    protected function afterSave(): void
    {
        $reservation = $this->record->fresh();

        // Pastikan ada 1 RG (buat/update) dari header Walk-in
        $this->upsertSingleReservationGuestFromHeader($reservation->id);

        // (opsional) set ulang expected_checkin/checkout di RG dari header
        $this->syncRgDatesFromHeader($reservation->id);

        // Jika ini Save biasa (bukan dari tombol Check In) → reload halaman edit
        if (! $this->skipPostSaveRedirect) {
            $this->redirect(
                WalkinResource::getUrl('edit', ['record' => $reservation]),
                navigate: true
            );
        }
    }

    /**
     * Bersihkan / set data sebelum save.
     * - set hotel_id & created_by
     * - hitung expected_departure dari expected_arrival + nights
     * - buang field-field preview (pv_*) dari payload
     * - simpan payload ke $this->lastSavedFormData untuk dipakai afterSave()
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Hotel & creator
        $data['created_by'] = $data['created_by'] ?? Auth::id();
        $data['hotel_id']   = $data['hotel_id'] ?? Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

        // Validasi minimal
        if (empty($data['guest_id'])) {
            throw ValidationException::withMessages([
                'guest_id' => 'Silakan pilih Guest.',
            ]);
        }

        // expected_departure dari nights
        if (!empty($data['expected_arrival']) && !empty($data['nights'])) {
            $arrival = Carbon::parse($data['expected_arrival']);
            $nights  = max(1, (int) $data['nights']);
            $data['expected_departure'] = $arrival->copy()->startOfDay()->addDays($nights)->setTime(12, 0);
        }

        // Buang field preview / UI-only agar tidak ikut dehydrated
        unset(
            $data['pv_address'],
            $data['pv_guest_type'],
            $data['pv_city'],
            $data['pv_country'],
            $data['pv_nationality'],
            $data['pv_profession'],
            $data['pv_id_type'],
            $data['pv_id_card'],
            $data['pv_birth_place'],
            $data['pv_birth_date'],
            $data['pv_issued_place'],
            $data['pv_issued_date'],
            $data['pv_group_phone'],
            $data['pv_group_email'],
            // preview room
            $data['pv_room_type'],
            $data['pv_room_status'],
        );

        // Simpan state terakhir untuk dipakai di afterSave()
        $this->lastSavedFormData = $data;

        return $data;
    }

    /**
     * Isi nights untuk UI saat edit (jika kosong).
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (empty($data['nights'])) {
            if (!empty($data['expected_arrival']) && !empty($data['expected_departure'])) {
                $nights = Carbon::parse($data['expected_arrival'])
                    ->startOfDay()
                    ->diffInDays(Carbon::parse($data['expected_departure'])->startOfDay());
                $data['nights'] = max(1, $nights);
            } else {
                $data['nights'] = 1;
            }
        }

        $rg = $this->record->reservationGuests()->orderBy('id')->first();

        if ($rg) {
            // hanya jika header kosong
            $data['pov']          = $data['pov']          ?? $rg->pov;
            $data['person']       = $data['person']       ?? ($rg->person ?? $rg->charge_to);
            // tampil cepat di walkin:
            $data['room_id']      = $data['room_id']      ?? $rg->room_id;
            $data['room_rate']    = $data['room_rate']    ?? (int) ($rg->room_rate ?? 0);
            $data['breakfast']    = $data['breakfast']    ?? $rg->breakfast;
            $data['male']         = $data['male']         ?? $rg->male;
            $data['female']       = $data['female']       ?? $rg->female;
            $data['children']     = $data['children']     ?? $rg->children;
            $data['jumlah_orang'] = $data['jumlah_orang'] ?? $rg->jumlah_orang;
            $data['extra_bed']    = $data['extra_bed']    ?? $rg->extra_bed;
            $data['note']         = $data['note']         ?? $rg->note;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * Tampilkan tombol “Check In Guest Now”.
     */
    protected function getFormActions(): array
    {
        // Ambil ReservationGuest aktif
        $activeRg = $this->record
            ->reservationGuests()
            ->whereNull('actual_checkout')
            ->latest('id')
            ->first();

        $guestId = $activeRg?->guest_id;
        $roomId  = $activeRg?->room_id;

        // Kondisi
        $hasGuestAndRoom = filled($guestId) && filled($roomId);

        // Tombol Save → hanya muncul jika belum lengkap
        $save = $this->getSaveFormAction()
            ->label('Save changes')
            ->icon('heroicon-o-check')
            ->color('primary')
            ->visible(fn() => ! $hasGuestAndRoom);

        // Tombol Check-in → hanya muncul jika RG aktif & guest_id & room_id lengkap
        $checkIn = FormAction::make('checkInGuestNow')
            ->label('Check In Guest Now')
            ->icon('heroicon-o-key')
            ->color('success')
            ->visible(fn() => $hasGuestAndRoom)
            ->action('checkInGuestNow');

        return [
            $save,
            $checkIn,
            $this->getCancelFormAction(),
        ];
    }

    /**
     * Dipanggil saat klik tombol Check In Guest Now.
     * 1) Simpan form (memicu afterSave → upsert RG)
     * 2) Proses check-in & redirect/print
     */
    public function checkInGuestNow(): void
    {
        // Hindari redirect otomatis di afterSave saat check-in
        $this->skipPostSaveRedirect = true;

        // Simpan dulu supaya RG pasti ter-upsert
        $this->save();

        // Lanjut proses check-in
        $this->doCheckInAndRedirect();

        // Reset flag (opsional)
        $this->skipPostSaveRedirect = false;
    }

    /**
     * Proses check-in RG aktif setelah form tersimpan.
     */
    private function doCheckInAndRedirect(): void
    {
        // Pastikan form sudah tersimpan & RG di-upsert oleh afterSave()
        $this->record->refresh();

        [$reservation, $rg] = DB::transaction(function () {
            // Kunci header
            $reservation = \App\Models\Reservation::query()
                ->whereKey($this->record->getKey())
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                \Filament\Notifications\Notification::make()
                    ->title('Reservation tidak ditemukan')
                    ->danger()
                    ->send();
                return [null, null];
            }

            // Ambil RG aktif & kunci
            $rg = ReservationGuest::query()
                ->where('reservation_id', $reservation->id)
                ->whereNull('actual_checkout')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $rg) {
                \Filament\Notifications\Notification::make()
                    ->title('Tidak ada guest untuk di-check-in')
                    ->danger()
                    ->send();
                return [null, null];
            }

            // Update status kamar → OCC
            if ($rg->room_id) {
                Room::whereKey($rg->room_id)->update([
                    'status'            => Room::ST_OCC,
                    'status_changed_at' => now(),
                ]);
            }

            // Konversi DP kamar → deposit card (default)
            $reservation->forceFill([
                'deposit_card' => (int) $reservation->deposit_card + (int) $reservation->deposit_room,
                'deposit_room' => 0,
            ])->save();

            // Set waktu check-in
            if (blank($rg->actual_checkin)) {
                $now = now();

                $rg->actual_checkin = $now;
                $rg->save();

                if (blank($reservation->checkin_date)) {
                    $reservation->checkin_date = $now;
                    $reservation->save();
                }

                \Filament\Notifications\Notification::make()
                    ->title('Checked-in')
                    ->body('ReservationGuest #' . $rg->id . ' pada ' . $now->format('d/m/Y H:i'))
                    ->success()
                    ->send();
            } else {
                \Filament\Notifications\Notification::make()
                    ->title('Sudah check-in')
                    ->info()
                    ->send();
            }

            return [$reservation, $rg];
        });

        if (! $rg) {
            return; // sudah tampilkan notifikasi
        }

        // Buka print RG di tab baru
        $printUrl = route('reservation-guests.print', ['guest' => $rg->getKey()]);
        $this->js("window.open('{$printUrl}', '_blank', 'noopener,noreferrer');");

        // Redirect ke index RG (opsional)
        $url = ReservationGuestResource::getUrl('index');
        $this->redirect($url, navigate: true);
    }

    /**
     * Upsert satu ReservationGuest dari header Walk-in.
     * Mengambil field-field yang ada di form dari $this->lastSavedFormData (fallback $this->data).
     */
    private function upsertSingleReservationGuestFromHeader(int $reservationId): void
    {
        $res  = $this->record->fresh();

        // Ambil state form terakhir yang sudah dimutasi & disave
        $form = $this->lastSavedFormData ?: (is_array($this->data ?? null) ? $this->data : []);

        $roomId = data_get($form, 'room_id');
        if (empty($roomId)) return;

        $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

        // hanya ambil RG yang BELUM actual_checkin & BELUM checkout
        $rg = ReservationGuest::query()
            ->where('reservation_id', $reservationId)
            ->whereNull('actual_checkin')
            ->whereNull('actual_checkout')
            ->orderBy('id')
            ->first();

        // Normalisasi room_rate dari form: "250,000" / "250.000" -> 250000
        $roomRateRaw = data_get($form, 'room_rate');
        if (is_string($roomRateRaw)) {
            $roomRateSanitized = preg_replace('/[^\d]/', '', $roomRateRaw);
            $roomRate = (int) ($roomRateSanitized === '' ? 0 : $roomRateSanitized);
        } else {
            $roomRate = (int) ($roomRateRaw ?? 0);
        }
        if ($roomRate <= 0) {
            $roomRate = (int) (Room::whereKey($roomId)->value('price') ?? 0);
        }

        $payload = [
            'hotel_id'          => $hid,
            'reservation_id'    => $reservationId,
            'guest_id'          => data_get($form, 'guest_id'),
            'room_id'           => $roomId,
            'room_rate'         => $roomRate,
            'male'              => (int) (data_get($form, 'male') ?? 1),
            'female'            => (int) (data_get($form, 'female') ?? 0),
            'children'          => (int) (data_get($form, 'children') ?? 0),
            'jumlah_orang'      => (int) (data_get($form, 'jumlah_orang') ?? 1),
            'breakfast'         => data_get($form, 'breakfast') ?? 'Yes',
            'discount_percent'  => (float) (data_get($form, 'discount_percent') ?? 0),
            'extra_bed'         => (int) (data_get($form, 'extra_bed') ?? 0),
            'note'              => data_get($form, 'note'),
            'pov'               => data_get($form, 'pov') ?? $res->pov ?? 'BUSINESS',
            'person'            => data_get($form, 'person') ?? $res->person ?? 'PERSONAL ACCOUNT',
            'charge_to'         => data_get($form, 'person') ?? $res->person ?? 'PERSONAL ACCOUNT',
            'expected_checkin'  => $res->expected_arrival   ? Carbon::parse($res->expected_arrival)   : null,
            'expected_checkout' => $res->expected_departure ? Carbon::parse($res->expected_departure) : null,
        ];

        if ($rg) {
            // HANYA update kolom2 aman; TIDAK menyentuh actual_checkin
            $rg->fill($payload)->save();
        } else {
            ReservationGuest::create($payload);
        }
    }

    /**
     * Sinkronkan expected_checkin / expected_checkout RG dengan header
     * (untuk baris yang BELUM actual_checkin).
     */
    private function syncRgDatesFromHeader(int $reservationId): void
    {
        $res = $this->record->fresh();
        $payload = [];

        if ($res->expected_arrival) {
            $payload['expected_checkin'] = Carbon::parse($res->expected_arrival);
        }
        if ($res->expected_departure) {
            $payload['expected_checkout'] = Carbon::parse($res->expected_departure);
        }

        if (!empty($payload)) {
            ReservationGuest::query()
                ->where('reservation_id', $reservationId)
                ->whereNull('actual_checkin')
                ->update($payload);
        }
    }
}
