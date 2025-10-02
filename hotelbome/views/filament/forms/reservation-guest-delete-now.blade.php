<div class="flex justify-end pt-6">
    @if($rowId)
        <x-filament::button
            size="sm"
            color="danger"
            icon="heroicon-m-trash"
            wire:click="$dispatch('reservation-guest:delete-now', { id: {{ $rowId }}, index: {{ $rowIndex }} })"
            x-on:click.prevent
        >
            Delete now
        </x-filament::button>
    @else
        {{-- kalau baris baru (belum punya id), cukup hilangkan dari state --}}
        <x-filament::button
            size="sm"
            color="danger"
            icon="heroicon-m-trash"
            wire:click="$dispatch('reservation-guest:remove-row', { index: {{ $rowIndex }} })"
            x-on:click.prevent
        >
            Remove
        </x-filament::button>
    @endif
</div>
