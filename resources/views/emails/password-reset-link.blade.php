<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $appName }} | Restablecimiento seguro de contraseña</title>
</head>
<body style="margin:0;padding:0;background:#f5f1f2;font-family:Arial,Helvetica,sans-serif;color:#2d2430;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f1f2;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #ead9de;">
                    <tr>
                        <td style="padding:24px 28px;background:linear-gradient(135deg,#7c1734,#d95d2c);color:#ffffff;">
                            <div style="font-size:12px;letter-spacing:1.2px;text-transform:uppercase;opacity:.9;">Seguridad corporativa</div>
                            <h1 style="margin:10px 0 0;font-size:24px;line-height:1.2;">Restablecimiento de contraseña</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 14px;">Hola {{ $recipientName }},</p>
                            <p style="margin:0 0 16px;line-height:1.6;">
                                Recibimos una solicitud para restablecer la contraseña de <strong>{{ $appName }}</strong>.
                                Usa el siguiente enlace seguro para definir una nueva clave:
                            </p>
                            <div style="margin:24px 0;text-align:center;">
                                <a href="{{ $resetUrl }}" style="display:inline-block;padding:14px 24px;border-radius:999px;background:linear-gradient(135deg,#7c1734,#d95d2c);color:#ffffff;text-decoration:none;font-weight:700;">
                                    Restablecer contraseña
                                </a>
                            </div>
                            <p style="margin:0 0 12px;line-height:1.6;">
                                Este enlace vence en <strong>{{ $expiresMinutes }} minutos</strong> y solo será válido para la solicitud más reciente.
                            </p>
                            <p style="margin:0 0 12px;line-height:1.6;">
                                Si no reconoces esta solicitud, ignora el mensaje y comunícate con el equipo administrador.
                            </p>
                            <p style="margin:16px 0 0;line-height:1.5;font-size:13px;color:#6b5a62;word-break:break-all;">
                                Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
                                <a href="{{ $resetUrl }}" style="color:#7c1734;">{{ $resetUrl }}</a>
                            </p>
                            <p style="margin:24px 0 0;font-size:13px;color:#6b5a62;line-height:1.6;">
                                Soporte: {{ $supportEmail }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
