<?php

namespace App\Filament\Resources\FacilityBlocks\Pages;

use App\Filament\Resources\FacilityBlocks\FacilityBlockResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFacilityBlock extends CreateRecord
{
    protected static string $resource = FacilityBlockResource::class;

    // Ubah judul agar sesuai tampilan board
    protected static ?string $title = 'Facility Board';

    // Jangan tampilkan tombol "Create & create another"
    protected static bool $canCreateAnother = false;

    /**
     * Hilangkan semua aksi form (Create / Cancel dsb.)
     * Versi Filament v3: cukup override method ini.
     */
    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * Blokir penyimpanan ketika Livewire tetap memanggil create().
     * No-op agar tidak ada insert ke DB.
     */
    public function create(bool $another = false): void
    {
        // no-op: halaman ini hanya menampilkan board, tidak menyimpan apa pun
        return;
    }

    /**
     * Kalau ada mekanisme internal yang mencoba redirect setelah create,
     * tetap di halaman yang sama.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('create');
    }
}
