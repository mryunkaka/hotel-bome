<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Models\Room;
use Filament\Actions\Action;
use Illuminate\Support\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Illuminate\Support\Facades\Auth;
use Filament\Actions\ForceDeleteAction;
use Illuminate\Support\Facades\Session;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use App\Filament\Resources\Reservations\ReservationResource;

class EditReservation extends EditRecord
{
    protected static string $resource = ReservationResource::class;

    // Heading halaman
    public function getHeading(): string
    {
        $no = $this->getRecord()->reservation_no;
        return $no ? ('Resv. No : ' . $no) : 'Edit reservation';
    }

    protected function afterSave(): void
    {
        $this->purgeGuestsIfRepeaterEmpty();
    }

    private function purgeGuestsIfRepeaterEmpty(): void
    {
        // Ambil state Repeater dari form
        $items = data_get($this->data, 'reservationGuests', []);

        // Jika null / [] / hanya berisi elemen kosong -> anggap kosong
        $isEmpty = blank($items) || collect($items)->filter()->isEmpty();

        if ($isEmpty) {
            // Soft delete SEMUA anak (karena model ReservationGuest pakai SoftDeletes)
            $this->record->reservationGuests()->delete();

            // (Opsional) kalau mau HAPUS PERMANEN:
            // $this->record->reservationGuests()->forceDelete();
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Entry by
        $data['created_by'] = $data['created_by'] ?? Auth::id();

        // Hitung expected_departure dari arrival + nights (UI-only)
        if (! empty($data['expected_arrival']) && ! empty($data['nights'])) {
            $data['expected_departure'] = Carbon::parse($data['expected_arrival'])
                ->startOfDay()
                ->addDays(max(1, (int) $data['nights']))
                ->setTime(12, 0);
        }

        // Reserved By: sekarang hanya pakai guest_id / group_id
        $type = $data['reserved_by_type'] ?? 'GUEST';

        if ($type === 'GUEST') {
            if (empty($data['guest_id'])) {
                throw ValidationException::withMessages([
                    'guest_id' => 'Silakan pilih Guest.',
                ]);
            }
            // pastikan tidak tercampur
            $data['group_id'] = null;
        } else { // GROUP
            if (empty($data['group_id'])) {
                throw ValidationException::withMessages([
                    'group_id' => 'Silakan pilih Group.',
                ]);
            }
            $data['guest_id'] = null;
        }

        // ========= Referensi header untuk periode per-guest =========
        $headerIn  = !empty($data['expected_arrival'])
            ? Carbon::parse($data['expected_arrival'])->setTime(12, 0)
            : null;

        $headerOut = !empty($data['expected_departure'])
            ? Carbon::parse($data['expected_departure'])->setTime(12, 0)
            : ($headerIn ? $headerIn->copy()->addDay()->setTime(12, 0) : null);

        // === Sinkron data repeater reservationGuests (tanpa live/hook) ===
        if (! empty($data['reservationGuests']) && is_array($data['reservationGuests'])) {
            $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

            foreach ($data['reservationGuests'] as &$row) {
                // hotel_id default
                $row['hotel_id'] = $row['hotel_id'] ?? $hid;

                // ================== Pax (male + female + children) ==================
                // Perbaikan: sanitasi angka, tidak bergantung reactive/afterUpdate.
                // Jika field tidak ditampilkan (mis. hanya 'male'), yang lain dianggap 0.
                $male     = (int) preg_replace('/\D+/', '', (string) ($row['male']     ?? '0'));
                $female   = (int) preg_replace('/\D+/', '', (string) ($row['female']   ?? '0'));
                $children = (int) preg_replace('/\D+/', '', (string) ($row['children'] ?? '0'));

                $sum = $male + $female + $children;
                $row['jumlah_orang'] = max(1, $sum);
                // ====================================================================

                // ================== Periode per-guest via MUTATE ==================
                // Override dari header (sesuai permintaan: masuk mutate saja)
                if ($headerIn) {
                    $row['expected_checkin'] = $headerIn->copy();
                } else {
                    $row['expected_checkin'] = now()->setTime(12, 0);
                }

                if ($headerOut) {
                    $row['expected_checkout'] = $headerOut->copy();
                } else {
                    $row['expected_checkout'] = Carbon::parse($row['expected_checkin'])->addDay()->setTime(12, 0);
                }

                // Pastikan checkout > checkin (minimal +1 hari)
                if (
                    Carbon::parse($row['expected_checkout'])
                    ->lessThanOrEqualTo(Carbon::parse($row['expected_checkin']))
                ) {
                    $row['expected_checkout'] = Carbon::parse($row['expected_checkin'])->addDay()->setTime(12, 0);
                }
                // ===================================================================

                // Room rate otomatis dari harga room jika kosong
                if (empty($row['room_rate']) && !empty($row['room_id'])) {
                    $price = Room::whereKey($row['room_id'])->value('price');
                    if ($price !== null) {
                        $row['room_rate'] = (int) $price;
                    }
                }
            }
            unset($row);
        }

        // Buang field non-DB / legacy
        unset(
            $data['nights'],
            $data['reserved_guest_id'],
            $data['reserved_by'],
            $data['reserved_number'],
            $data['reserved_title'],
        );

        return $data;
    }

    // Nilai default saat membuka form edit (untuk UI saja)
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Default nights
        if (empty($data['nights'])) {
            if (!empty($data['expected_arrival']) && !empty($data['expected_departure'])) {
                $data['nights'] = Carbon::parse($data['expected_arrival'])
                    ->startOfDay()
                    ->diffInDays(Carbon::parse($data['expected_departure'])->startOfDay()) ?: 1;
            } else {
                $data['nights'] = 1;
            }
        }

        // Default reserved_by_type berdasar data yang ada
        if (empty($data['reserved_by_type'])) {
            $data['reserved_by_type'] = !empty($data['group_id']) ? 'GROUP' : 'GUEST';
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print')
                ->icon('heroicon-m-printer')
                ->url(fn() => route('reservations.print', [
                    'reservation' => $this->record,   // ← WAJIB pakai key param
                ]))
                ->openUrlInNewTab(),

            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
