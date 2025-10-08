<?php

namespace App\Filament\Resources\Banks\Schemas;

use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

class BankForm
{
    public static function configure(Schema $schema): Schema
    {
        // Katalog bank: short_code => [Label Nama Bank, SWIFT]
        $bankCatalog = [
            'BCA'      => ['Bank Central Asia (BCA)',      'CENAIDJA'],
            'BRI'      => ['Bank Rakyat Indonesia (BRI)',  'BRINIDJA'],
            'MANDIRI'  => ['Bank Mandiri',                 'BMRIIDJA'],
            'BNI'      => ['Bank Negara Indonesia (BNI)',  'BNINIDJA'],
            'BSI'      => ['Bank Syariah Indonesia (BSI)', 'BSMDIDJA'],
            'BTN'      => ['Bank Tabungan Negara (BTN)',   'BTANIDJA'],
            'PERMATA'  => ['Bank Permata',                 'BBBAIDJA'],
            'DANAMON'  => ['Bank Danamon',                 'BDINIDJA'],
            'CIMB'     => ['CIMB Niaga',                   'BNIAIDJA'],
            'OCBC'     => ['OCBC NISP',                    'NISPIDJA'],
            'PANIN'    => ['Panin Bank',                   'PINBIDJA'],
            'BJB'      => ['Bank BJB',                     'PDJBIDJA'],
            'BTPN'     => ['Bank BTPN',                    'SUNIIDJA'],
            'MAYBANK'  => ['Maybank Indonesia',            'IBBKIDJA'],
            'MEGA'     => ['Bank Mega',                    'MEGAIDJA'],
        ];

        $bankOptions = collect($bankCatalog)->mapWithKeys(fn($v, $k) => [$k => $v[0]])->all();

        return $schema->components([
            Hidden::make('hotel_id')
                ->default(fn() => (int) Session::get('active_hotel_id'))
                ->dehydrated(true)
                ->required(),

            Grid::make(12)->schema([

                // === Pilih "Nama Bank" via SELECT (disimpan ke short_code),
                //     lalu otomatis isi name & swift_code.
                Select::make('short_code')
                    ->label('Bank Name')
                    ->options($bankOptions)
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required() // WAJIB (bagian dari "nama bank")
                    ->helperText('Pilih bank. Nama & SWIFT akan terisi otomatis.')
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set) use ($bankCatalog) {
                        $code = $state ? mb_strtoupper(trim($state)) : null;
                        if ($code && isset($bankCatalog[$code])) {
                            $set('name', $bankCatalog[$code][0] ?? $code);
                            $set('swift_code', $bankCatalog[$code][1] ?? null);
                        } else {
                            $set('name', null);
                            $set('swift_code', null);
                        }
                    })
                    // unik per hotel
                    ->rule(function (Get $get, ?Model $record) {
                        return Rule::unique('banks', 'short_code')
                            ->where('hotel_id', (int) Session::get('active_hotel_id'))
                            ->ignore($record?->id);
                    })
                    ->columnSpan(6),

                // Ditampilkan agar user melihat nama bank final, tapi readOnly (auto)
                TextInput::make('name')
                    ->label('Bank Label')
                    ->required()
                    ->readOnly()
                    ->columnSpan(6),

                TextInput::make('branch')
                    ->label('Branch')
                    ->maxLength(100)
                    ->columnSpan(6),

                TextInput::make('holder_name')
                    ->label('Account Holder')
                    ->maxLength(100)
                    ->columnSpan(6),

                // === Account Number (WAJIB)
                TextInput::make('account_no')
                    ->label('Account Number')
                    ->required()
                    ->maxLength(50)
                    ->extraInputAttributes(['inputmode' => 'numeric'])
                    ->helperText('Spasi/tanda baca akan dihapus otomatis saat blur.')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set) {
                        $normalized = $state ? preg_replace('/[\s\-\.]/', '', trim($state)) : null;
                        $set('account_no', $normalized);
                    })
                    // unik per hotel
                    ->rule(function (Get $get, ?Model $record) {
                        return Rule::unique('banks', 'account_no')
                            ->where('hotel_id', (int) Session::get('active_hotel_id'))
                            ->ignore($record?->id);
                    })
                    ->columnSpan(6),

                // === SWIFT otomatis (opsional)
                TextInput::make('swift_code')
                    ->label('SWIFT/BIC')
                    ->maxLength(20)
                    ->readOnly()
                    ->columnSpan(3),

                Select::make('currency')
                    ->label('Currency')
                    ->options([
                        'IDR' => 'IDR — Indonesian Rupiah',
                        'USD' => 'USD — US Dollar',
                        'EUR' => 'EUR — Euro',
                        'SGD' => 'SGD — Singapore Dollar',
                        'MYR' => 'MYR — Malaysian Ringgit',
                        'AUD' => 'AUD — Australian Dollar',
                    ])
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->default('IDR')
                    ->columnSpan(3),

                Toggle::make('is_active')
                    ->label('Active?')
                    ->default(true)
                    ->inline(false)
                    ->columnSpan(2),

                // === Address (WAJIB)
                Textarea::make('address')
                    ->label('Address')
                    ->rows(2)
                    ->maxLength(255)
                    ->required()
                    ->columnSpan(12),

                // Opsional
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(150)
                    ->columnSpan(6),

                TextInput::make('phone')
                    ->label('Phone')
                    ->tel()
                    ->maxLength(50)
                    ->columnSpan(6),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->maxLength(65535)
                    ->columnSpan(12),
            ])->columnSpanFull(),
        ]);
    }
}
