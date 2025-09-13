<?php

namespace App\Exports;

use App\Models\Hotel;
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

class HotelsExport implements
    FromQuery,
    WithMapping,
    WithHeadings,
    WithEvents,
    ShouldAutoSize,
    WithColumnFormatting
{
    public function query()
    {
        return Hotel::query()->latest('id');
    }

    public function headings(): array
    {
        return [
            'name',
            'tipe',
            'email',
            'phone',
            'address',
            'no_reg',
            'created_at', // informasi tambahan
        ];
    }

    public function map($h): array
    {
        return [
            $h->name,
            $h->tipe,
            $h->email,
            $h->phone,
            $h->address,
            $h->no_reg,
            optional($h->created_at)?->timezone('Asia/Singapore')?->format('Y-m-d H:i'),
        ];
    }

    public function columnFormats(): array
    {
        return [
            'G' => NumberFormat::FORMAT_DATE_YYYYMMDD2 . ' ' . NumberFormat::FORMAT_DATE_TIME4,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $e) {
                $sheet = $e->sheet->getDelegate();

                $lastRow = $sheet->getHighestRow();
                $lastCol = 'G'; // 7 kolom

                $header = "A1:{$lastCol}1";
                $sheet->getStyle($header)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($header)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
                $sheet->getRowDimension(1)->setRowHeight(22);

                $sheet->getStyle("A1:{$lastCol}{$lastRow}")
                    ->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('DDDDDD');

                $sheet->setAutoFilter($header);
                $sheet->freezePane('A2');

                $sheet->getStyle("E2:E{$lastRow}")->getAlignment()->setWrapText(true); // address
            },
        ];
    }
}
