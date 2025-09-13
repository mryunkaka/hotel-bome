<?php

namespace App\Filament\Resources\Guests\Schemas;

use Filament\Schemas\Schema;
use Intervention\Image\ImageManager;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Filament\Forms\Components\TextInput;

// intervention/image v3 (core)
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class GuestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // Scope hotel
            Hidden::make('hotel_id')
                ->default(fn() => Session::get('active_hotel_id'))
                ->required(),

            // Identitas dasar
            Select::make('salutation')
                ->label('Salutation')
                ->options(
                    fn() => collect(\App\Enums\Salutation::cases())
                        ->mapWithKeys(function ($c) {
                            // dukung BackedEnum & UnitEnum
                            $value = $c instanceof \BackedEnum ? $c->value : $c->name;
                            $label = ucfirst(strtolower($c->name));
                            return [$value => $label];
                        })->all()
                )
                ->native(false),

            TextInput::make('name')->required(),

            Select::make('guest_type')
                ->label('Guest Type')
                ->options([
                    'DOMESTIC' => 'DOMESTIC',
                    'INTERNASIONAL'    => 'INTERNASIONAL',
                ])
                ->native(false),

            TextInput::make('address'),
            TextInput::make('city'),
            TextInput::make('nationality'),
            TextInput::make('profession'),

            // Dokumen identitas
            Select::make('id_type')
                ->label('ID Type')
                ->options([
                    'ID'             => 'National ID',
                    'PASSPORT'       => 'Passport',
                    'DRIVER_LICENSE' => 'Driver License',
                    'OTHER'          => 'Other',
                ])
                ->native(false),

            TextInput::make('id_card')->label('ID / Passport Number'),

            FileUpload::make('id_card_file')
                ->label('ID/Passport Attachment')
                ->disk('public')
                ->directory('guests/idcard')
                ->preserveFilenames()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                ->getUploadedFileNameForStorageUsing(
                    fn(TemporaryUploadedFile $file): string => $file->getClientOriginalName()
                )
                ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                    $path = 'guests/idcard/' . $file->getClientOriginalName();
                    Storage::disk('public')->put($path, file_get_contents($file->getRealPath()), 'public');
                    return $path;
                })
                ->downloadable()
                ->openable(),

            // Data tempat & tanggal
            TextInput::make('birth_place'),
            DatePicker::make('birth_date')->native(false),

            TextInput::make('issued_place')->label('Issued At'),
            DatePicker::make('issued_date')->label('Issued Date')->native(false),

            // Kontak
            TextInput::make('phone')->tel(),
            TextInput::make('email')->label('Email address')->email(),
        ]);
    }

    /**
     * Kompres & simpan gambar ke disk 'public' TANPA mengubah nama file.
     * - scaleDown hingga sisi terpanjang 2560px (tajam saat di-zoom, file lebih kecil)
     * - JPEG/WEBP quality 82; PNG level 7
     * - Overwrite jika nama sama.
     * - (Opsional) Optimalkan lagi jika spatie/laravel-image-optimizer terpasang.
     */
    protected static function storeCompressed(TemporaryUploadedFile $file, string $dir): string
    {
        static $manager = null;
        if ($manager === null) {
            $driver  = extension_loaded('imagick') ? new ImagickDriver() : new GdDriver();
            $manager = new ImageManager($driver);
        }

        $filename = $file->getClientOriginalName();            // nama asli (termasuk ekstensi)
        $path     = trim($dir, '/') . '/' . $filename;

        // Baca & kecilkan (maks 2560px sisi terpanjang)
        $image = $manager->read($file->getRealPath())->scaleDown(2560, 2560);

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg'])) {
            $binary = $image->encodeByExtension('jpg', 82);    // jaga kualitas
        } elseif ($ext === 'png') {
            $binary = $image->encodeByExtension('png', 7);     // kompresi png
        } elseif ($ext === 'webp') {
            $binary = $image->encodeByExtension('webp', 82);
        } else {
            // Batasi hanya jenis gambar yang didukung melalui acceptedFileTypes
            // Jika sampai sini, fallback aman ke jpeg tanpa ganti nama:
            $binary = $image->encodeByExtension('jpg', 82);
        }

        Storage::disk('public')->put($path, (string) $binary, 'public');

        // (opsional) optimasi tambahan jika paket tersedia
        if (class_exists(\Spatie\ImageOptimizer\OptimizerChainFactory::class)) {
            $full = Storage::disk('public')->path($path);
            \Spatie\ImageOptimizer\OptimizerChainFactory::create()->optimize($full);
        }

        return $path;
    }
}
