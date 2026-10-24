{{-- OBSERVACIONES (en ambos lados) --}}
<div class="sec">
    <div class="sec-title">Observaciones</div>
    <div class="obs">
        @if ($record->prescriptions->isNotEmpty() && !empty($record->treatment))
            <div><span class="k">Plan / Tratamiento:</span> <span class="v">{{ $record->treatment }}</span></div>
        @endif
        @if ($record->recommendations)
            <div><span class="k">Recomendaciones:</span><br><span class="v">{{ $record->recommendations }}</span></div>
        @endif
        @if ($record->alarm_signs)
            <div><span class="k">Signos de alarma:</span> <span class="v">{{ $record->alarm_signs }}</span></div>
        @endif
        <div><span class="k">Alergias:</span> <span class="v">{{ $allergies ?: 'No refiere' }}</span></div>
        <div><span class="k">Próxima cita:</span> <span class="v">{{ $nextAppointment?->appointment_date?->format('d-m-Y H:i') ?? '' }}</span></div>
    </div>
</div>
