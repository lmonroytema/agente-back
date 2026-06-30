<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $appName }} | Codigo de doble autenticacion</title>
</head>
<body style="margin:0;padding:0;background:#f5f1f2;font-family:Arial,Helvetica,sans-serif;color:#2d2430;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f1f2;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #ead9de;">
                    <tr>
                        <td style="padding:24px 28px;background:linear-gradient(135deg,#7c1734,#d95d2c);color:#ffffff;">
                            <div style="font-size:12px;letter-spacing:1.2px;text-transform:uppercase;opacity:.9;">Seguridad corporativa</div>
                            <h1 style="margin:10px 0 0;font-size:24px;line-height:1.2;">Codigo de doble autenticacion</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 14px;">Hola {{ $recipientName }},</p>
                            <p style="margin:0 0 16px;line-height:1.6;">
                                Recibimos una solicitud de acceso a <strong>{{ $appName }}</strong>. Usa el siguiente codigo para completar tu inicio de sesion:
                            </p>
                            <div style="margin:24px 0;padding:18px 20px;border-radius:16px;background:#f9edf0;border:1px solid #efd7de;text-align:center;">
                                <div style="font-size:32px;letter-spacing:8px;font-weight:700;color:#7c1734;">{{ $code }}</div>
                            </div>
                            <p style="margin:0 0 12px;line-height:1.6;">
                                Este codigo vence en <strong>{{ $expiresMinutes }} minutos</strong> y solo puede usarse una vez.
                            </p>
                            <p style="margin:0 0 12px;line-height:1.6;">
                                Si no reconoces este intento, ignora el mensaje y notifica al equipo administrador.
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
