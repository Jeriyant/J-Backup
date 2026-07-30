# Patch tampilan J-BACKUP

Patch ini dibuat dari commit `697326e` dan hanya mengganti dua aset antarmuka:

- `assets/app.js`
- `assets/app.css`

## Perubahan

- Ikon sumber otomatis memakai inisial nama sumber.
- Disk tujuan Realtime ditampilkan pada menu Penyimpanan.
- Mount sistem WSL seperti `/usr/lib/wsl/drivers` ditempatkan paling bawah.
- Teks informasi koneksi SSH dibuat lebih kecil.

## Pemasangan di server produksi

Ganti `/lokasi/J-Backup` dengan lokasi instalasi J-BACKUP di server.

```bash
cd /lokasi/J-Backup

sudo cp -a assets/app.js assets/app.js.sebelum-patch
sudo cp -a assets/app.css assets/app.css.sebelum-patch

sudo cp /lokasi/folder-patch/assets/app.js assets/app.js
sudo cp /lokasi/folder-patch/assets/app.css assets/app.css

sudo systemctl reload apache2
```

Patch ini tidak mengubah database, konfigurasi, password SSH, key SSH, worker, maupun file hasil backup.

Setelah pemasangan, lakukan hard refresh pada browser dengan `Ctrl+F5`.

## Membatalkan patch

```bash
cd /lokasi/J-Backup

sudo mv assets/app.js.sebelum-patch assets/app.js
sudo mv assets/app.css.sebelum-patch assets/app.css

sudo systemctl reload apache2
```

## SHA-256

```text
assets/app.js   565DE4E9FFBD129FE683755473C03D1F56AD1007E9788B3A8476475269CB3ACF
assets/app.css  EB91CB6537D4F9D89015AB3916F048CD8A74E39C96EB9023CE2D14B7691C93AF
```
