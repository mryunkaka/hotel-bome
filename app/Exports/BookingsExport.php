<?php

namespace App\Exports;

use App\Models\Booking;
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

class BookingsExport implements
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
        return Booking::query()
            // muat relasi + termasuk yang soft-deleted
            ->with([
                'room'  => fn($q) => method_exists($q->getModel(), 'bootSoftDeletes') ? $q->withTrashed() : $q,
                'guest' => fn($q) => method_exists($q->getModel(), 'bootSoftDeletes') ? $q->withTrashed() : $q,
            ])
            ->when($this->hid, fn($q) => $q->where('hotel_id', $this->hid))
            ->latest('check_in_at');
    }

    public function headings(): array
    {
        return ['room', 'guest', 'check_in_at', 'check_out_at', 'status', 'notes'];
    }

    public function map($b): array
    {
        return [
            $this->roomLabel($b),                       // <= perbaikan di sini
            optional($b->guest)->name ?? optional($b->guest)->email,
            $this->fmtDt($b->check_in_at),
            $this->fmtDt($b->check_out_at),
            $b->status,
            $b->notes,
        ];
    }

    private function roomLabel($b): ?string
    {
        $r = $b->room;
        if (! $r) return null;

        foreach (['name', 'number', 'room_no', 'no', 'code'] as $attr) {
            if (isset($r->{$attr}) && $r->{$attr} !== '' && $r->{$attr} !== null) {
                return (string) $r->{$attr};
            }
        }
        // fallback terakhir
        return isset($r->id) ? (string) $r->id : null;
    }

    private function fmtDt($dt): ?string
    {
        return $dt ? $dt->timezone('Asia/Singapore')->format('Y-m-d H:i') : null;
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_DATE_YYYYMMDD2 . ' hh:mm',
            'D' => NumberFormat::FORMAT_DATE_YYYYMMDD2 . ' hh:mm',
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $e) {
                $sheet = $e->sheet->getDelegate();

                $header = 'A1:F1';
                $sheet->getStyle($header)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($header)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
                $sheet->getRowDimension(1)->setRowHeight(22);

                $lastRow = $sheet->getHighestRow();
                $sheet->getStyle("A1:F{$lastRow}")
                    ->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('DDDDDD');

                $sheet->setAutoFilter($header);
                $sheet->freezePane('A2');
                $sheet->getStyle("F2:F{$lastRow}")->getAlignment()->setWrapText(true);
            },
        ];
    }
}
