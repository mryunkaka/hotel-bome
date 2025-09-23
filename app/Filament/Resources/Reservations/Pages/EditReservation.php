<?php

namespace App\Filament\Resources\Reservations\Pages;

use Throwable;
use App\Models\Room;
use Filament\Actions\Action;
use Illuminate\Support\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Illuminate\Support\Facades\Log;
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

    protected function beforeSave(): void
    {
        Log::info('[DEBUG] EditReservation - beforeSave called');
    }

    protected function afterSave(): void
    {
        Log::info('[DEBUG] EditReservation - afterSave START');

        try {
            $this->purgeGuestsIfRepeaterEmpty();

            // pastikan ambil nilai TERBARU dari DB
            $this->record->refresh();

            // Paksa ulang semua ReservationGuest mengikuti header
            $this->syncGuestsCheckinCheckoutFromHeader();

            Log::info('[DEBUG] EditReservation - afterSave SUCCESS');
        } catch (Throwable $e) {
            Log::error('[DEBUG] EditReservation - afterSave FAILED', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function purgeGuestsIfRepeaterEmpty(): void
    {
        // Ambil state Repeater dari form
        $items = data_get($this->data, 'reservationGuests', []);

        // Jika null / [] / hanya berisi elemen kosong -> anggap kosong
        $isEmpty = blank($items) || collect($items)->filter()->isEmpty();

        Log::info('[DEBUG] purgeGuestsIfRepeaterEmpty', [
            'items_count' => count($items),
            'isEmpty' => $isEmpty
        ]);

        if ($isEmpty) {
            // Soft delete SEMUA anak
            $this->record->reservationGuests()->delete();
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        Log::info('[DEBUG] EditReservation - mutateFormDataBeforeSave START', [
            'data_keys' => array_keys($data),
            'reserved_by_type' => $data['reserved_by_type'] ?? null,
            'guest_id' => $data['guest_id'] ?? null,
            'group_id' => $data['group_id'] ?? null,
            'record_id' => $this->record->id ?? null
        ]);

        try {
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

                Log::info('[DEBUG] Calculated departure', [
                    'arrival' => $data['expected_arrival'],
                    'nights' => $data['nights'],
                    'departure' => $data['expected_departure']
                ]);
            }

            // 3. RESERVED BY TYPE VALIDATION
            $type = $data['reserved_by_type'] ?? 'GUEST';
            Log::info('[DEBUG] Processing reserved_by_type', ['type' => $type]);

            if ($type === 'GUEST') {
                if (empty($data['guest_id'])) {
                    Log::warning('[DEBUG] Guest ID kosong padahal type GUEST');
                    throw ValidationException::withMessages([
                        'guest_id' => 'Silakan pilih Guest.',
                    ]);
                }
                $data['group_id'] = null;
                Log::info('[DEBUG] Set group_id to null for GUEST type');
            } else {
                if (empty($data['group_id'])) {
                    Log::warning('[DEBUG] Group ID kosong padahal type GROUP');
                    throw ValidationException::withMessages([
                        'group_id' => 'Silakan pilih Group.',
                    ]);
                }
                $data['guest_id'] = null;
                Log::info('[DEBUG] Set guest_id to null for GROUP type');
            }

            // 4. PROCESS RESERVATION GUESTS (SIMPLIFIED)
            if (!empty($data['reservationGuests']) && is_array($data['reservationGuests'])) {
                $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

                Log::info('[DEBUG] Processing reservationGuests', [
                    'count' => count($data['reservationGuests']),
                    'hotel_id' => $hid
                ]);

                // Header dates untuk sync
                $headerIn = !empty($data['expected_arrival'])
                    ? Carbon::parse($data['expected_arrival'])
                    : null;
                $headerOut = !empty($data['expected_departure'])
                    ? Carbon::parse($data['expected_departure'])
                    : null;

                foreach ($data['reservationGuests'] as $index => &$row) {
                    Log::info("[DEBUG] Processing guest row {$index}", [
                        'room_id' => $row['room_id'] ?? null,
                        'guest_id' => $row['guest_id'] ?? null
                    ]);

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

                Log::info('[DEBUG] Finished processing reservationGuests');
            }

            // 5. CLEAN UP NON-DATABASE FIELDS
            unset(
                $data['nights'],
                $data['reserved_guest_id'],
                $data['reserved_by'],
                $data['reserved_number'],
                $data['reserved_title']
            );

            Log::info('[DEBUG] EditReservation - mutateFormDataBeforeSave SUCCESS', [
                'final_keys' => array_keys($data),
                'guest_count' => count($data['reservationGuests'] ?? [])
            ]);

            return $data;
        } catch (ValidationException $e) {
            Log::error('[DEBUG] EditReservation - Validation Error', [
                'errors' => $e->errors()
            ]);
            throw $e;
        } catch (Throwable $e) {
            Log::error('[DEBUG] EditReservation - mutateFormDataBeforeSave FAILED', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => collect($e->getTrace())->take(3)->toArray()
            ]);
            throw $e;
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        Log::info('[DEBUG] EditReservation - mutateFormDataBeforeFill', [
            'record_id' => $this->record->id ?? 'unknown',
            'has_arrival' => !empty($data['expected_arrival']),
            'has_departure' => !empty($data['expected_departure'])
        ]);

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

        Log::info('[DEBUG] Calculated UI fields', [
            'nights' => $data['nights'],
            'reserved_by_type' => $data['reserved_by_type']
        ]);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print')
                ->icon('heroicon-m-printer')
                ->url(fn() => route('reservations.print', [
                    'reservation' => $this->record,
                ]))
                ->openUrlInNewTab(),

            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    private function syncGuestsCheckinCheckoutFromHeader(): void
    {
        $reservation = $this->record;

        $in = $reservation->expected_arrival
            ? Carbon::parse($reservation->expected_arrival)
            : null;

        $out = $reservation->expected_departure
            ? Carbon::parse($reservation->expected_departure)
            : null;

        $payload = [];
        if ($in) $payload['expected_checkin'] = $in;
        if ($out) $payload['expected_checkout'] = $out;

        Log::info('[DEBUG] syncGuestsCheckinCheckoutFromHeader', [
            'payload_keys' => array_keys($payload),
            'guests_count' => $reservation->reservationGuests()->count()
        ]);

        if (!empty($payload)) {
            $updated = $reservation->reservationGuests()->update($payload);
            Log::info('[DEBUG] Updated guests with header dates', ['updated_count' => $updated]);
        }
    }
}
