<?php

namespace App\Exports;

use App\Models\AccountLedger;
use App\Models\Hotel;
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

class AccountLedgersExport implements
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
        return AccountLedger::query()
            ->when($this->hid, fn($q) => $q->where('hotel_id', $this->hid))
            ->latest('date');
    }

    public function headings(): array
    {
        return ['debit', 'credit', 'date', 'method', 'description'];
    }

    public function map($row): array
    {
        return [
            (float) $row->debit,
            (float) $row->credit,
            optional($row->date)->format('Y-m-d'),
            $row->method,
            $row->description,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2, // debit
            'B' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2, // credit
            'C' => NumberFormat::FORMAT_DATE_YYYYMMDD2,          // date
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $headerRange = 'A1:E1';
                $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($headerRange)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('1F2937');
                $sheet->getRowDimension(1)->setRowHeight(22);

                $highestRow = $sheet->getHighestRow();
                $sheet->getStyle("A1:E{$highestRow}")
                    ->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setRGB('DDDDDD');

                $sheet->setAutoFilter($headerRange);
                $sheet->freezePane('A2');

                $sheet->getStyle("E2:E{$highestRow}")->getAlignment()->setWrapText(true);
            },
        ];
    }
}
