<?php

namespace Tests\Feature;

use Tests\TestCase;

class FacilityBoardViewTest extends TestCase
{
    public function test_empty_facility_board_shows_a_working_creation_link_and_calendar_hint(): void
    {
        $html = view('facility_blocks.board', [
            'facilities' => collect(),
            'nameMap' => [],
            'buckets' => [],
            'stats' => [],
            'calendarEvents' => [],
        ])->render();

        $this->assertStringContainsString('Belum ada fasilitas.', $html);
        $createUrl = \App\Filament\Resources\Facilities\FacilityResource::getUrl('create');
        $this->assertStringContainsString('href="' . $createUrl . '"', $html);
        $this->assertStringContainsString('Belum ada booking atau blok fasilitas', $html);
        $this->assertStringContainsString('.dark .fb-theme', $html);
        $this->assertStringContainsString('--fb-surface:#2e3440', $html);
        $this->assertStringContainsString('#facility-calendar .fc-theme-standard .fc-scrollgrid', $html);
        $this->assertStringNotContainsString('index.global.min.css', $html);
    }
}
