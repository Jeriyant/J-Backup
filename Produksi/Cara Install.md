# Cara Install J-BACKUP di Debian

J-BACKUP dapat dipasang dari lokasi mana pun. Folder aplikasi tidak harus
berada di `/var/www/html`. Installer membuat Apache Alias dan service worker
berdasarkan lokasi sebenarnya secara otomatis.

Secara bawaan, database dan data runtime disimpan di dalam folder aplikasi:

```text
<folder-aplikasi>/storage/j-backup.sqlite
<folder-aplikasi>/RSYNC
<folder-aplikasi>/BACKUP
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
Data RSYNC      : <folder-aplikasi>/RSYNC
Lokasi backup   : <folder-aplikasi>/BACKUP
URL             : /<nama-folder>/
```

Jika foldernya `/opt/j-backup`, URL bawaannya menjadi:

```text
http://ALAMAT-SERVER/j-backup/
```

Installer akan:

- memasang Apache, PHP 8.2+, SQLite, Sodium, PHP Zip/XML untuk import Excel,
  rsync, 7-Zip, OpenSSH, dan `sshpass`;
- menampilkan verifikasi setiap paket dengan status `OK` atau `GAGAL`;
- menjalankan worker sebagai `root` agar lokasi sumber dan tujuan dapat
  menggunakan path absolut di seluruh server;
- menyiapkan database, folder RSYNC (`RSYNC`), `BACKUP`, SSH, dan key
  enkripsi;
- membuat konfigurasi Apache berdasarkan lokasi aplikasi;
- membuat service worker berdasarkan lokasi aplikasi;
- mengaktifkan timer worker setiap 15 detik;
- menjalankan worker sekali agar heartbeat langsung tersedia.

## 3. Pilihan lokasi dan URL khusus

Installer menerima variabel berikut:

```text
JBACKUP_INSTALL_DIR  lokasi akhir aplikasi
JBACKUP_DATA_DIR     lokasi database, secret.key, dan key SSH
JBACKUP_RSYNC_DIR    lokasi tujuan RSYNC awal
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
  JBACKUP_RSYNC_DIR=/srv/j-backup-rsync \
  JBACKUP_BACKUP_DIR=/mnt/backup/j-backup \
  bash scripts/install-linux.sh
```

Jika `JBACKUP_INSTALL_DIR` tidak diberikan, aplikasi dipasang dan dijalankan
langsung dari folder saat ini. Path URL harus diawali `/` dan hanya menggunakan
huruf, angka, titik, garis bawah, tanda hubung, atau garis miring.

## 4. Izin folder

Installer mengatur izin secara otomatis:

- file aplikasi dimiliki `root`;
- data runtime dimiliki `root` dengan grup user Apache;
- folder RSYNC dan hasil backup disiapkan otomatis oleh installer;
- `storage/.ssh` menggunakan mode `770` agar worker dapat membuat key dan
  aplikasi web dapat membersihkannya saat Reset Database;
- `storage/secret.key` menggunakan mode `640`.

Untuk contoh aplikasi di `/opt/j-backup`:

```bash
sudo chown -R root:root /opt/j-backup
sudo chown -R root:www-data \
  /opt/j-backup/storage \
  /opt/j-backup/RSYNC \
  /opt/j-backup/BACKUP
sudo chmod 770 \
  /opt/j-backup/storage \
  /opt/j-backup/RSYNC \
  /opt/j-backup/BACKUP
sudo chmod 640 /opt/j-backup/storage/secret.key
```

Semua direktori induk lokasi aplikasi harus dapat dilintasi oleh Apache.
Worker berjalan sebagai `root`, sedangkan antarmuka web tetap berjalan sebagai
user Apache dan hanya membutuhkan akses ke database serta folder upload.

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
3. Isi host, port, user, dan password SSH. User awal adalah `root`, tetapi
   bebas diganti sesuai akun pada server sumber.
4. Tekan **Connect** untuk menyimpan konfigurasi koneksi, mengenkripsi password,
   membuat key, dan memasang public key. Tidak ada tombol simpan koneksi
   terpisah.
5. Tombol Connect akan berubah menjadi **Disconnect**.
6. Gunakan **Tes koneksi** untuk menguji kembali autentikasi private key.
7. Atur **Folder data RSYNC** pada panel **Data RSYNC**, lalu simpan
   panel tersebut. Atur tujuan backup pada panel **Lokasi & penamaan** dan
   simpan panel backup secara terpisah.
8. Tambahkan satu atau beberapa **Sumber Backup** dari menu **Sumber**.
   Daftar sumber juga dapat diimpor dari file Excel `.xlsx` atau `.csv`
   menggunakan tombol **Import Excel** dan format tabel yang ditampilkan
   aplikasi.
9. Atur jadwal RSYNC & Backup.

### Sumber backup universal

J-BACKUP tidak membatasi sumber pada database MySQL. Setiap Sumber Backup
memiliki nama, mode arsip, dan satu atau beberapa path folder absolut pada
server remote. Contoh sumber database:

```text
/var/lib/mysql/cusj_airupas
/var/lib/mysql/cusj_airupas_sys
sakep=/var/lib/mysql/cusj_airupas_sakep
```

Contoh sumber website:

```text
website=/var/www/example.com
nginx=/etc/nginx/sites-available/example.com
uploads=/srv/data/uploads
```

Format `alias=/path/folder` bersifat opsional. Tanpa alias, nama folder terakhir
dipakai otomatis. Alias harus unik dalam satu sumber dan digunakan sebagai nama
folder RSYNC serta nama arsip pada mode terpisah.

Mode **Gabungkan** membuat satu arsip 7z yang berisi seluruh path dalam sumber.
Mode **Terpisah** membuat satu arsip 7z untuk setiap path. Setiap arsip diuji
dengan `7z t`, diperiksa kembali pada folder tujuan, lalu dicatat beserta ukuran
dan checksum SHA-256. Seluruh arsip harus berhasil sebelum job dinyatakan sukses.

Data hasil RSYNC disusun terisolasi per sumber:

```text
RSYNC/<id>-<nama-sumber>/<alias>/
```

Arsip ditempatkan berdasarkan tanggal dan sumber:

```text
BACKUP/<tahun>/<bulan>/<tanggal>/<nama-sumber>/
```

Instalasi lama dimigrasikan otomatis: nama database lama menjadi satu Sumber
Backup, sementara folder utama dan pasangan `_sys` lama diubah menjadi daftar
path eksplisit. Arsip hasil backup yang sudah ada tetap dipertahankan.

Menu **Dashboard** menampilkan status worker, jadwal, uptime, CPU, memory,
latensi browser ke server, kapasitas tujuan backup, aktivitas terbaru, dan
status koneksi SSH. Menu **Penyimpanan** menampilkan kapasitas tujuan, aturan
verifikasi, serta mount yang tersedia pada host.
Menu **Backup** menyediakan explorer dan upload/download arsip, sedangkan menu
**RSYNC** menyediakan explorer hasil rsync. Menu **About** berisi versi dan
pengecekan pembaruan GitHub. Status **Terhubung** hanya tampil jika target
host/user yang tersimpan cocok dengan koneksi terakhir dan private key lokal
masih tersedia.

Worker berjalan sebagai `root`. Private key tetap disimpan di
`<lokasi-data>/.ssh` agar seluruh data aplikasi berada pada satu lokasi dan
dapat dibersihkan melalui Disconnect atau Reset Database.

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
- daftar sumber backup, path, dan jadwal;
- antrean serta riwayat pekerjaan;
- status koneksi, tugas SSH, dan password SSH terenkripsi;
- seluruh isi `storage/.ssh`, termasuk private key, public key, `known_hosts`,
  dan file sementara `ssh-copy-id`.

Jika koneksi SSH sedang aktif, Reset Database lebih dahulu meminta worker
mencabut public key J-BACKUP dari `~/.ssh/authorized_keys` server sumber.
Database baru direset setelah pencabutan remote dan pembersihan key lokal
berhasil. Jika server tidak dapat dihubungi, reset dibatalkan dan key lokal
dipertahankan supaya pencabutan dapat dicoba kembali.

File arsip pada folder tujuan backup tidak dihapus. Reset ditolak selama worker
sedang menjalankan pekerjaan.

### Catatan staging pada WSL

Drive Windows seperti `/mnt/c`, `/mnt/d`, atau `/mnt/e` tidak mendukung seluruh
metadata permission dan timestamp Linux. J-BACKUP menjalankan rsync dalam mode
salin-konten agar staging pada drive tersebut tidak gagal dengan code 23.

Untuk performa file yang lebih cepat, staging tetap disarankan berada pada
filesystem Linux WSL, misalnya:

```text
/var/lib/j-backup-staging
```

Lokasinya dapat diubah dari **Pengaturan → Folder data RSYNC**.

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
sudo chown -R root:www-data /LOKASI-BARU/storage
cd /LOKASI-BARU
sudo bash scripts/install-linux.sh
```

Jangan menggabungkan dua database SQLite. Pilih satu folder `storage` yang
datanya ingin dipertahankan.

## 9. Memperbarui aplikasi

J-BACKUP memeriksa release resmi
[`Jeriyant/J-Backup`](https://github.com/Jeriyant/J-Backup) secara otomatis.
Jika versi baru tersedia, banner akan muncul pada Web UI. Buka **About â†’
Pembaruan**, lalu pilih **Update sekarang**.

Updater hanya menerima aset `j-backup-dist.zip` yang checksum SHA-256-nya
sesuai dengan release GitHub. Sebelum memasang versi baru, updater membuat
cadangan kode dan akan melakukan rollback jika penyalinan gagal. Folder
`storage`, key SSH, data RSYNC, hasil BACKUP, dan log tidak diganti.

Update otomatis ditolak ketika masih ada backup, tugas SSH, pengujian folder,
atau antrean yang aktif. Tunggu seluruh pekerjaan selesai terlebih dahulu.

Pemeriksaan manual dari terminal juga tersedia:

```bash
cd /LOKASI/APLIKASI
bash scripts/update-linux.sh
```

Untuk instalasi lama yang belum memberi izin updater pada Web UI, jalankan
installer satu kali:

```bash
cd /LOKASI/APLIKASI
sudo bash scripts/install-linux.sh
```

Installer memperbaiki izin folder kode tanpa menghapus database, password
terenkripsi, private key SSH, staging, atau hasil backup yang sudah ada.

### Membuat paket release

Dari Windows PowerShell pada repository pengembangan:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/package-release.ps1
```

Unggah `j-backup-dist.zip` dan `j-backup-dist.zip.sha256` ke GitHub Release
dengan tag yang sama seperti nilai pada `version.json`.

## 10. Menghapus aplikasi

Untuk menghapus service, konfigurasi Apache, dan file kode aplikasi sambil
mempertahankan database serta seluruh data backup:

```bash
cd /LOKASI/APLIKASI
sudo bash scripts/uninstall-linux.sh
```

Ketik `HAPUS` saat diminta. Untuk menghapus database, key SSH, data RSYNC,
hasil backup, dan log secara permanen, tambahkan `--purge-data`:

```bash
sudo bash scripts/uninstall-linux.sh --purge-data
```

Gunakan kembali variabel `JBACKUP_*` yang sama jika instalasi memakai lokasi
khusus. Opsi `--yes` tersedia untuk eksekusi noninteraktif. Uninstaller tidak
menghapus Apache, PHP, rsync, atau paket sistem lain karena paket tersebut
mungkin digunakan aplikasi lain.

## 11. Pemeriksaan masalah

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
