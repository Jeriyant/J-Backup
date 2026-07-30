# Cara Install J-BACKUP di Debian

J-BACKUP dipasang sebagai aplikasi PHP pada Apache. Web dan worker memakai
database yang sama di dalam folder aplikasi:

```text
/var/www/html/J-Backup/storage/j-backup.sqlite
```

## 1. Upload aplikasi

Upload seluruh isi proyek ke:

```text
/var/www/html/J-Backup
```

Contoh menggunakan `scp` dari komputer lokal:

```bash
scp -r J-Backup user@ALAMAT-SERVER:/tmp/
ssh user@ALAMAT-SERVER
sudo mkdir -p /var/www/html
sudo cp -a /tmp/J-Backup /var/www/html/J-Backup
```

Jangan hanya mengupload `index.php`. Folder `assets`, `bin`, `deploy`, `src`,
`scripts`, dan file lainnya harus ikut lengkap.

## 2. Jalankan script install

Masuk ke folder aplikasi:

```bash
cd /var/www/html/J-Backup
sudo bash scripts/install-linux.sh
```

Installer akan:

- memasang Apache, PHP 8.2+, SQLite, Sodium, rsync, 7-Zip, OpenSSH, dan
  `sshpass`;
- memverifikasi setiap paket dengan status `OK` atau `GAGAL`;
- membuat user sistem `jbackup`;
- membuat folder data, staging, backup, SSH, dan key enkripsi;
- memasang konfigurasi Apache;
- mengaktifkan worker setiap 15 detik.

## 3. Lokasi dan izin folder

Lokasi yang digunakan:

```text
/var/www/html/J-Backup           file aplikasi
/var/www/html/J-Backup/storage   database, key enkripsi, SSH, dan staging
/var/backups/j-backup            hasil backup
/var/log/j-backup                log operasional
```

Installer mengatur izin secara otomatis. Untuk memeriksanya:

```bash
sudo chown -R root:root /var/www/html/J-Backup
sudo chown -R jbackup:jbackup /var/www/html/J-Backup/storage /var/backups/j-backup
sudo chmod 770 /var/www/html/J-Backup/storage /var/backups/j-backup
sudo chmod 640 /var/www/html/J-Backup/storage/secret.key
```

User Apache `www-data` dimasukkan ke grup `jbackup` agar web dapat memakai
database dan folder backup tanpa menjalankan Apache sebagai root.

### Migrasi dari versi lama

Versi lama mungkin menyimpan database di `/var/lib/j-backup` atau folder proyek
lain. Hentikan web dan worker sebelum menyalinnya:

```bash
sudo systemctl stop j-backup-worker.timer apache2
sudo mkdir -p /var/www/html/J-Backup/storage
sudo cp -a /var/lib/j-backup/j-backup.sqlite* /var/www/html/J-Backup/storage/ 2>/dev/null || true
sudo cp -a /var/lib/j-backup/secret.key /var/www/html/J-Backup/storage/ 2>/dev/null || true
sudo cp -a /var/lib/j-backup/.ssh /var/www/html/J-Backup/storage/ 2>/dev/null || true
sudo chown -R jbackup:jbackup /var/www/html/J-Backup/storage
sudo systemctl start apache2 j-backup-worker.timer
```

Jika database yang ingin dipertahankan berada di folder proyek WSL
`/mnt/e/Cursor-Project/J-Backup/storage`, ganti `/var/lib/j-backup` pada
perintah di atas dengan lokasi tersebut. Jangan menggabungkan dua file SQLite;
pilih satu database yang datanya ingin dipertahankan.

## 4. Buka aplikasi

Buka:

```text
http://ALAMAT-SERVER/J-Backup/
```

Jika dipasang di WSL pada komputer yang sama:

```text
http://localhost/J-Backup/
```

Lakukan refresh penuh dengan `Ctrl+F5` setelah memperbarui aplikasi.

## 5. Setup aplikasi

Saat pertama dibuka:

1. Buat username administrator.
2. Gunakan password administrator minimal 6 karakter.
3. Buka menu **Pengaturan**.
4. Isi host, port, user, dan password SSH.
5. Simpan pengaturan.
6. Tekan **Setup koneksi** untuk membuat key dan memasangnya ke server sumber.
7. Tekan **Tes koneksi** untuk memastikan autentikasi private key berhasil.
8. Isi root database remote, folder staging, dan folder tujuan backup.
9. Tambahkan nama database dari menu **Database**.
10. Atur jadwal sinkronisasi dan backup.

Password SSH disimpan sebagai ciphertext Sodium SecretBox di SQLite. Key
enkripsinya berada terpisah di:

```text
/var/www/html/J-Backup/storage/secret.key
```

Jangan menghapus atau mengganti `secret.key`. Tanpa file tersebut, password
lama tidak dapat didekripsi. Password dan key enkripsi tidak pernah dikirim
kembali ke browser.

## 6. Periksa worker

```bash
sudo systemctl status j-backup-worker.timer
sudo systemctl status j-backup-worker.service
sudo systemctl list-timers j-backup-worker.timer
```

Service bertipe `oneshot`, sehingga status `inactive (dead)` setelah selesai
adalah normal. Timer harus berstatus `active (waiting)`.

Jalankan worker sekarang:

```bash
sudo systemctl start j-backup-worker.service
```

Lihat log:

```bash
sudo journalctl -u j-backup-worker.service -n 100 --no-pager
```

## 7. File Explorer backup

Menu **Penyimpanan** menampilkan folder dan file di `/var/backups/j-backup`.
Administrator dapat membuka folder tanggal, mencari, download, dan upload file
`.7z`. Upload dengan nama yang sama akan ditolak.

Batas upload bawaan installer adalah 8 GB. Ubah
`deploy/php-j-backup.ini` bila diperlukan, lalu jalankan installer kembali.

## 8. Update aplikasi

Upload versi baru ke `/var/www/html/J-Backup`, lalu jalankan:

```bash
cd /var/www/html/J-Backup
sudo bash scripts/install-linux.sh
sudo systemctl restart j-backup-worker.timer
sudo systemctl restart apache2
```

Installer tidak menghapus database, password terenkripsi, private key SSH,
staging, atau file backup yang sudah ada.

## 9. Pemeriksaan masalah

Periksa kebutuhan aplikasi:

```bash
cd /var/www/html/J-Backup
php bin/check-requirements.php
```

Periksa konfigurasi Apache:

```bash
sudo apache2ctl configtest
sudo systemctl status apache2
```

Pastikan web dan worker memakai database yang sama:

```text
/var/www/html/J-Backup/storage/j-backup.sqlite
```

Jika tugas terus berada dalam antrean, periksa timer dan log worker pada langkah
6.
