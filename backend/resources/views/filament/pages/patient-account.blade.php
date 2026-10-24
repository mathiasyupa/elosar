<x-filament-panels::page>
    {{-- Resumen de totales --}}
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm text-gray-500 dark:text-gray-400">Total Facturado</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">
                ${{ number_format($this->getTotalInvoiced(), 2) }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm text-gray-500 dark:text-gray-400">Total Pagado</p>
            <p class="mt-1 text-2xl font-bold text-green-600">
                ${{ number_format($this->getTotalPaid(), 2) }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm text-gray-500 dark:text-gray-400">Saldo Pendiente</p>
            <p class="mt-1 text-2xl font-bold {{ $this->getTotalDue() > 0 ? 'text-red-600' : 'text-green-600' }}">
                ${{ number_format($this->getTotalDue(), 2) }}
            </p>
        </div>
    </div>

    {{-- Tabla de facturas --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">
                Historial de Facturas — {{ $this->record->name }}
            </h2>
        </div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>
