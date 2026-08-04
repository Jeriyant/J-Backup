#!/usr/bin/env bash
set -euo pipefail

show_help() {
  cat <<'EOF'
Penggunaan:
  sudo bash scripts/uninstall-linux.sh [opsi]

Opsi:
  --purge-data  Hapus juga database, key SSH, data RSYNC, hasil backup, dan log.
  --yes         Lewati konfirmasi interaktif.
  -h, --help    Tampilkan bantuan ini.

Tanpa --purge-data, seluruh data runtime dipertahankan.

Jika instalasi memakai lokasi khusus, berikan kembali variabel yang sama:
  JBACKUP_INSTALL_DIR, JBACKUP_DATA_DIR, JBACKUP_RSYNC_DIR,
  JBACKUP_BACKUP_DIR, dan JBACKUP_LOG_DIR.
EOF
}

purge_data=0
assume_yes=0

while (( $# > 0 )); do
  case "$1" in
    --purge-data)
      purge_data=1
      ;;
    --yes)
      assume_yes=1
      ;;
    -h|--help)
      show_help
      exit 0
      ;;
    *)
      echo "Opsi tidak dikenal: $1" >&2
      show_help >&2
      exit 2
      ;;
  esac
  shift
done

if [[ "${EUID}" -ne 0 ]]; then
  echo "Jalankan uninstaller dengan sudo." >&2
  exit 1
fi

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
install_dir="$(readlink -m -- "${JBACKUP_INSTALL_DIR:-${project_dir}}")"
data_dir="$(readlink -m -- "${JBACKUP_DATA_DIR:-${install_dir}/storage}")"
rsync_dir="$(readlink -m -- "${JBACKUP_RSYNC_DIR:-${install_dir}/RSYNC}")"
backup_dir="$(readlink -m -- "${JBACKUP_BACKUP_DIR:-${install_dir}/BACKUP}")"
log_dir="$(readlink -m -- "${JBACKUP_LOG_DIR:-/var/log/j-backup}")"

is_protected_path() {
  case "$1" in
    /|/bin|/boot|/dev|/etc|/home|/lib|/lib64|/media|/mnt|/opt|/proc|/root|/run|/sbin|/srv|/sys|/tmp|/usr|/var|/var/lib|/var/log|/var/www|/var/www/html)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

validate_removal_path() {
  local label="$1"
  local target="$2"

  if [[ "${target}" != /* ]] || [[ "${target}" == *$'\n'* ]]; then
    echo "${label} tidak valid: ${target}" >&2
    exit 1
  fi
  if is_protected_path "${target}"; then
    echo "${label} menunjuk ke lokasi sistem yang dilindungi: ${target}" >&2
    exit 1
  fi
}

validate_removal_path "Lokasi aplikasi" "${install_dir}"
if (( purge_data != 0 )); then
  validate_removal_path "Lokasi data" "${data_dir}"
  validate_removal_path "Lokasi RSYNC" "${rsync_dir}"
  validate_removal_path "Lokasi backup" "${backup_dir}"
  validate_removal_path "Lokasi log" "${log_dir}"
else
  for runtime_path in "${data_dir}" "${rsync_dir}" "${backup_dir}" "${log_dir}"; do
    for code_dir in src assets bin deploy scripts; do
      managed_path="${install_dir}/${code_dir}"
      if [[ "${runtime_path}" == "${managed_path}" \
        || "${runtime_path}" == "${managed_path}/"* ]]; then
        echo "Data yang harus dipertahankan berada di dalam folder kode: ${runtime_path}" >&2
        echo "Pindahkan data tersebut atau periksa kembali variabel JBACKUP_* Anda." >&2
        exit 1
      fi
    done
  done
fi

echo "J-BACKUP akan dihapus dari sistem."
echo "  Aplikasi : ${install_dir}"
if (( purge_data != 0 )); then
  echo "  Data      : ${data_dir}"
  echo "  RSYNC     : ${rsync_dir}"
  echo "  Backup    : ${backup_dir}"
  echo "  Log       : ${log_dir}"
  echo
  echo "PERINGATAN: --purge-data menghapus seluruh data di atas secara permanen."
else
  echo "  Data      : ${data_dir} (dipertahankan)"
  echo "  RSYNC     : ${rsync_dir} (dipertahankan)"
  echo "  Backup    : ${backup_dir} (dipertahankan)"
  echo "  Log       : ${log_dir} (dipertahankan)"
fi

if (( assume_yes == 0 )); then
  if [[ ! -t 0 ]]; then
    echo "Konfirmasi interaktif tidak tersedia. Gunakan --yes untuk melanjutkan." >&2
    exit 1
  fi
  read -r -p "Ketik HAPUS untuk melanjutkan: " confirmation
  if [[ "${confirmation}" != "HAPUS" ]]; then
    echo "Pembatalan: tidak ada perubahan yang dilakukan."
    exit 0
  fi
fi

echo
echo "Menghentikan worker J-BACKUP..."
if command -v systemctl >/dev/null 2>&1; then
  systemctl disable --now j-backup-worker.timer >/dev/null 2>&1 || true
  systemctl stop j-backup-worker.service >/dev/null 2>&1 || true
fi

echo "Menghapus konfigurasi systemd dan Apache..."
rm -f -- \
  /etc/systemd/system/j-backup-worker.service \
  /etc/systemd/system/j-backup-worker.timer

if command -v a2disconf >/dev/null 2>&1; then
  a2disconf j-backup >/dev/null 2>&1 || true
fi
rm -f -- \
  /etc/apache2/conf-enabled/j-backup.conf \
  /etc/apache2/conf-available/j-backup.conf \
  /etc/httpd/conf.d/j-backup.conf \
  /etc/php.d/99-j-backup.ini

for php_ini in /etc/php/*/apache2/conf.d/99-j-backup.ini; do
  if [[ -e "${php_ini}" || -L "${php_ini}" ]]; then
    rm -f -- "${php_ini}"
  fi
done

if command -v systemctl >/dev/null 2>&1; then
  systemctl daemon-reload >/dev/null 2>&1 || \
    echo "Peringatan: systemd tidak dapat dimuat ulang." >&2
  systemctl reset-failed j-backup-worker.service j-backup-worker.timer \
    >/dev/null 2>&1 || true

  if systemctl is-active --quiet apache2; then
    if command -v apache2ctl >/dev/null 2>&1 \
      && apache2ctl configtest >/dev/null 2>&1; then
      systemctl reload apache2 >/dev/null 2>&1 || \
        echo "Peringatan: Apache perlu dimuat ulang secara manual." >&2
    else
      echo "Peringatan: konfigurasi Apache gagal diuji; Apache tidak dimuat ulang." >&2
    fi
  fi

  if systemctl is-active --quiet httpd; then
    if command -v httpd >/dev/null 2>&1 && httpd -t >/dev/null 2>&1; then
      systemctl reload httpd >/dev/null 2>&1 || \
        echo "Peringatan: httpd perlu dimuat ulang secara manual." >&2
    else
      echo "Peringatan: konfigurasi httpd gagal diuji; httpd tidak dimuat ulang." >&2
    fi
  fi
fi

echo "Menghapus file aplikasi..."
for app_path in \
  src assets bin deploy scripts \
  index.php api.php version.json og.png .htaccess "Cara Install.md"; do
  rm -rf -- "${install_dir}/${app_path}"
done

if (( purge_data != 0 )); then
  echo "Menghapus data runtime..."
  for runtime_path in "${data_dir}" "${rsync_dir}" "${backup_dir}" "${log_dir}"; do
    rm -rf -- "${runtime_path}"
  done
else
  echo
  echo "Data dipertahankan di:"
  echo "  ${data_dir}"
  echo "  ${rsync_dir}"
  echo "  ${backup_dir}"
  echo "  ${log_dir}"
fi

rmdir -- "${install_dir}" >/dev/null 2>&1 || true

echo
echo "J-BACKUP berhasil dihapus."
echo "Paket Apache, PHP, rsync, 7-Zip, OpenSSH, dan paket sistem lain tetap terpasang."
