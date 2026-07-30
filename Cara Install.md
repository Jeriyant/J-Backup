# Cara Install J-BACKUP di Debian

J-BACKUP dapat dipasang dari lokasi mana pun. Folder aplikasi tidak harus
berada di `/var/www/html`. Installer membuat Apache Alias dan service worker
berdasarkan lokasi sebenarnya secara otomatis.

Secara bawaan, database dan data runtime disimpan di dalam folder aplikasi:

```text
<folder-aplikasi>/storage/j-backup.sqlite
```

Web dan worker selalu diarahkan ke file SQLite yang sama.

## 1. Tentukan lokasi aplikasi

Pilih folder permanen, misalnya:

```text
/opt/j-backup
/srv/apps/j-backup
/var/www/aplikasi/J-Backup
/mnt/e/Cursor-Project/J-Backup
```

Jangan memasang aplikasi dari folder sementara yang akan dihapus, seperti
`/tmp`.

Contoh upload ke `/opt/j-backup`:

```bash
scp -r J-Backup user@ALAMAT-SERVER:/tmp/
ssh user@ALAMAT-SERVER
sudo mkdir -p /opt/j-backup
sudo cp -a /tmp/J-Backup/. /opt/j-backup/
cd /opt/j-backup
```

Seluruh folder `assets`, `bin`, `deploy`, `src`, `scripts`, dan file aplikasi
lainnya harus ikut diunggah.

## 2. Jalankan installer

Jalankan installer dari folder aplikasi:

```bash
cd /opt/j-backup
sudo bash scripts/install-linux.sh
```

Secara bawaan installer menggunakan:

```text
Lokasi aplikasi : folder tempat script berada
Lokasi data     : <folder-aplikasi>/storage
Lokasi backup   : /var/backups/j-backup
URL             : /<nama-folder>/
```

Jika foldernya `/opt/j-backup`, URL bawaannya menjadi:

```text
http://ALAMAT-SERVER/j-backup/
```

Installer akan:

- memasang Apache, PHP 8.2+, SQLite, Sodium, rsync, 7-Zip, OpenSSH, dan
  `sshpass`;
- menampilkan verifikasi setiap paket dengan status `OK` atau `GAGAL`;
- membuat user sistem `jbackup`;
- menyiapkan database, staging, backup, SSH, dan key enkripsi;
- membuat konfigurasi Apache berdasarkan lokasi aplikasi;
- membuat service worker berdasarkan lokasi aplikasi;
- mengaktifkan timer worker setiap 15 detik;
- menjalankan worker sekali agar heartbeat langsung tersedia.

## 3. Pilihan lokasi dan URL khusus

Installer menerima variabel berikut:

```text
JBACKUP_INSTALL_DIR  lokasi akhir aplikasi
JBACKUP_DATA_DIR     lokasi database, staging, secret.key, dan key SSH
JBACKUP_BACKUP_DIR   lokasi hasil backup awal
JBACKUP_LOG_DIR      lokasi log
JBACKUP_URL_PATH     path URL Apache
```

Contoh menyalin source saat ini ke `/srv/apps/j-backup` dan memakai URL
`/backup-server`:

```bash
sudo env \
  JBACKUP_INSTALL_DIR=/srv/apps/j-backup \
  JBACKUP_URL_PATH=/backup-server \
  bash scripts/install-linux.sh
```

Contoh memisahkan data dari file aplikasi:

```bash
sudo env \
  JBACKUP_DATA_DIR=/srv/j-backup-data \
  JBACKUP_BACKUP_DIR=/mnt/backup/j-backup \
  bash scripts/install-linux.sh
```

Jika `JBACKUP_INSTALL_DIR` tidak diberikan, aplikasi dipasang dan dijalankan
langsung dari folder saat ini. Path URL harus diawali `/` dan hanya menggunakan
huruf, angka, titik, garis bawah, tanda hubung, atau garis miring.

## 4. Izin folder

Installer mengatur izin secara otomatis:

- file aplikasi dimiliki `root`;
- data runtime dan hasil backup dimiliki `jbackup`;
- user Apache dimasukkan ke grup `jbackup`;
- `storage/.ssh` menggunakan mode `770` agar worker dapat membuat key dan
  aplikasi web dapat membersihkannya saat Reset Database;
- `storage/secret.key` menggunakan mode `640`.

Untuk contoh aplikasi di `/opt/j-backup`:

```bash
sudo chown -R root:root /opt/j-backup
sudo chown -R jbackup:jbackup \
  /opt/j-backup/storage \
  /var/backups/j-backup
sudo chmod 770 /opt/j-backup/storage /var/backups/j-backup
sudo chmod 640 /opt/j-backup/storage/secret.key
```

Semua direktori induk lokasi aplikasi harus dapat dilintasi oleh Apache dan
user `jbackup`. Hindari menaruh aplikasi di home pribadi yang memiliki mode
`700`.

## 5. Buka dan setup aplikasi

Buka URL yang dicetak installer, misalnya:

```text
http://ALAMAT-SERVER/j-backup/
```

Jika memakai WSL pada komputer yang sama:

```text
http://localhost/j-backup/
```

Saat pertama dibuka:

1. Buat administrator dengan password minimal 1 karakter.
2. Buka menu **Pengaturan**.
3. Isi host, port, user, dan password SSH.
4. Simpan pengaturan.
5. Tekan **Connect** untuk membuat dan memasang public key.
6. Tekan **Tes koneksi** untuk menguji autentikasi private key.
7. Isi root database remote, folder staging, dan tujuan backup.
8. Tambahkan database dari menu **Database**.
9. Atur jadwal sinkronisasi dan backup.

Menu **Dashboard** menampilkan status worker, kapasitas tujuan backup, aktivitas
terbaru, dan status koneksi SSH. Status **Terhubung** hanya tampil jika target
host/user yang tersimpan cocok dengan koneksi terakhir dan private key lokal
masih tersedia.

Password SSH disimpan terenkripsi memakai Sodium SecretBox. Key enkripsi
berada di:

```text
<lokasi-data>/secret.key
```

Password tersimpan dapat ditampilkan kembali oleh administrator melalui antarmuka Web. Jangan menghapus `secret.key`; tanpa file itu,

password lama tidak dapat didekripsi.

Setelah Connect berhasil, tombol berubah menjadi **Disconnect**. Tindakan
Disconnect akan:

- mencabut public key J-BACKUP yang cocok dari `~/.ssh/authorized_keys` server
  sumber;
- menghapus private key, public key, dan `known_hosts` lokal;
- menghapus password SSH terenkripsi yang tersimpan;
- mempertahankan host, port, dan user agar Connect dapat dilakukan kembali.

Jika pencabutan key remote gagal, key lokal tidak dihapus agar proses dapat
dicoba lagi dengan aman.

## 6. Periksa worker

```bash
sudo systemctl status j-backup-worker.timer
sudo systemctl status j-backup-worker.service
sudo systemctl list-timers j-backup-worker.timer
sudo journalctl -u j-backup-worker.service -n 100 --no-pager
```

Service bertipe `oneshot`. Status `inactive (dead)` setelah proses selesai
adalah normal. Timer harus berstatus `active (waiting)`, sedangkan aplikasi
menampilkan **Worker siap** jika heartbeat masih baru.

Jalankan worker segera:

```bash
sudo systemctl start j-backup-worker.service
```

Lihat lokasi yang benar-benar digunakan service:

```bash
sudo systemctl show j-backup-worker.service \
  -p Environment \
  -p WorkingDirectory \
  -p ExecStart
```

## 7. File Explorer backup

Menu **Penyimpanan** menampilkan lokasi tujuan backup yang disimpan pada
pengaturan aplikasi. Administrator dapat membuka folder, mencari, download,
dan upload file `.7z`. Upload dengan nama sama akan ditolak.

Batas upload bawaan adalah 8 GB. Ubah `deploy/php-j-backup.ini`, lalu jalankan
installer kembali untuk menerapkan nilai baru.

### Reset Database

Menu **Pengaturan → Reset Database** mengembalikan aplikasi ke kondisi setup
awal. Administrator harus mengetik `RESET` sebelum tindakan dapat dijalankan.
Reset akan menghapus:

- akun administrator;
- seluruh konfigurasi aplikasi;
- daftar database dan jadwal;
- antrean serta riwayat pekerjaan;
- status koneksi, tugas SSH, dan password SSH terenkripsi;
- seluruh isi `storage/.ssh`, termasuk private key, public key, `known_hosts`,
  dan file sementara `ssh-copy-id`.

File arsip pada folder tujuan backup tidak dihapus. Public key yang sudah
terpasang pada server sumber juga tidak dicabut oleh Reset Database. Gunakan
**Disconnect** terlebih dahulu jika public key remote juga harus dihapus.
Reset ditolak selama worker sedang menjalankan pekerjaan.

### Catatan staging pada WSL

Drive Windows seperti `/mnt/c`, `/mnt/d`, atau `/mnt/e` tidak mendukung seluruh
metadata permission dan timestamp Linux. J-BACKUP menjalankan rsync dalam mode
salin-konten agar staging pada drive tersebut tidak gagal dengan code 23.

Untuk performa file yang lebih cepat, staging tetap disarankan berada pada
filesystem Linux WSL, misalnya:

```text
/var/lib/j-backup-staging
```

Lokasinya dapat diubah dari **Pengaturan → Folder staging lokal**.

## 8. Memindahkan aplikasi

Untuk memindahkan aplikasi tanpa kehilangan data:

```bash
sudo systemctl stop j-backup-worker.timer apache2
sudo mv /opt/j-backup /srv/apps/j-backup
cd /srv/apps/j-backup
sudo bash scripts/install-linux.sh
sudo systemctl start apache2 j-backup-worker.timer
```

Installer membuat ulang konfigurasi Apache dan worker dari lokasi baru.
Pengaturan staging dan key SSH bawaan ikut dimigrasikan ke `storage` yang baru.
Path yang sebelumnya diubah sendiri oleh pengguna tidak ditimpa.

Jika hanya menyalin database dari instalasi lama, salin bersama `secret.key`
dan folder `.ssh`:

```bash
sudo systemctl stop j-backup-worker.timer apache2
sudo cp -a /LOKASI-LAMA/storage/. /LOKASI-BARU/storage/
sudo chown -R jbackup:jbackup /LOKASI-BARU/storage
cd /LOKASI-BARU
sudo bash scripts/install-linux.sh
```

Jangan menggabungkan dua database SQLite. Pilih satu folder `storage` yang
datanya ingin dipertahankan.

## 9. Memperbarui aplikasi

Timpa file kode pada folder aplikasi tanpa menghapus `storage`, lalu jalankan:

```bash
cd /LOKASI/APLIKASI
sudo bash scripts/install-linux.sh
```

Installer tidak menghapus database, password terenkripsi, private key SSH,
staging, atau hasil backup yang sudah ada.

## 10. Pemeriksaan masalah

```bash
cd /LOKASI/APLIKASI
php bin/check-requirements.php
sudo apache2ctl configtest
sudo systemctl status apache2
sudo systemctl status j-backup-worker.timer
```

Periksa database aktif pada output berikut:

```bash
sudo systemctl show j-backup-worker.service -p Environment
```

Nilai `JBACKUP_DATA_DIR` harus sama dengan folder `storage` yang digunakan
aplikasi web. Jalankan installer kembali dari folder aplikasi jika lokasinya
berbeda.
