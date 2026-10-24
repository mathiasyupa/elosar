<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; color: #1f2430; line-height: 1.5;">
    <h2 style="color: #4f3f7a;">Te han invitado a {{ $clinicName }}</h2>
    <p>Has sido invitado a unirte como <strong>{{ $roleLabel }}</strong>.</p>
    <p>Crea tu cuenta y comienza a trabajar con el equipo:</p>
    <p>
        <a href="{{ $redeemUrl }}"
           style="background: #4f3f7a; color: #fff; padding: 12px 22px; border-radius: 8px; text-decoration: none; display: inline-block;">
            Crear mi cuenta
        </a>
    </p>
    <p style="font-size: 12px; color: #6b7280;">
        Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
        {{ $redeemUrl }}
    </p>
    <p style="font-size: 12px; color: #6b7280;">Esta invitación caduca en 14 días.</p>
</body>
</html>
