<?php

namespace App\Exports;

use App\Models\Bank;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class BanksExport implements
    FromQuery,
    WithMapping,
    WithHeadings,
    WithEvents,
    ShouldAutoSize,
    WithColumnFormatting
{
    protected int $hid;

    public function __construct()
    {
        $this->hid = (int) (session('active_hotel_id') ?? 0);
    }

    public function query()
    {
        return Bank::query()
            ->when($this->hid, fn($q) => $q->where('hotel_id', $this->hid))
            ->latest('id');
    }

    // urutan & nama kolom = sesuai importer (lowercase)
    public function headings(): array
    {
        return ['name', 'branch', 'account_no', 'address', 'phone', 'email'];
    }

    public function map($row): array
    {
        return [
            $row->name,
            $row->branch,
            (string) $row->account_no, // export sebagai string agar tidak jadi scientific/kehilangan leading zero
            $row->address,
            $row->phone,
            $row->email,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_TEXT, // account_no jadi TEXT supaya aman di Excel
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // header styling
                $headerRange = 'A1:F1';
                $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($headerRange)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('1F2937'); // slate-800
                $sheet->getRowDimension(1)->setRowHeight(22);

                // border seluruh tabel
                $highestRow = $sheet->getHighestRow();
                $sheet->getStyle("A1:F{$highestRow}")
                    ->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setRGB('DDDDDD');

                // autofilter + freeze
                $sheet->setAutoFilter($headerRange);
                $sheet->freezePane('A2');

                // wrap address
                $sheet->getStyle("D2:D{$highestRow}")->getAlignment()->setWrapText(true);
            },
        ];
    }
}
