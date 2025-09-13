<?php

namespace App\Filament\Resources\Hotels\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\LaravelImageOptimizer\Facades\ImageOptimizer;
use Intervention\Image\ImageManager;
use Illuminate\Validation\ValidationException;

class HotelForm
{
    private const MAX_UPLOAD_KB = 10240; // 10 MB
    private const MAX_DIM       = 2560;  // max width/height untuk web

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            TextInput::make('name')
                ->label('Nama Hotel')
                ->required()
                ->maxLength(255),

            TextInput::make('tipe')
                ->label('Tipe Hotel')
                ->required()
                ->maxLength(255),

            TextInput::make('no_reg')
                ->label('No. Registrasi')
                ->maxLength(100),

            Textarea::make('address')->label('Alamat'),

            TextInput::make('phone')->label('Telepon')->tel()->maxLength(50),

            TextInput::make('email')->label('Email')->email()->maxLength(255),

            // LOGO — hanya gambar → konversi ke .png + kompres + dedup
            FileUpload::make('logo')
                ->label('Logo Hotel')
                ->acceptedFileTypes(['image/*'])
                ->rules(['max:' . self::MAX_UPLOAD_KB]) // 10 MB
                ->disk('public')
                ->directory('hotels/logos')
                ->image() // penting untuk preview & auto-orient
                ->imagePreviewHeight('120')
                ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                    return self::storeAsPngOrReuse($file, 'hotels/logos', fieldName: 'logo');
                }),

            // FOTO — multiple, relasi hasMany('photos'), hanya gambar
            Repeater::make('photos')
                ->label('Foto Hotel')
                ->relationship('photos')
                ->collapsible()
                ->defaultItems(0)
                ->addActionLabel('Tambah Foto')
                ->schema([
                    FileUpload::make('path')
                        ->label('Foto')
                        ->acceptedFileTypes(['image/*'])
                        ->rules(['max:' . self::MAX_UPLOAD_KB]) // 10 MB
                        ->disk('public')
                        ->directory('hotels/photos')
                        ->image() // tambahkan agar preview/handling image aktif
                        ->imagePreviewHeight('150')
                        ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                            return self::storeAsPngOrReuse($file, 'hotels/photos', fieldName: 'path');
                        }),

                    TextInput::make('caption')->label('Keterangan')->maxLength(255),
                ]),
        ]);
    }

    /**
     * Simpan file gambar sebagai PNG (nama file = nama asli, ekstensi .png).
     * - Jika target sudah ada => pakai yang ada (dedup).
     * - Gambar (jpg/png/webp/…) => Intervention → PNG
     * - Setelah simpan => kompres (lossless).
     */
    private static function storeAsPngOrReuse(TemporaryUploadedFile $file, string $dir, string $fieldName): string
    {
        // Guard: ukuran file mentah jangan terlalu besar (double-protection)
        $sizeKb = (int) ceil(($file->getSize() ?? 0) / 1024);
        if ($sizeKb > self::MAX_UPLOAD_KB * 1.5) {
            throw ValidationException::withMessages([
                $fieldName => 'File terlalu besar. Maksimal ~' . self::MAX_UPLOAD_KB . ' KB.',
            ]);
        }

        $originalName = $file->getClientOriginalName();
        $basename     = pathinfo($originalName, PATHINFO_FILENAME);

        $safeBase   = Str::slug($basename);
        $targetFile = $safeBase . '.png';
        $targetPath = trim($dir, '/') . '/' . $targetFile;

        // Dedup: jika .png dengan nama sama sudah ada → pakai existing
        if (Storage::disk('public')->exists($targetPath)) {
            return $targetPath;
        }

        Storage::disk('public')->makeDirectory($dir);

        $source         = $file->getRealPath();
        $absoluteTarget = Storage::disk('public')->path($targetPath);

        // Konversi gambar → PNG (hemat memori: pakai Imagick jika ada, fallback GD)
        self::convertImageToPng($source, $absoluteTarget);

        // Optimasi setelah tersimpan
        try {
            ImageOptimizer::optimize($absoluteTarget);
        } catch (\Throwable $e) {
            // abaikan error optimizer agar upload tetap sukses
        }

        return $targetPath;
    }

    /** Konversi gambar (jpg/png/webp/…) ke PNG, skala turun + sharpen ringan */
    private static function convertImageToPng(string $sourceAbsolute, string $targetAbsolute): void
    {
        $driver = class_exists('Imagick')
            ? new \Intervention\Image\Drivers\Imagick\Driver()
            : new \Intervention\Image\Drivers\Gd\Driver();

        $manager = new ImageManager($driver);

        $image = $manager->read($sourceAbsolute);

        // orientasi EXIF bila ada
        if (method_exists($image, 'orient')) {
            $image->orient();
        }

        // downscale proporsional
        if (method_exists($image, 'scaleDown')) {
            $image->scaleDown(self::MAX_DIM, self::MAX_DIM);
        } else {
            $w = $image->width();
            $h = $image->height();
            if ($w > self::MAX_DIM || $h > self::MAX_DIM) {
                $ratio = min(self::MAX_DIM / $w, self::MAX_DIM / $h);
                $image->resize((int) ($w * $ratio), (int) ($h * $ratio));
            }
        }

        // sharpen ringan (v3: langsung panggil ->sharpen)
        if (method_exists($image, 'sharpen')) {
            $image->sharpen(12); // 8–16 aman
        }

        // simpan sebagai PNG valid
        $image->encodeByExtension('png');
        $image->save($targetAbsolute);

        // optimasi lossless (pngquant/optipng bila tersedia)
        self::optimizePngLossless($targetAbsolute);
    }

    private static function optimizePngLossless(string $absoluteTarget): void
    {
        try {
            ImageOptimizer::optimize($absoluteTarget);
        } catch (\Throwable $e) {
            // abaikan kalau tool CLI belum terpasang
        }
    }
}
