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
                ->options(Hotel::pluck('name', 'id'))
                ->searchable()
                ->required(),

            $this->getRememberFormComponent(),
        ]);
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        $user = User::where('email', $data['email'] ?? '')->first();

        // Email salah
        if (! $user) {
            Notification::make()
                ->title('Login gagal')
                ->body('Email tidak ditemukan.')
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'email' => __('Email tidak ditemukan.'),
            ]);
        }

        // Password salah
        if (! Hash::check($data['password'] ?? '', $user->password)) {
            Notification::make()
                ->title('Login gagal')
                ->body('Password salah.')
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'password' => __('Password salah.'),
            ]);
        }

        $hotelId = (int) ($data['hotel_id'] ?? 0);

        // Validasi hotel
        if ($user->hasRole('super admin')) {
            Session::put('active_hotel_id', $hotelId);
        } else {
            if ($user->hotel_id !== $hotelId) {
                Notification::make()
                    ->title('Login gagal')
                    ->body('Anda tidak berhak mengakses hotel ini.')
                    ->danger()
                    ->send();

                throw ValidationException::withMessages([
                    'hotel_id' => __('Anda tidak berhak mengakses hotel ini.'),
                ]);
            }
            Session::put('active_hotel_id', $hotelId);
        }

        return [
            'email' => $data['email'],
            'password' => $data['password'],
        ];
    }
}
