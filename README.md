<p align="center">
  <img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="350" alt="Laravel Logo">
</p>

<h1 align="center">🏨 Hotel Bome</h1>

<p align="center">
  <b>Sistem Manajemen Hotel & Reservasi</b><br>
  Dibangun dengan <a href="https://laravel.com" target="_blank">Laravel 12</a> + <a href="https://filamentphp.com/" target="_blank">Filament v4</a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12-red?style=flat-square&logo=laravel" alt="Laravel">
  <img src="https://img.shields.io/badge/PHP-^8.2-blue?style=flat-square&logo=php" alt="PHP">
  <img src="https://img.shields.io/badge/Filament-v4-8A2BE2?style=flat-square" alt="Filament v4">
  <img src="https://img.shields.io/github/license/mryunkaka/hotel-bome?style=flat-square" alt="License">
  <img src="https://img.shields.io/github/last-commit/mryunkaka/hotel-bome?style=flat-square" alt="Last Commit">
</p>

---

## 📖 Tentang Project

**Hotel Bome** adalah sistem manajemen hotel (Hotel Management System) untuk mengelola:
- **Reservasi**: pencatatan tamu, check-in/out, status, deposit.
- **Tamu (Guests)**: data lengkap tamu (identitas, dokumen, kontak).
- **Kamar (Rooms)**: tipe kamar, harga, fasilitas, import/export data.
- **Panel Admin Modern** dengan **Filament v4**.

Fokus utama adalah kecepatan input, konsistensi data, serta logging yang jelas untuk inspeksi.

---

## ✨ Fitur Utama

✅ Reservasi dengan auto nomor unik  
✅ Data tamu lengkap dengan **Enum salutation**  
✅ Import/Export **Excel**  
✅ Preview & cetak **PDF**  
✅ Single-session login (user hanya bisa login 1 sesi aktif)  
✅ Admin panel modern dengan **Filament v4**  
✅ Logging & audit trail siap integrasi (Spatie Activity Log)  

---

## 🛠️ Tech Stack

- [Laravel 12](https://laravel.com)  
- [Filament v4](https://filamentphp.com)  
- [Livewire 3](https://livewire.laravel.com)  
- [Spatie Laravel Permission](https://spatie.be/docs/laravel-permission)  
- [Maatwebsite Excel](https://laravel-excel.com)  
- [Barryvdh DomPDF](https://github.com/barryvdh/laravel-dompdf)  
- [Intervention Image](http://image.intervention.io/)  

---

## 🚀 Instalasi

### 1. Clone repo
```bash
git clone https://github.com/mryunkaka/hotel-bome.git
cd hotel-bome
```

### 2. Install dependency
```bash
composer install
npm install
```

### 3. Setup environment
Salin file `.env.example` menjadi `.env`
```bash
cp .env.example .env
```

Edit `.env`:
```dotenv
APP_NAME="Hotel Bome"
APP_URL=http://hotel-bome.test

DB_DATABASE=hotel_bome
DB_USERNAME=root
DB_PASSWORD=
```

### 4. Generate key & migrate database
```bash
php artisan key:generate
php artisan migrate --seed
```

### 5. Jalankan aplikasi
```bash
php artisan serve
npm run dev
```

Buka [http://hotel-bome.test/admin](http://hotel-bome.test/admin) (atau sesuai domain Laragon Anda).

---

## 🖥️ Screenshot (contoh UI)

<p align="center">
  <img src="https://filamentphp.com/images/screenshot.png" width="700" alt="Filament Screenshot">
</p>

---

## 📦 Perintah Harian

```bash
# refresh database
php artisan migrate:fresh --seed

# queue worker
php artisan queue:work

# reverb (realtime)
php artisan reverb:start

# optimize (production)
php artisan optimize
```

---

## 📄 Versi & Changelog
Lihat [VERSIONS.md](VERSIONS.md) untuk catatan perubahan.

---

## 🤝 Kontribusi

1. Fork repo ini  
2. Buat branch fitur (`git checkout -b feature/fitur-baru`)  
3. Commit (`git commit -m "feat: tambah fitur baru"`)  
4. Push ke branch (`git push origin feature/fitur-baru`)  
5. Buat Pull Request  

---

## 🔒 Keamanan

- Jangan commit **kunci privat/akses**.  
- Putar ulang (rotate) key jika pernah ter-commit.  
- Gunakan **GitHub PAT** untuk push via HTTPS.  

---

## 📜 Lisensi

Proyek ini dirilis di bawah [MIT License](LICENSE).
