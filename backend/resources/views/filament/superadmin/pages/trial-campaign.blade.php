<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="fi-form-actions mt-6">
            <x-filament::button type="submit">
                Save campaign
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
