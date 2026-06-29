# Deploy Con Todo Dentro De public_html

## Cuándo Usar Este Esquema

Usa este esquema solo si tu hosting compartido no te deja apuntar el dominio o subdominio directamente a `backend-laravel/public`.

En este modelo:

- `public_html/index.php` arranca Laravel
- el código completo del backend queda en `public_html/laravel_app`
- el contenido de `frontend/dist` queda en la raíz de `public_html`
- `public_html/laravel_app/.htaccess` bloquea el acceso directo a esa carpeta
- `public_html/.htaccess` fuerza el dominio `https://gm.temalitoclean.com/` y reenvía las rutas a Laravel

## Script Preparado

Ejecuta:

```powershell
cd c:\trabajos\agente
.\scripts\build_hostinger_public_html_package.ps1
```

Esto genera:

```text
deploy/
  hostinger_shared/
    README.txt
    public_html/
      index.php
      .htaccess
      assets/
      imagenes/
      laravel_app/
```

## Qué Subir Al Hosting

Sube el contenido de:

- `deploy/hostinger_shared/public_html/`

dentro de:

- `public_html/` del hosting

La distribución queda así:

- `public_html/index.php`: front controller de Laravel ajustado al subdirectorio `laravel_app`
- `public_html/.htaccess`: canonicalización a `https://gm.temalitoclean.com/` y fallback a Laravel
- `public_html/assets/*`, `public_html/imagenes/*`, `public_html/index.html`: frontend compilado
- `public_html/laravel_app/*`: backend completo

## Variables Recomendadas

Base sugerida:

- [\.env.hostinger.shared.example](file:///c:/trabajos/agente/backend-laravel/.env.hostinger.shared.example)

Valores clave:

- `APP_URL=https://gm.temalitoclean.com`
- `ASSET_URL=https://gm.temalitoclean.com`
- `SESSION_DOMAIN=gm.temalitoclean.com`
- `SESSION_SECURE_COOKIE=true`
- `VITE_API_BASE_URL=https://gm.temalitoclean.com`

## Después De Subir

1. Verifica que exista `public_html/laravel_app/.env`
2. Verifica permisos sobre:
   - `public_html/laravel_app/storage`
   - `public_html/laravel_app/bootstrap/cache`
3. Ejecuta si el hosting lo permite:
   - `php artisan migrate --force`
   - `php artisan db:seed --force`
4. Prueba:
   - `/`
   - `/api/health`
   - `/api/config`
   - subida de archivos
   - generación de documentos

## Recomendación

Si Hostinger te permite apuntar el subdominio directamente a `backend-laravel/public`, esa opción sigue siendo más limpia.

Si no te lo permite, este paquete `public_html` ya queda listo para subir sin tocar manualmente `index.php`.
