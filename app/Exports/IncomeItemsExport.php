<?php

namespace App\Exports;

use App\Models\IncomeItem;
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

class IncomeItemsExport implements
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
        return IncomeItem::query()
            ->with('incomeCategory')
            ->when($this->hid, fn($q) => $q->where('hotel_id', $this->hid))
            ->latest('date')->latest('id');
    }

    // urutan ini jadi patokan importer
    public function headings(): array
    {
        return [
            'category',     // nama kategori
            'amount',       // angka 2 desimal
            'description',  // teks
            'date',         // Y-m-d H:i (Asia/Singapore)
            'created_at',   // ekstra (diabaikan importer)
        ];
    }

    public function map($it): array
    {
        return [
            $it->incomeCategory?->name,
            (float) $it->amount,
            (string) $it->description,
            optional($it->date)?->timezone('Asia/Singapore')?->format('Y-m-d H:i'),
            optional($it->created_at)?->timezone('Asia/Singapore')?->format('Y-m-d H:i'),
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => '#,##0.00', // amount
            'D' => NumberFormat::FORMAT_DATE_YYYYMMDD2 . ' ' . NumberFormat::FORMAT_DATE_TIME4, // date
            'E' => NumberFormat::FORMAT_DATE_YYYYMMDD2 . ' ' . NumberFormat::FORMAT_DATE_TIME4, // created_at
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $e) {
                $sheet = $e->sheet->getDelegate();

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

                // Wrap description
                $sheet->getStyle("C2:C{$lastRow}")->getAlignment()->setWrapText(true);
            },
        ];
    }
}
