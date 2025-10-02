<?php

namespace App\Filament\Pages\Auth;

use App\Models\Hotel;
use App\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Filament\Notifications\Notification;

class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),

            Select::make('hotel_id')
                ->label('Hotel')
                ->options(fn() => Hotel::query()->orderBy('name')->pluck('name', 'id')->toArray())
                ->searchable()
                ->preload()
                ->required(),

            $this->getRememberFormComponent(),
        ]);
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        /** @var User|null $user */
        $user = User::where('email', $data['email'] ?? '')->first();

        // Email salah
        if (! $user) {
            Session::forget('active_hotel_id');
            Notification::make()->title('Login gagal')->body('Email tidak ditemukan.')->danger()->send();
            throw ValidationException::withMessages(['email' => 'Email tidak ditemukan.']);
        }

        // Password salah
        if (! Hash::check($data['password'] ?? '', $user->password)) {
            Session::forget('active_hotel_id');
            Notification::make()->title('Login gagal')->body('Password salah.')->danger()->send();
            throw ValidationException::withMessages(['password' => 'Password salah.']);
        }

        // Validasi & set konteks hotel
        $selectedHotelId = (int) ($data['hotel_id'] ?? 0);
        $selectedHotel   = Hotel::find($selectedHotelId);
        if (! $selectedHotel) {
            Notification::make()->title('Login gagal')->body('Hotel tidak valid.')->danger()->send();
            throw ValidationException::withMessages(['hotel_id' => 'Hotel tidak valid.']);
        }

        if ($user->hasRole('super admin')) {
            Session::put('active_hotel_id', $selectedHotel->id);
        } else {
            if ((int) $user->hotel_id !== (int) $selectedHotel->id) {
                Notification::make()->title('Login gagal')->body('Anda tidak berhak mengakses hotel ini.')->danger()->send();
                throw ValidationException::withMessages(['hotel_id' => 'Anda tidak berhak mengakses hotel ini.']);
            }
            Session::put('active_hotel_id', (int) $user->hotel_id);
        }

        // Kembalikan HANYA email & password
        return [
            'email'    => $data['email'],
            'password' => $data['password'],
            // JANGAN sertakan 'remember' di sini.
        ];
    }
}
