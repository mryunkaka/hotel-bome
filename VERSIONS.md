# Versions and changelog

## 2026-10-27

- PHP minimum: 8.3.
- Laravel Framework: 13.38.
- Filament: 5.11 with Livewire 4.4.
- Laravel Excel: 4.0; imports and exports now declare the required return types.
- Intervention Image: 4.4.
- Frontend build: Node.js 20.19+ or 22.12+, Vite 7.3, Tailwind CSS 4.3.
- RFID bridge: Node.js 20.18.1+, with `uiohook-napi` for keyboard-wedge mode.
- Windows deployment example: NGINX 1.30.6, PHP-CGI 8.5.12 NTS VS17 x64, NSSM 2.24, and MySQL 8.4 LTS.

Also corrected the Nord theme build for Filament 5, reduced the public app stylesheet, fixed the facilities board empty state and calendar filtering, preserved zero/trailing zeroes in bank account imports, and removed the duplicate dashboard navigation entry. Added Windows deployment and SQL migration guidance, an NGINX configuration, and the MIT license.
