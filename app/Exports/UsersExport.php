<?php

namespace App\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class UsersExport implements FromQuery, WithMapping, WithHeadings, WithEvents, ShouldAutoSize, WithColumnFormatting
{
    public function query()
    {
        return User::query()
            ->with('hotel')     // <- preload biar gak N+1
            ->latest('id');     // tampilkan semua user
    }

    public function headings(): array
    {
        // Kolom import resmi tetap: name, email, password
        // Tambah kolom ekstra: hotel, created_at (importer abaikan)
        return [
            'hotel',     // ekstra
            'name',
            'email',
            'password',  // dibiarkan kosong saat export
            'created_at' // ekstra
        ];
    }

    public function map($u): array
    {
        return [
            $u->hotel?->name,  // hotel
            $u->name,
            $u->email,
            '', // JANGAN ekspor password
            optional($u->created_at)?->timezone('Asia/Singapore')?->format('Y-m-d H:i'),
        ];
    }

    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_DATE_YYYYMMDD2 . ' ' . NumberFormat::FORMAT_DATE_TIME4, // created_at
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $e) {
                $sheet   = $e->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();
                $lastCol = 'E'; // 5 kolom

                $header = "A1:{$lastCol}1";
                $sheet->getStyle($header)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($header)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
                $sheet->getRowDimension(1)->setRowHeight(22);

                $sheet->getStyle("A1:{$lastCol}{$lastRow}")
                    ->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('DDDDDD');

                $sheet->setAutoFilter($header);
                $sheet->freezePane('A2');
            },
        ];
    }
}
