# Patch tampilan J-BACKUP

Patch ini hanya mengganti dua aset antarmuka:

- `assets/app.js`
- `assets/app.css`
- satu bagian tindakan reset pada `api.php`

## Perubahan

- Ikon sumber otomatis memakai inisial nama sumber.
- Inisial sumber tampil sebagai mini-logo hitam/putih yang mengikuti tema aplikasi.
- ID sumber ditampilkan dalam kapsul pada setiap baris.
- Kapsul sumber hanya menampilkan nomor tanpa awalan `ID`.
- Riwayat pekerjaan selalu diurutkan dari waktu terbaru ke terlama.
- Reset Database tidak lagi mencabut public key dari server sumber.
- Disk tujuan Realtime ditampilkan pada menu Penyimpanan.
- Mount sistem WSL seperti `/usr/lib/wsl/drivers` ditempatkan paling bawah.
- Teks informasi koneksi SSH dibuat lebih kecil.

## Pemasangan di server produksi

Ganti `/lokasi/J-Backup` dengan lokasi instalasi J-BACKUP di server.

```bash
cd /lokasi/J-Backup

sudo cp -a assets/app.js assets/app.js.sebelum-patch
sudo cp -a assets/app.css assets/app.css.sebelum-patch
sudo cp -a api.php api.php.sebelum-patch

sudo cp /lokasi/folder-patch/assets/app.js assets/app.js
sudo cp /lokasi/folder-patch/assets/app.css assets/app.css
sudo patch -p1 < /lokasi/folder-patch/api-reset-public-key.patch

sudo systemctl reload apache2
```

Patch ini tidak mengubah database, konfigurasi, password SSH, key SSH lokal, worker, maupun file hasil backup.

Setelah pemasangan, lakukan hard refresh pada browser dengan `Ctrl+F5`.

## Membatalkan patch

```bash
cd /lokasi/J-Backup

sudo mv assets/app.js.sebelum-patch assets/app.js
sudo mv assets/app.css.sebelum-patch assets/app.css
sudo mv api.php.sebelum-patch api.php

sudo systemctl reload apache2
```

## SHA-256

```text
assets/app.js   2C32A1D146CBBDF570C12FAFD4BF3385514D54230B20EA3CE669E83DF6A1AF8E
assets/app.css  F302BB57C081C715DF57ABE2316D73A8D721496DF2F9AB07DE882B7B8828C1B0
api patch       1CCD5F538A69082E44AAF597EEBBFB86E1EB42E85EE5698C1CE43683CFC82B9F
```
