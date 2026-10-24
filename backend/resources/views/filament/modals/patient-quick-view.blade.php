<div class="space-y-4 text-sm">
    @if ($patient)
        {{-- Datos básicos --}}
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Cédula</p>
                <p class="mt-0.5 text-gray-900 dark:text-white">{{ $patient->id_number ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Edad</p>
                <p class="mt-0.5 text-gray-900 dark:text-white">
                    {{ $patient->birth_date ? $patient->birth_date->age . ' años' : '—' }}
                </p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Grupo sanguíneo</p>
                <p class="mt-0.5 font-semibold text-red-600 dark:text-red-400">
                    {{ $patient->blood_type ?? '—' }}
                </p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Teléfono</p>
                <p class="mt-0.5 text-gray-900 dark:text-white">{{ $patient->phone ?? '—' }}</p>
            </div>
        </div>

        {{-- Alergias --}}
        @if ($patient->allergies && $patient->allergies->count() > 0)
            <div class="rounded-lg bg-red-50 border border-red-200 p-3 dark:bg-red-900/20 dark:border-red-800">
                <p class="text-xs font-medium text-red-700 dark:text-red-400 uppercase tracking-wide mb-1">
                    ⚠ Alergias registradas
                </p>
                <ul class="space-y-0.5">
                    @foreach ($patient->allergies as $allergy)
                        <li class="text-red-800 dark:text-red-300">
                            <span class="font-medium">{{ $allergy->allergen_name }}</span>
                            <span class="text-xs text-red-600 dark:text-red-400">({{ $allergy->severity }})</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            <div class="rounded-lg bg-gray-50 border border-gray-200 p-3 dark:bg-gray-800 dark:border-gray-700">
                <p class="text-xs text-gray-500">Sin alergias conocidas registradas</p>
            </div>
        @endif

        {{-- Medicación actual --}}
        @if (!empty($patient->personal_background['current_medication']))
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Medicación actual</p>
                <p class="mt-0.5 text-gray-900 dark:text-white">{{ $patient->personal_background['current_medication'] }}</p>
            </div>
        @endif

        {{-- Última consulta --}}
        @if ($lastRecord)
            <div class="border-t border-gray-200 dark:border-gray-700 pt-3">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Última consulta</p>
                <p class="text-xs text-gray-500">{{ $lastRecord->created_at->format('d/m/Y') }}</p>
                <p class="text-gray-900 dark:text-white mt-0.5">{{ $lastRecord->diagnosis }}</p>
            </div>
        @endif

        {{-- Seguro médico --}}
        @if ($patient->insurance_provider)
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Seguro médico</p>
                <p class="mt-0.5 text-gray-900 dark:text-white">
                    {{ $patient->insurance_provider }}
                    @if ($patient->insurance_policy_number)
                        <span class="text-gray-500"> — {{ $patient->insurance_policy_number }}</span>
                    @endif
                </p>
            </div>
        @endif

        {{-- Contacto de emergencia --}}
        @if ($patient->emergency_contact_name)
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Contacto de emergencia</p>
                <p class="mt-0.5 text-gray-900 dark:text-white">
                    {{ $patient->emergency_contact_name }}
                    @if ($patient->emergency_contact_phone)
                        — {{ $patient->emergency_contact_phone }}
                    @endif
                </p>
            </div>
        @endif
    @else
        <p class="text-gray-500">No se pudo cargar la información del paciente.</p>
    @endif
</div>
