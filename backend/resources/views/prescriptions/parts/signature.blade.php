{{-- FIRMA: «Dr. Nombre», el título de esta atención, la cédula y el contacto
     (de la clínica en una clínica, del médico en su consultorio). --}}
<div class="sign">
    @if ($doctor?->uses_physical_stamp_signature)
        <div class="physical-signature-space"><div class="line"></div></div>
    @else
    @if ($signature)<img src="{{ $signature }}" alt="firma"><br>@endif
    <div class="line">
        <span class="name">{{ \App\Support\LetterheadData::doctorName($doctor) }}</span>
        @if ($specialty)<div class="sign-meta">{{ $specialty }}</div>@endif
        @if ($doctor?->id_number)<div class="sign-meta">C.I.: {{ $doctor->id_number }}</div>@endif
        @php $reach = array_filter([$signatureContact['phone'] ?? null, $signatureContact['email'] ?? null]); @endphp
        @if ($reach)<div class="sign-meta">{{ implode(' · ', $reach) }}</div>@endif
    </div>
    @endif
</div>
