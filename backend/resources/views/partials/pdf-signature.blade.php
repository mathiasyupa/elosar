<table class="signature">
    <tr>
        <td>
            @if ($doctor?->uses_physical_stamp_signature)
                <div style="height:92px; padding-top:72px;"><div class="sign-line"></div></div>
            @else
            @if ($signature)
                <img class="sign-img" src="{{ $signature }}" alt="firma"><br>
            @endif
            <div class="sign-line">
                <span class="sign-name">{{ \App\Support\LetterheadData::doctorName($doctor) }}</span><br>
                <span class="sign-meta">
                    @php $displayProfessionalTitle = $professionalTitle ?? $doctor?->primarySpecialty()?->name; @endphp
                    @if ($displayProfessionalTitle) {{ $displayProfessionalTitle }} @endif
                    @if ($doctor?->medical_license) &nbsp;·&nbsp; Reg. MSP: {{ $doctor->medical_license }} @endif
                    @if ($doctor?->acess_code) &nbsp;·&nbsp; ACESS: {{ $doctor->acess_code }} @endif
                    @if ($doctor?->senescyt_registration) &nbsp;·&nbsp; SENESCYT: {{ $doctor->senescyt_registration }} @endif
                    @php $contact = array_filter([
                        $doctor?->id_number ? 'CI: '.$doctor->id_number : null,
                        !empty($signatureContact['phone']) ? 'Tlf.: '.$signatureContact['phone'] : null,
                        $signatureContact['email'] ?? null,
                    ]); @endphp
                    @if ($contact)<br>{{ implode(' · ', $contact) }}@endif
                </span>
            </div>
            @endif
        </td>
    </tr>
</table>

@if (!empty($settings['footer']))
    <div class="footer">{{ $settings['footer'] }}</div>
@endif
