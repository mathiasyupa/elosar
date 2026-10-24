<x-filament-panels::page>

    {{-- ── Selector de fechas ──────────────────────────────────────────── --}}
    <div class="flex flex-wrap gap-4 items-end mb-6">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Desde</label>
            <input
                type="date"
                wire:model.live="dateFrom"
                value="{{ $dateFrom }}"
                class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500"
            >
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Hasta</label>
            <input
                type="date"
                wire:model.live="dateTo"
                value="{{ $dateTo }}"
                class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500"
            >
        </div>
    </div>

    {{-- ── KPI Cards ─────────────────────────────────────────────────────── --}}
    @php
        $totalCollected = $this->getTotalCollected();
        $paidInvoices   = $this->getPaidInvoicesCount();
        $uniquePatients = $this->getUniquePatientsCount();
        $averageTicket  = $paidInvoices > 0 ? $totalCollected / $paidInvoices : 0;
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 border border-gray-100 dark:border-gray-700">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total cobrado</p>
            <p class="text-3xl font-bold text-green-600 mt-1">${{ number_format($totalCollected, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 border border-gray-100 dark:border-gray-700">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Facturas pagadas</p>
            <p class="text-3xl font-bold text-blue-600 mt-1">{{ $paidInvoices }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 border border-gray-100 dark:border-gray-700">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Pacientes únicos</p>
            <p class="text-3xl font-bold text-purple-600 mt-1">{{ $uniquePatients }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 border border-gray-100 dark:border-gray-700">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Ticket promedio</p>
            <p class="text-3xl font-bold text-amber-600 mt-1">${{ number_format($averageTicket, 2) }}</p>
        </div>
    </div>

    {{-- ── Two columns: payment method + daily trend ────────────────────── --}}
    @php
        $breakdown  = $this->getBreakdownByMethod();
        $dailyTrend = $this->getDailyTrend();
        $dailyMax   = $dailyTrend ? max($dailyTrend) : 1;
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Breakdown by payment method --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-5">
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-4">Por método de pago</h3>
            @if (empty($breakdown))
                <p class="text-sm text-gray-400 italic">Sin pagos en el período.</p>
            @else
                <div class="space-y-3">
                    @foreach ($breakdown as $method => $amount)
                        @php $pct = $totalCollected > 0 ? ($amount / $totalCollected) * 100 : 0; @endphp
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $method }}</span>
                                <span class="text-gray-500 dark:text-gray-400">${{ number_format($amount, 2) }} ({{ number_format($pct, 1) }}%)</span>
                            </div>
                            <div class="h-2 rounded-full bg-gray-200 dark:bg-gray-700">
                                <div class="h-2 rounded-full bg-primary-500" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Daily trend --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-5">
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-4">Evolución diaria</h3>
            @if (empty($dailyTrend))
                <p class="text-sm text-gray-400 italic">Sin datos en el período.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                <th class="pb-2 font-semibold">Fecha</th>
                                <th class="pb-2 font-semibold text-right">Total</th>
                                <th class="pb-2 w-1/2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dailyTrend as $day => $amount)
                                @php $pct = $dailyMax > 0 ? ($amount / $dailyMax) * 100 : 0; @endphp
                                <tr class="border-t border-gray-100 dark:border-gray-700">
                                    <td class="py-1.5 text-gray-700 dark:text-gray-300">
                                        {{ \Carbon\Carbon::parse($day)->format('d/m/Y') }}
                                    </td>
                                    <td class="py-1.5 text-right font-medium text-green-600">
                                        ${{ number_format($amount, 2) }}
                                    </td>
                                    <td class="py-1.5 pl-3">
                                        <div class="h-2 rounded-full bg-gray-200 dark:bg-gray-700">
                                            <div class="h-2 rounded-full bg-green-400" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

</x-filament-panels::page>
