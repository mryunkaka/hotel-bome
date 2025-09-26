<?php

namespace App\Filament\Resources\Reservations\Pages;

use Throwable;
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

        // pastikan ambil nilai TERBARU dari DB
        $this->record->refresh();

        // Paksa ulang semua ReservationGuest mengikuti header
        $this->syncGuestsCheckinCheckoutFromHeader();

        // === TAMBAHKAN 3 BARIS INI SAJA ===
        $printUrl = route('reservations.print', ['reservation' => $this->record]);
        // buka tab baru untuk halaman print
        $this->js("window.open('{$printUrl}', '_blank', 'noopener,noreferrer');");
        // ================================
    }

    private function purgeGuestsIfRepeaterEmpty(): void
    {
        // Ambil state Repeater dari form
        $items = data_get($this->data, 'reservationGuests', []);

        // Jika null / [] / hanya berisi elemen kosong -> anggap kosong
        $isEmpty = blank($items) || collect($items)->filter()->isEmpty();

        if ($isEmpty) {
            // Soft delete SEMUA anak
            $this->record->reservationGuests()->delete();
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // 1. BASIC FIELDS
        $data['created_by'] = $data['created_by'] ?? Auth::id();
        $data['hotel_id'] = $data['hotel_id'] ?? Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

        // 2. CALCULATE DEPARTURE FROM NIGHTS
        if (!empty($data['expected_arrival']) && !empty($data['nights'])) {
            $arrival = Carbon::parse($data['expected_arrival']);
            $data['expected_departure'] = $arrival->copy()
                ->startOfDay()
                ->addDays(max(1, (int) $data['nights']))
                ->setTime(12, 0);
        }

        // 3. RESERVED BY TYPE VALIDATION
        $type = $data['reserved_by_type'] ?? 'GUEST';

        if ($type === 'GUEST') {
            if (empty($data['guest_id'])) {
                throw ValidationException::withMessages([
                    'guest_id' => 'Silakan pilih Guest.',
                ]);
            }
            $data['group_id'] = null;
        } else {
            if (empty($data['group_id'])) {
                throw ValidationException::withMessages([
                    'group_id' => 'Silakan pilih Group.',
                ]);
            }
            $data['guest_id'] = null;
        }

        // 4. PROCESS RESERVATION GUESTS (SIMPLIFIED)
        if (!empty($data['reservationGuests']) && is_array($data['reservationGuests'])) {
            $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

            // Header dates untuk sync
            $headerIn = !empty($data['expected_arrival'])
                ? Carbon::parse($data['expected_arrival'])
                : null;
            $headerOut = !empty($data['expected_departure'])
                ? Carbon::parse($data['expected_departure'])
                : null;

            foreach ($data['reservationGuests'] as $index => &$row) {

                // Basic fields
                $row['hotel_id'] = $row['hotel_id'] ?? $hid;

                // Calculate total people
                $male = (int) ($row['male'] ?? 0);
                $female = (int) ($row['female'] ?? 0);
                $children = (int) ($row['children'] ?? 0);
                $row['jumlah_orang'] = max(1, $male + $female + $children);

                // Sync dates with header
                if ($headerIn && empty($row['expected_checkin'])) {
                    $row['expected_checkin'] = $headerIn->copy();
                }
                if ($headerOut && empty($row['expected_checkout'])) {
                    $row['expected_checkout'] = $headerOut->copy();
                }

                // Default dates if still empty
                if (empty($row['expected_checkin'])) {
                    $row['expected_checkin'] = now()->setTime(12, 0);
                }
                if (empty($row['expected_checkout'])) {
                    $row['expected_checkout'] = Carbon::parse($row['expected_checkin'])->addDay()->setTime(12, 0);
                }

                // Auto room rate
                if (empty($row['room_rate']) && !empty($row['room_id'])) {
                    $price = Room::whereKey($row['room_id'])->value('price');
                    if ($price !== null) {
                        $row['room_rate'] = (int) $price;
                    }
                }

                // Normalize room_rate
                $row['room_rate'] = (int) ($row['room_rate'] ?? 0);
            }
            unset($row);
        }

        // 5. CLEAN UP NON-DATABASE FIELDS
        unset(
            $data['nights'],
            $data['reserved_guest_id'],
            $data['reserved_by'],
            $data['reserved_number'],
            $data['reserved_title']
        );

        return $data;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Calculate nights for UI
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

        // Set reserved_by_type based on existing data
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
                ->visible(fn() => filled($this->record?->guest_id)) // ⬅️ tambahkan baris ini
                ->url(fn() => route('reservations.print', [
                    'reservation' => $this->record,
                ]))
                ->openUrlInNewTab(),

            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    // app/Filament/Resources/Reservations/Pages/EditReservation.php

    private function syncGuestsCheckinCheckoutFromHeader(): void
    {
        $reservation = $this->record->fresh(['reservationGuests']);

        $in  = $reservation->expected_arrival  ? \Illuminate\Support\Carbon::parse($reservation->expected_arrival)  : null;
        $out = $reservation->expected_departure ? \Illuminate\Support\Carbon::parse($reservation->expected_departure) : null;

        $payload = [];
        if ($in) {
            $payload['expected_checkin']  = $in;
        }
        if ($out) {
            $payload['expected_checkout'] = $out;
        }

        if (! empty($payload)) {
            // Sinkron untuk semua anak yang belum punya actual_checkout
            $reservation->reservationGuests()
                // ->update($payload);
                // Kalau mau lebih ketat, bisa batasi:
                ->whereNull('actual_checkin')
                ->update($payload);
        }
    }
}
