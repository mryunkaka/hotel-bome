<?php

namespace App\Exports;

use App\Models\Room;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class RoomsExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
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
        return Room::query()
            ->when($this->hid, fn($q) => $q->where('hotel_id', $this->hid))
            ->orderBy('floor')
            ->orderBy('room_no');
    }

    // SAMA dengan importer (satu mode)
    public function headings(): array
    {
        return [
            'type',
            'room_no',
            'floor',
            'price',
            'geyser',
            'ac',
            'balcony',
            'bathtub',
            'hicomode',
            'locker',
            'freeze',
            'internet',
            'intercom',
            'tv',
            'wardrobe',
            'created_at',
        ];
    }

    public function map($r): array
    {
        $b = fn($v) => $v ? 1 : 0;

        return [
            (string) $r->type,
            (string) $r->room_no,
            $r->floor !== null ? (int) $r->floor : null,
            $r->price !== null ? (float) $r->price : null,

            $b($r->geyser),
            $b($r->ac),
            $b($r->balcony),
            $b($r->bathtub),
            $b($r->hicomode),
            $b($r->locker),
            $b($r->freeze),
            $b($r->internet),
            $b($r->intercom),
            $b($r->tv),
            $b($r->wardrobe),

            optional($r->created_at)?->timezone('Asia/Singapore')->format('Y-m-d H:i'),
        ];
    }

    public function columnFormats(): array
    {
        // floor integer, price 2 desimal; kolom boolean tetap general
        return [
            'C' => NumberFormat::FORMAT_NUMBER,
            'D' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $e) {
                $sheet   = $e->sheet->getDelegate();
                $header  = 'A1:P1'; // A..P = 16 kolom di headings()

                // Header abu-abu gelap, font putih
                $sheet->getStyle($header)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($header)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
                $sheet->getRowDimension(1)->setRowHeight(20);

                // Border semua sel
                $lastRow = $sheet->getHighestRow();
                $sheet->getStyle("A1:P{$lastRow}")
                    ->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setRGB('DDDDDD');

                // Freeze & Filter
                $sheet->freezePane('A2');
                $sheet->setAutoFilter($header);
            },
        ];
    }
}
