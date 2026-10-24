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
        $total     = $this->getTotalAppointments();
        $noShows   = $this->getNoShows();
        $statuses  = $this->getByStatus();
        $completed = $statuses['Completadas'] ?? 0;
        $cancelled = $statuses['Canceladas']  ?? 0;
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 border border-gray-100 dark:border-gray-700">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total citas</p>
            <p class="text-3xl font-bold text-blue-600 mt-1">{{ $total }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 border border-gray-100 dark:border-gray-700">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Completadas</p>
            <p class="text-3xl font-bold text-green-600 mt-1">{{ $completed }}</p>
            @if ($total > 0)
                <p class="text-xs text-gray-400 mt-1">{{ number_format(($completed / $total) * 100, 1) }}% del total</p>
            @endif
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 border border-gray-100 dark:border-gray-700">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Canceladas</p>
            <p class="text-3xl font-bold text-red-500 mt-1">{{ $cancelled }}</p>
            @if ($total > 0)
                <p class="text-xs text-gray-400 mt-1">{{ number_format(($cancelled / $total) * 100, 1) }}% del total</p>
            @endif
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 border border-gray-100 dark:border-gray-700">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">No-shows</p>
            <p class="text-3xl font-bold text-orange-500 mt-1">{{ $noShows }}</p>
            <p class="text-xs text-gray-400 mt-1">Agendadas con fecha pasada</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        {{-- By status --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-5">
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-4">Por estado</h3>
            @if (empty($statuses))
                <p class="text-sm text-gray-400 italic">Sin citas en el período.</p>
            @else
                <div class="space-y-3">
                    @foreach ($statuses as $status => $count)
                        @php $pct = $total > 0 ? ($count / $total) * 100 : 0; @endphp
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $status }}</span>
                                <span class="text-gray-500">{{ $count }} ({{ number_format($pct, 1) }}%)</span>
                            </div>
                            <div class="h-2 rounded-full bg-gray-200 dark:bg-gray-700">
                                <div class="h-2 rounded-full {{ match($status) {
                                    'Completadas' => 'bg-green-400',
                                    'Canceladas'  => 'bg-red-400',
                                    default       => 'bg-blue-400',
                                } }}" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- By appointment type --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-5">
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-4">Por tipo de cita</h3>
            @php $types = $this->getByType(); @endphp
            @if (empty($types))
                <p class="text-sm text-gray-400 italic">Sin tipos registrados.</p>
            @else
                <div class="space-y-3">
                    @foreach ($types as $type => $count)
                        @php $pct = $total > 0 ? ($count / $total) * 100 : 0; @endphp
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $type }}</span>
                                <span class="text-gray-500">{{ $count }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-gray-200 dark:bg-gray-700">
                                <div class="h-2 rounded-full bg-purple-400" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- By doctor --}}
    @php $byDoctor = $this->getByDoctor(); @endphp
    @if (!empty($byDoctor))
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-5">
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-4">Por médico</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                            <th class="pb-2 font-semibold">Médico</th>
                            <th class="pb-2 font-semibold text-center">Total</th>
                            <th class="pb-2 font-semibold text-center text-green-600">Completadas</th>
                            <th class="pb-2 font-semibold text-center text-blue-600">Agendadas</th>
                            <th class="pb-2 font-semibold text-center text-red-500">Canceladas</th>
                            <th class="pb-2 font-semibold text-center">% Asistencia</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byDoctor as $doctorName => $stats)
                            @php
                                $attendanceRate = $stats['total'] > 0
                                    ? round(($stats['completed'] / $stats['total']) * 100, 1)
                                    : 0;
                            @endphp
                            <tr class="border-t border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-750">
                                <td class="py-2 font-medium text-gray-800 dark:text-gray-200">{{ $doctorName }}</td>
                                <td class="py-2 text-center font-bold">{{ $stats['total'] }}</td>
                                <td class="py-2 text-center text-green-600">{{ $stats['completed'] }}</td>
                                <td class="py-2 text-center text-blue-600">{{ $stats['scheduled'] }}</td>
                                <td class="py-2 text-center text-red-500">{{ $stats['cancelled'] }}</td>
                                <td class="py-2 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $attendanceRate >= 75 ? 'bg-green-100 text-green-700' : ($attendanceRate >= 50 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                                        {{ $attendanceRate }}%
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</x-filament-panels::page>
