# GunyDrive Backend & Admin Dashboard

Backend **Laravel 13** yang menyediakan **API v1** untuk aplikasi mobile GunyDrive serta **Dashboard Admin** (Inertia 3, React 19, Tailwind CSS 4) untuk pengelolaan pengguna, kapasitas penyimpanan, pemantauan API, dan penjelajahan struktur backup berkas.

- Repositori Backend: [hendra-it19/Guny-Drive-Backend](https://github.com/hendra-it19/Guny-Drive-Backend)
- Repositori Induk: [hendra-it19/Guny-Drive](https://github.com/hendra-it19/Guny-Drive)

---

## Fitur Utama

### Dashboard Admin (`/dashboard`)
- **Ringkasan Server**: Statistik pemakaian penyimpanan global, pengguna aktif, sesi transfer, dan kesehatan worker.
- **Pengguna & Storage (`/users`)**:
  - Pencarian pengguna (nama, email, username), filter (*Admin*, *Belum Verifikasi*, *Kuota ≥ 80%*), pengurutan, dan rincian penggunaan ruang per kategori.
  - Pengaturan kuota & batas maksimal per berkas (termasuk mode *Tanpa batas per berkas* / `max_file_bytes: 0`), sinkronisasi ulang kuota, verifikasi email manual, ubah peran Admin secara aman, kosongkan Sampah user, dan pencabutan token perangkat/integrasi.
- **Explorer Storage & File per Pengguna (`/users/{user}/storage`)**:
  - Halaman penuh bergaya *file explorer* dengan pohon folder backup di sidebar, breadcrumb, mode tampilan **Daftar / Grid Sedang / Grid Rapat**, sorting, pencarian, filter kategori, dan tab **Sampah**.
  - Pratinjau *on-demand* berkas asli saat diklik (gambar dengan zoom, pemutaran video/audio dengan HTTP Range seek, PDF, dan teks/JSON aman) serta aksi unduh, pulihkan, pindah ke Sampah, atau hapus permanen.
  - **Isolasi ketat Folder Privat**: Seluruh nama berkas, folder, thumbnail, dan konten pada `Space::Private` tidak pernah diekspos di panel admin (selalu `404`).
- **Penyimpanan (`/dashboard/storage`)**: Monitoring volume disk backup, kebijakan default, pembersihan otomatis berkas/sesi kedaluwarsa, pembatalan sesi unggah macet, dan pembuatan *storage link* (`public/storage`).
- **Akses API (`/api-access`) & Monitoring (`/monitoring`)**: Penerbitan endpoint & kredensial integrasi, tabel log API dan audit keamanan terpaginasi dengan filter metode HTTP, kategori status (`2xx`/`4xx`/`5xx`), pencarian, serta tombol *prune* log lama.

### Sistem Thumbnail Teroptimasi
- **Metadata Database**: Tabel `drive_files` menyimpan `thumbnail_preset`, `thumbnail_mime`, `thumbnail_size_bytes`, dan `thumbnail_status`.
- **Dokumen & Non-Media** (`pdf`, `word`, `excel`, `powerpoint`, `text`, `code`, `audio`, `archive`, `file`):
  - Tidak menyimpan berkas thumbnail per file di disk (`thumbnail_key = null`, `thumbnail_size_bytes = 0`).
  - Dilayani lewat endpoint vektor SVG publik ter-cache permanen (`public, max-age=604800, immutable`) di `/thumbnails/defaults/{preset}.svg` dan `/api/v1/thumbnails/defaults/{preset}.svg`.
- **Foto & Video**:
  - Diproses otomatis dari file asli saat unggahan selesai (atau *lazy* jika belum tersedia) menjadi thumbnail `≤ 320px` berformat **WebP** (dengan fallback progressive JPEG), lengkap dengan koreksi orientasi EXIF (GD) untuk foto dan ekstraksi frame `ffmpeg` untuk video.

### API v1 (`/api/v1`)
- Autentikasi perangkat: registrasi + verifikasi email, login email/username, Google OAuth, Passkey biometrik, dan manajemen sesi.
- Katalog berkas & folder: daftar cursor-paginated, buat/ubah nama/pindah/salin/hapus berkas & folder (`POST /files/{id}/copy`, `POST /folders/{id}/copy`), pratinjau Sampah (`?include_trashed=1`), dan endpoint `/media`.
- Unggah resumable berbasis offset dengan reservasi kuota atomik, deduplikasi opsional, dan verifikasi **SHA-256**.
- Ruang **Folder Privat** (`/vault/*`) dengan sesi PIN sementara (`X-Vault-Token`).

---

## Instalasi Lokal

1. Siapkan **PHP 8.5** (ekstensi `pdo_mysql`, `pdo_sqlite`, `gd`, serta `ffmpeg` opsional untuk ekstraksi frame video), **Composer 2**, **Node.js 22.18+**, **npm**, dan server **MySQL**.
2. Salin `.env.example` ke `.env` pada checkout baru, lalu atur koneksi database & admin awal:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gunydrive
DB_USERNAME=gunydrive
DB_PASSWORD=

GUNYDRIVE_ADMIN_EMAIL=admin@example.com
GUNYDRIVE_ADMIN_PASSWORD=ganti-kata-sandi-minimal-12-karakter
```

3. Jalankan instalasi awal:

```sh
composer setup
php artisan db:seed --class=AdminSeeder
composer run dev
```

Untuk pembaruan instalasi yang sudah ada, pertahankan `APP_KEY` lalu jalankan:

```sh
php artisan migrate
npm run build
```

---

## Konfigurasi Email (SMTP) & Produksi

Atur pengiriman email verifikasi dan reset kata sandi pada `.env`:

| Variabel | Pengaturan |
| --- | --- |
| `MAIL_MAILER` | Gunakan `smtp` pada produksi (`log` hanya menulis ke berkas log). |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME` | Host, port (`587` STARTTLS atau `465` SMTPS), dan skema penyedia SMTP. |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | Kredensial SMTP (simpan hanya di `.env` / secret server). |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Alamat dan nama pengirim resmi. |
| `APP_URL` | URL HTTPS publik backend untuk tautan verifikasi dan OAuth. |

Untuk login Google mobile, isi `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, dan `GOOGLE_REDIRECT_URI` (mis. `https://drive.example.com/mobile/google/callback`). Pada cPanel/hosting bersama, arahkan *document root* ke direktori `public/` (atau atur `GUNYDRIVE_PUBLIC_PATH` jika memakai `public_html` terpisah), aktifkan HTTPS, `APP_DEBUG=false`, worker antrean, scheduler (`gunydrive:cleanup`), dan buat *storage link* melalui `/dashboard/storage`.

---

## Pengujian & Verifikasi

```sh
# Format otomatis (Pint & Vite+/OxLint)
composer lint

# Pemeriksaan CI lengkap (vp check, tsc, Pint, PHPStan, dan Pest tests)
composer ci:check

# Build aset produksi dashboard
npm run build
```

Pengujian [PHPUnit / Pest](phpunit.xml) menggunakan SQLite dalam memori dan mailer `array` secara terisolasi.

[Kontrak API v1](../docs/openapi-v1.yaml) · [Operasi & Pemulihan](../docs/operasi-pemulihan.md) · [Panduan Proyek Induk](../README.md)
