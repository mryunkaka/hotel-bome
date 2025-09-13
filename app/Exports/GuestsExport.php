<?php

namespace App\Exports;

use App\Models\Guest;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class GuestsExport implements
    FromQuery,
    WithMapping,
    WithHeadings,
    WithEvents,
    ShouldAutoSize
{
    protected int $hid;

    public function __construct()
    {
        $this->hid = (int) (session('active_hotel_id') ?? 0);
    }

    public function query()
    {
        return Guest::query()
            ->when($this->hid, fn($q) => $q->where('hotel_id', $this->hid))
            ->latest('id');
    }

    // urutan kolom yang “dibaca importer”
    public function headings(): array
    {
        // kolom inti untuk import:
        // name, email, phone, address, nid_no, passport_no, father, mother, spouse
        // Tambahkan kolom info non-import di akhir (mis: created_at)
        return [
            'name',
            'email',
            'phone',
            'address',
            'nid_no',
            'passport_no',
            'father',
            'mother',
            'spouse',
            'created_at', // hanya informasi; importer akan abaikan
        ];
    }

    public function map($g): array
    {
        return [
            $g->name,
            $g->email,
            $g->phone,
            $g->address,
            $g->nid_no,
            $g->passport_no,
            $g->father,
            $g->mother,
            $g->spouse,
            optional($g->created_at)?->timezone('Asia/Singapore')?->format('Y-m-d H:i'),
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $e) {
                $sheet = $e->sheet->getDelegate();

                $lastRow = $sheet->getHighestRow();
                $lastCol = 'J'; // 10 kolom

                $header = "A1:{$lastCol}1";
                $sheet->getStyle($header)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($header)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
                $sheet->getRowDimension(1)->setRowHeight(22);

                $sheet->getStyle("A1:{$lastCol}{$lastRow}")
                    ->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('DDDDDD');

                $sheet->setAutoFilter($header);
                $sheet->freezePane('A2');
                $sheet->getStyle("D2:D{$lastRow}")->getAlignment()->setWrapText(true); // address
            },
        ];
    }
}
