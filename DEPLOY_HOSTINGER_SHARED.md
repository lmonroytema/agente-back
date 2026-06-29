# Deploy En Hostinger Shared

## Objetivo

Desplegar la app en `https://gm.temalitoclean.com` usando:

- `backend-laravel` como backend principal
- `frontend` compilado dentro de `public/`
- `MySQL` de Hostinger
- `storage/app/tema_litoclean` para artefactos, auditoria y configuracion

## Lo Que Ya Quedo Preparado

- `chat` y `capabilities` ya no dependen de Python.
- Laravel sirve `public/index.html` en la ruta `/` si existe.
- El frontend puede usar el mismo dominio en produccion.
- Los artefactos ahora viven en `storage/app/tema_litoclean`.

## Estructura De Publicacion

- `backend-laravel/` se publica en el hosting.
- El `document root` del subdominio debe apuntar a `backend-laravel/public`.
- El frontend compilado se copia dentro de `backend-laravel/public`.

Si prefieres subir todo dentro de `public_html`, usa el layout alternativo ya preparado:

- backend completo en `public_html/laravel_app`
- contenido de `frontend/dist` en la raiz de `public_html`
- `public_html/index.php` ajustado para arrancar Laravel desde `laravel_app`
- `public_html/.htaccess` preparado para `https://gm.temalitoclean.com/`

## Paso A Paso

1. Crear el subdominio `gm.temalitoclean.com` en Hostinger.
2. Apuntar el `document root` del subdominio a la carpeta `public` de Laravel.
3. Crear la base de datos MySQL en Hostinger.
4. Copiar `.env.hostinger.shared.example` como base del archivo `.env` productivo.
5. Completar `APP_KEY`, credenciales MySQL, dominio y variables corporativas.
6. Ejecutar `composer install --no-dev --optimize-autoloader`.
7. Ejecutar `php artisan key:generate` si el `APP_KEY` aun no existe.
8. Ejecutar `php artisan migrate --force`.
9. Ejecutar `php artisan db:seed --force`.
10. Compilar el frontend con `npm run build`.
11. Copiar el contenido de `frontend/dist/` dentro de `backend-laravel/public/`.
12. Verificar que `public/index.html` exista junto con la carpeta `public/assets/`.
13. Dar permisos de escritura a `storage/` y `bootstrap/cache/`.
14. Probar:
    - `https://gm.temalitoclean.com`
    - `https://gm.temalitoclean.com/api/health`
    - subida de archivos
    - generacion de DOCX, XLSX, PPTX y JSON

## Variables Clave

- `APP_URL=https://gm.temalitoclean.com`
- `ASSET_URL=https://gm.temalitoclean.com`
- `APP_STORAGE_DIR=storage/app/tema_litoclean`
- `APP_AUDIT_DIR=storage/app/tema_litoclean/audit`
- `APP_SETTINGS_JSON=storage/app/tema_litoclean/app_settings.json`
- `SESSION_SECURE_COOKIE=true`
- `VITE_API_BASE_URL=https://gm.temalitoclean.com`

## Frontend

El frontend ya soporta este comportamiento:

- en desarrollo local usa `http://127.0.0.1:8000` si detecta el puerto `5173`
- en produccion usa `window.location.origin` si no se define otra URL

## Recomendacion De Build

Desde `frontend/`:

```bash
npm install
npm run build
```

Luego copiar:

- `frontend/dist/index.html` -> `backend-laravel/public/index.html`
- `frontend/dist/assets/*` -> `backend-laravel/public/assets/*`
- `frontend/dist/imagenes/*` -> `backend-laravel/public/imagenes/*`

## Limitaciones De Hosting Compartido

- No usar workers persistentes ni procesos Python.
- Mantener `QUEUE_CONNECTION=sync`.
- Evitar tareas pesadas o cron frecuentes para artefactos masivos.
- Si luego agregas OCR intensivo o procesamiento documental pesado, conviene pasar a VPS.

## Checklist Final

- `php artisan route:list` responde sin errores
- `php artisan migrate --force` ejecutado
- `php artisan db:seed --force` ejecutado
- `public/index.html` existe
- `storage/app/tema_litoclean` existe y es escribible
- `bootstrap/cache` es escribible
- `api/health` responde `200`
- login y administracion responden bien
- upload y descarga de archivos responden bien
