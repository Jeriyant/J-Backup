# Paket Produksi J-BACKUP

Folder ini adalah salinan bersih aplikasi untuk dipasang pada server baru.
Paket tidak menyertakan database, akun administrator, token, kredensial SSH,
key enkripsi, log, data RSYNC, maupun hasil BACKUP dari mesin pengembangan.

## Instalasi cepat

1. Unggah isi folder ini ke server, misalnya ke `/opt/j-backup`.
2. Masuk ke folder aplikasi tersebut.
3. Jalankan:

   ```bash
   sudo bash scripts/install-linux.sh
   ```

4. Buka URL yang ditampilkan installer lalu buat akun administrator baru.

Panduan lengkap dan opsi lokasi data tersedia pada `Cara Install.md`.

Untuk menghapus aplikasi tanpa menghapus database dan data backup:

```bash
sudo bash scripts/uninstall-linux.sh
```

## Isi paket

- Kode aplikasi: `api.php`, `index.php`, `src/`, dan `assets/`.
- Worker dan pemeriksa kebutuhan: `bin/`.
- Installer/uninstaller Linux dan konfigurasi Apache/systemd: `scripts/` dan
  `deploy/`.
- `storage/` hanya berisi `.gitignore`; data runtime akan dibuat installer.
