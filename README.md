# Hotel Bome

Hotel Bome is a hotel operations and reservation management application built with Laravel and Filament. It covers reservations, guests, rooms, facilities, minibar operations, payment records, and Excel/PDF workflows.

## Current stack

| Component | Version |
| --- | --- |
| PHP | 8.3 or newer; Windows deployment example uses PHP 8.5.12 NTS VS17 x64 |
| Laravel | 13.38 |
| Filament | 5.11 |
| Livewire | 4.4 |
| MySQL | 8.4.13 LTS recommended |
| Node.js | 20.19+ or 22.12+ |
| Vite / Tailwind CSS | 7.3 / 4.3 |

For the dependency details and update notes, see [VERSIONS.md](VERSIONS.md).

## Features

- Hotel, room, guest, and reservation management
- Facility status, booking, and calendar views
- Minibar stock, receipts, and daily closing workflows
- Excel import/export and PDF output
- Role-based access with Spatie Laravel Permission
- RFID bridge for supported card readers

## Requirements

- PHP 8.3+ with the extensions required by Composer; for MySQL, enable `pdo_mysql`
- Composer 2
- MySQL 8.4 LTS, or another database supported by Laravel
- Node.js 20.19+ or 22.12+ and npm, only when building frontend assets
- Windows Server/Desktop for the Windows service example below

## Windows downloads used by the example

Download software from its official source and extract/install it to the paths shown. Do not commit the downloaded binaries to this repository.

| Software | Example path | Official download |
| --- | --- | --- |
| NGINX 1.30.6 stable | `C:\Server\nginx-1.30.6` | [nginx.org/download](https://nginx.org/download/) |
| NSSM 2.24 x64 | `C:\Server\nssm-2.24\win64\nssm.exe` | [nssm.cc/download](https://nssm.cc/download) |
| PHP 8.5.12 NTS VS17 x64 | `C:\Server\php-8.5.12-nts-Win32-vs17-x64` | [PHP for Windows downloads and archives](https://windows.php.net/downloads/) |
| MySQL Community Server 8.4 LTS | Installed by MySQL Installer | [MySQL 8.4 downloads](https://dev.mysql.com/downloads/mysql/8.4.html) |
| Visual C++ runtime | Microsoft Visual C++ 2015–2022 Redistributable x64 | [Microsoft download](https://aka.ms/vs/17/release/vc_redist.x64.exe) |

The PHP project requires PHP 8.3 or newer. The exact PHP folder above follows the deployment version requested for this project; install the matching Visual C++ runtime and verify the binary with `php.exe -v` and `php-cgi.exe -v`.

NGINX for Windows is a console application rather than a native Windows service. NGINX classifies the Windows build as beta and notes that high performance and scalability should not be expected. NSSM wraps it as a service for this setup; use a supported Linux NGINX deployment for workloads that need high scalability. NSSM 2.24 is the requested stable release. Its maintainers note that Windows 10 and newer may need the 2.24-101 prerelease or the `AppNoConsole` workaround; the commands below set that workaround.

## Install the application

Run PowerShell as the account that will own the project files:

```powershell
git clone https://github.com/mryunkaka/hotel-bome.git D:\Project\hotel-bome
Set-Location D:\Project\hotel-bome

composer install --no-dev --optimize-autoloader
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

Edit `.env` before running Artisan. Set a unique `APP_KEY`, production `APP_ENV`, `APP_DEBUG=false`, the public `APP_URL`, and the database credentials. Never commit `.env` or a database dump.

Build the frontend assets once on the deployment machine or in CI, then deploy the generated `public/build` directory with the application:

```powershell
npm ci
npm run build
```

## Create the MySQL database

Install MySQL Community Server 8.4 LTS with the official Windows MSI. The installer creates and manages its own Windows service; do not wrap MySQL with NSSM.

Create an empty database and a dedicated account. Replace the sample password with a long, unique value:

```sql
CREATE DATABASE hotel_bome CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hotel_bome'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_A_UNIQUE_PASSWORD';
GRANT ALL PRIVILEGES ON hotel_bome.* TO 'hotel_bome'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Set these values in `.env`:

```dotenv
APP_URL=http://localhost:8084
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hotel_bome
DB_USERNAME=hotel_bome
DB_PASSWORD=REPLACE_WITH_A_UNIQUE_PASSWORD
```

After editing `.env`, clear Laravel's cached configuration before checking the connection:

```powershell
php artisan config:clear
php artisan migrate:status
```

## Database migration and SQL backup workflow

### New database

The repository's Laravel migrations are the source of truth for the schema. After creating an empty database and setting `.env`, run:

```powershell
php artisan key:generate --force
php artisan config:clear
php artisan migrate --force
php artisan migrate:status
php artisan storage:link
php artisan optimize
```

`migrate --force` applies only migrations that have not run yet; it does not erase existing tables. Keep the generated `migrations` table with the application database so Laravel can track completed migrations.

The seeders insert sample hotels, rooms, guests, banks, roles, and users. They are intended for development/demo databases, not a public production database. In particular, review `InitialSetupSeeder` before using it outside development: it contains demonstration accounts with a weak default password. Do not expose a newly seeded system publicly until every demo account has been removed or given a strong, unique password.

To load sample data in a disposable development database only:

```powershell
php artisan db:seed
```

Do not use `migrate:fresh` on a database that contains data. It drops all tables.

### Back up before a production migration

Take and verify a database backup before deploying code that includes new migrations. Example using MySQL's command-line tools:

```powershell
mysqldump.exe --single-transaction --routines --triggers --user=hotel_bome --password --result-file="D:\Backups\hotel_bome-before-migration.sql" hotel_bome
```

The command prompts for the database password. Store backups outside the web root and repository, and restrict access to them.

For a full SQL dump, restore it into a newly created, empty target database using the MySQL client, then run `php artisan migrate --force`. In Command Prompt (not PowerShell), the import command is:

```bat
"C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" --host=127.0.0.1 --user=hotel_bome --password --database=hotel_bome < "D:\Backups\hotel_bome.sql"
```

Confirm that the dump includes Laravel's `migrations` table; otherwise Laravel cannot know which schema changes have already been applied. Do not import a dump over a live database without a tested backup and a planned maintenance window.

### Deploy later schema changes

1. Put the application in maintenance mode or stop incoming writes.
2. Back up the database.
3. Deploy the new application code and run `composer install --no-dev --optimize-autoloader`.
4. Update `.env` if required, then run `php artisan config:clear`.
5. Run `php artisan migrate --force` and inspect `php artisan migrate:status`.
6. Run `php artisan optimize`, restart the NGINX and PHP-CGI services, and verify the login page and key workflows.
7. Bring the application back online.

Rollback is application-specific. Review each migration before using `php artisan migrate:rollback`; restore the verified backup if a migration cannot be safely reversed.

## NGINX configuration

The repository includes [deploy/windows/nginx.conf](deploy/windows/nginx.conf). It serves the application on `http://localhost:8084`, points the document root to `D:/Project/hotel-bome/public`, and forwards PHP requests to `127.0.0.1:9000`. Change the `root` path if the project is installed elsewhere, then copy the file to `C:\Server\nginx-1.30.6\conf\nginx.conf`:

```powershell
Copy-Item .\deploy\windows\nginx.conf 'C:\Server\nginx-1.30.6\conf\nginx.conf' -Force
```

The NGINX document root must be Laravel's `public` directory, never the project root. Create Laravel's public storage link after configuring the environment:

```powershell
php artisan storage:link
```

Ensure the Windows account running the services can read the application files and can write to `storage`, `bootstrap/cache`, and the NGINX log directory.

## Register PHP-CGI and NGINX with NSSM

Use an elevated PowerShell window after installing the software and copying `nginx.conf`. This creates **two separate NSSM services**. PHP-CGI listens only on loopback; NGINX accepts browser traffic on port 8084. MySQL remains under the service created by its own installer.

```powershell
$nssm = 'C:\Server\nssm-2.24\win64\nssm.exe'
$php = 'C:\Server\php-8.5.12-nts-Win32-vs17-x64'
$nginx = 'C:\Server\nginx-1.30.6'
$project = 'D:\Project\hotel-bome'
New-Item -ItemType Directory -Force "$project\storage\logs" | Out-Null

# Check NGINX configuration before installing services.
Push-Location $nginx
& .\nginx.exe -t
if ($LASTEXITCODE -ne 0) { Pop-Location; throw 'NGINX configuration test failed.' }
Pop-Location

# PHP FastCGI service.
& $nssm install HotelBomePhpCgi "$php\php-cgi.exe" '-b 127.0.0.1:9000'
& $nssm set HotelBomePhpCgi AppDirectory $php
& $nssm set HotelBomePhpCgi Start SERVICE_AUTO_START
& $nssm set HotelBomePhpCgi AppNoConsole 1
& $nssm set HotelBomePhpCgi AppExit Default Restart
& $nssm set HotelBomePhpCgi AppStdout "$project\storage\logs\php-cgi-service.log"
& $nssm set HotelBomePhpCgi AppStderr "$project\storage\logs\php-cgi-error.log"

# NGINX service. AppDirectory makes NGINX use its own conf/nginx.conf.
& $nssm install HotelBomeNginx "$nginx\nginx.exe"
& $nssm set HotelBomeNginx AppDirectory $nginx
& $nssm set HotelBomeNginx Start SERVICE_AUTO_START
& $nssm set HotelBomeNginx AppNoConsole 1
& $nssm set HotelBomeNginx AppExit Default Restart
& $nssm set HotelBomeNginx AppStdout "$nginx\logs\service-stdout.log"
& $nssm set HotelBomeNginx AppStderr "$nginx\logs\service-stderr.log"

Start-Service HotelBomePhpCgi
Start-Service HotelBomeNginx
Get-Service HotelBomePhpCgi, HotelBomeNginx
```

Run the commands once. If either service already exists, inspect it with `services.msc` or `nssm edit <service>` instead of installing a duplicate. The sample uses NSSM's default service identity; before exposing a public server, use a restricted service account and grant it only the required read/write permissions. Allow inbound TCP port 8084 in Windows Firewall only when remote clients need access.

## Verify and operate

```powershell
php artisan migrate:status
php artisan route:list --except-vendor
php artisan test
```

Check service state and logs:

```powershell
Get-Service HotelBomePhpCgi, HotelBomeNginx
Get-Content 'C:\Server\nginx-1.30.6\logs\error.log' -Tail 100
```

Open `http://localhost:8084/admin`. If NGINX returns `502 Bad Gateway`, verify that the PHP-CGI service is running and listening on `127.0.0.1:9000`. If CSS or JavaScript is missing, rebuild assets with `npm ci` and `npm run build`, then check `public/build/manifest.json`.

For RFID bridge source and scripts, see [bridge/bridge-auto.js](bridge/bridge-auto.js) and [bridge/package.json](bridge/package.json).

## Tests and security checks

```powershell
composer test
composer audit
npm audit
Push-Location bridge
npm ci
npm test
npm audit --omit=dev
Pop-Location
```

## License

Hotel Bome is distributed under the [MIT License](LICENSE).
