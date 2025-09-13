<?php

namespace App\Filament\Resources\Guests\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

// intervention/image v3 (core)
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

class GuestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('hotel_id')
                ->default(fn() => Session::get('active_hotel_id'))
                ->dehydrated(true)
                ->required(),

            TextInput::make('name')->required(),
            TextInput::make('email')->label('Email address')->email(),
            TextInput::make('phone')->tel(),
            TextInput::make('address'),

            TextInput::make('nid_no')->label('NID'),
            TextInput::make('passport_no'),
            TextInput::make('father'),
            TextInput::make('mother'),
            TextInput::make('spouse'),
            // NID attachment (image/pdf) — gambar DIKOMPRES, pdf disimpan apa adanya
            FileUpload::make('nid_file_path')
                ->label('NID Attachment')
                ->disk('public')
                ->directory('guests/nid')
                ->preserveFilenames()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                ->getUploadedFileNameForStorageUsing(
                    fn(TemporaryUploadedFile $file): string => $file->getClientOriginalName()
                )
                ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                    if (str_starts_with($file->getMimeType(), 'image/')) {
                        return self::storeCompressed($file, 'guests/nid');
                    }
                    // PDF → simpan apa adanya (overwrite)
                    $path = 'guests/nid/' . $file->getClientOriginalName();
                    Storage::disk('public')->put($path, file_get_contents($file->getRealPath()), 'public');
                    return $path;
                })
                ->downloadable()
                ->openable(),

            // Passport attachment (image/pdf) — gambar DIKOMPRES, pdf disimpan apa adanya
            FileUpload::make('passport_file_path')
                ->label('Passport Attachment')
                ->disk('public')
                ->directory('guests/passport')
                ->preserveFilenames()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                ->getUploadedFileNameForStorageUsing(
                    fn(TemporaryUploadedFile $file): string => $file->getClientOriginalName()
                )
                ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                    if (str_starts_with($file->getMimeType(), 'image/')) {
                        return self::storeCompressed($file, 'guests/passport');
                    }
                    $path = 'guests/passport/' . $file->getClientOriginalName();
                    Storage::disk('public')->put($path, file_get_contents($file->getRealPath()), 'public');
                    return $path;
                })
                ->downloadable()
                ->openable(),

            // Photo — gambar saja, DIKOMPRES
            FileUpload::make('photo_path')
                ->label('Photo')
                ->disk('public')
                ->directory('guests/photos')
                ->image()
                ->preserveFilenames()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->getUploadedFileNameForStorageUsing(
                    fn(TemporaryUploadedFile $file): string => $file->getClientOriginalName()
                )
                ->saveUploadedFileUsing(
                    fn(TemporaryUploadedFile $file): string
                    => self::storeCompressed($file, 'guests/photos')
                ),
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
