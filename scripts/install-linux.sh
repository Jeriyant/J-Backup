#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Jalankan installer dengan sudo." >&2
  exit 1
fi

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
install_dir="$(readlink -m -- "${JBACKUP_INSTALL_DIR:-${project_dir}}")"
data_dir="$(readlink -m -- "${JBACKUP_DATA_DIR:-${install_dir}/storage}")"
realtime_dir="$(readlink -m -- "${JBACKUP_REALTIME_DIR:-${install_dir}/Realtime-Data}")"
backup_dir="$(readlink -m -- "${JBACKUP_BACKUP_DIR:-${install_dir}/Hasil-Backup}")"
log_dir="$(readlink -m -- "${JBACKUP_LOG_DIR:-/var/log/j-backup}")"
app_url_path="${JBACKUP_URL_PATH:-/$(basename "${install_dir}")}"
app_url_path="/${app_url_path#/}"
app_url_path="${app_url_path%/}"

if [[ ! "${app_url_path}" =~ ^/[A-Za-z0-9._~/-]+$ ]] \
  || [[ "${app_url_path}" == *"//"* ]] \
  || [[ "${app_url_path}" == *"/../"* ]] \
  || [[ "${app_url_path}" == "/" ]]; then
  echo "JBACKUP_URL_PATH tidak valid: ${app_url_path}" >&2
  exit 1
fi

for runtime_path in \
  "${install_dir}" "${data_dir}" "${realtime_dir}" "${backup_dir}" "${log_dir}"; do
  if [[ "${runtime_path}" == *$'\n'* || "${runtime_path}" == *'"'* ]]; then
    echo "Lokasi mengandung karakter yang tidak didukung: ${runtime_path}" >&2
    exit 1
  fi
done

if command -v apt-get >/dev/null 2>&1; then
  apt-get update
  apt-get install -y \
    apache2 php libapache2-mod-php php-cli php-sqlite3 \
    rsync p7zip-full openssh-client sshpass ca-certificates
  web_user="www-data"
  apache_service="apache2"
  apache_command="apache2ctl"
  ca_command="update-ca-certificates"
elif command -v dnf >/dev/null 2>&1; then
  dnf install -y \
    httpd php php-cli php-pdo php-sqlite3 \
    rsync p7zip p7zip-plugins openssh-clients sshpass ca-certificates
  web_user="apache"
  apache_service="httpd"
  apache_command="httpd"
  ca_command="update-ca-trust"
else
  echo "Distribusi belum didukung otomatis." >&2
  echo "Pasang Apache, PHP 8.2+, pdo_sqlite, rsync, 7z, OpenSSH client, dan sshpass." >&2
  exit 1
fi

php_major="$(php -r 'echo PHP_MAJOR_VERSION;')"
php_minor="$(php -r 'echo PHP_MINOR_VERSION;')"
if (( php_major < 8 || (php_major == 8 && php_minor < 2) )); then
  echo "J-BACKUP membutuhkan PHP 8.2 atau lebih baru." >&2
  exit 1
fi

php "${project_dir}/bin/check-requirements.php"

verification_failed=0

verify_command() {
  local label="$1"
  local command_name="$2"
  if command -v "${command_name}" >/dev/null 2>&1; then
    printf "  %-20s : OK\n" "${label}"
  else
    printf "  %-20s : GAGAL\n" "${label}" >&2
    verification_failed=1
  fi
}

verify_php_extension() {
  local label="$1"
  local extension="$2"
  if php -r "exit(extension_loaded('${extension}') ? 0 : 1);"; then
    printf "  %-20s : OK\n" "${label}"
  else
    printf "  %-20s : GAGAL\n" "${label}" >&2
    verification_failed=1
  fi
}

echo
echo "Verifikasi paket J-BACKUP:"
verify_command "Apache" "${apache_command}"
verify_command "PHP CLI" "php"
verify_php_extension "PDO" "pdo"
verify_php_extension "PDO SQLite" "pdo_sqlite"
verify_php_extension "Sodium" "sodium"
verify_command "rsync" "rsync"
verify_command "7-Zip" "7z"
verify_command "SSH client" "ssh"
verify_command "ssh-keygen" "ssh-keygen"
verify_command "ssh-copy-id" "ssh-copy-id"
verify_command "sshpass" "sshpass"
verify_command "CA certificates" "${ca_command}"

if (( verification_failed != 0 )); then
  echo "Instalasi dihentikan karena ada paket wajib yang belum tersedia." >&2
  exit 1
fi
echo "Semua paket wajib tersedia."

if ! getent group jbackup >/dev/null 2>&1; then
  groupadd --system jbackup
fi
if ! id jbackup >/dev/null 2>&1; then
  useradd --system --gid jbackup --home-dir "${data_dir}" \
    --shell /usr/sbin/nologin jbackup
else
  usermod --home "${data_dir}" jbackup
fi
usermod -a -G jbackup "${web_user}"

install -d -m 0755 -o root -g root "${install_dir}"

if [[ "${project_dir}" != "${install_dir}" ]]; then
  for directory in src assets bin deploy scripts; do
    rsync -a "${project_dir}/${directory}/" "${install_dir}/${directory}/"
  done
  install -m 0644 \
    "${project_dir}/index.php" \
    "${project_dir}/api.php" \
    "${project_dir}/og.png" \
    "${project_dir}/.htaccess" \
    "${install_dir}/"
  install -m 0644 "${project_dir}/Cara Install.md" "${install_dir}/Cara Install.md"
fi
rm -f "${install_dir}/README.md"
chown root:root "${install_dir}"
for code_path in src assets bin deploy scripts index.php api.php og.png .htaccess "Cara Install.md"; do
  if [[ -e "${install_dir}/${code_path}" ]]; then
    chown -R root:root "${install_dir}/${code_path}"
  fi
done
install -d -m 0770 -o jbackup -g jbackup \
  "${data_dir}" "${data_dir}/staging" "${realtime_dir}" \
  "${backup_dir}" "${log_dir}"
install -d -m 0770 -o jbackup -g jbackup "${data_dir}/.ssh"
chown -R jbackup:jbackup "${data_dir}"
chmod 0770 "${data_dir}" "${data_dir}/staging" \
  "${realtime_dir}" "${backup_dir}"
chmod 0770 "${data_dir}/.ssh"
if [[ ! -f "${data_dir}/secret.key" ]]; then
  umask 0027
  head -c 32 /dev/urandom > "${data_dir}/secret.key"
fi
chown jbackup:jbackup "${data_dir}/secret.key"
chmod 0640 "${data_dir}/secret.key"
chmod 0755 "${install_dir}/bin/worker.php"

render_template() {
  local source_file="$1"
  local target_file="$2"

  JBACKUP_TEMPLATE_APP_DIR="${install_dir}" \
  JBACKUP_TEMPLATE_DATA_DIR="${data_dir}" \
  JBACKUP_TEMPLATE_REALTIME_DIR="${realtime_dir}" \
  JBACKUP_TEMPLATE_BACKUP_DIR="${backup_dir}" \
  JBACKUP_TEMPLATE_LOG_DIR="${log_dir}" \
  JBACKUP_TEMPLATE_URL_PATH="${app_url_path}" \
  php -r '
    $source = $argv[1];
    $target = $argv[2];
    $appDirectory = (string) getenv("JBACKUP_TEMPLATE_APP_DIR");
    $systemdPath = static fn (string $path): string => str_replace(
        ["\\", " "],
        ["\\x5c", "\\x20"],
        $path
    );
    $replacements = [
        "@@APP_DIR@@" => $appDirectory,
        "@@APP_DIR_REGEX@@" => preg_quote($appDirectory, "#"),
        "@@APP_DIR_SYSTEMD@@" => $systemdPath($appDirectory),
        "@@DATA_DIR@@" => (string) getenv("JBACKUP_TEMPLATE_DATA_DIR"),
        "@@REALTIME_DIR@@" => (string) getenv("JBACKUP_TEMPLATE_REALTIME_DIR"),
        "@@BACKUP_DIR@@" => (string) getenv("JBACKUP_TEMPLATE_BACKUP_DIR"),
        "@@LOG_DIR@@" => (string) getenv("JBACKUP_TEMPLATE_LOG_DIR"),
        "@@APP_URL_PATH@@" => (string) getenv("JBACKUP_TEMPLATE_URL_PATH"),
    ];
    $rendered = strtr((string) file_get_contents($source), $replacements);
    if (preg_match("/@@[A-Z0-9_]+@@/", $rendered)) {
        fwrite(STDERR, "Template masih memiliki placeholder yang belum diisi.\n");
        exit(1);
    }
    if (file_put_contents($target, $rendered) === false) {
        fwrite(STDERR, "Tidak dapat menulis konfigurasi: {$target}\n");
        exit(1);
    }
  ' "${source_file}" "${target_file}"
  chmod 0644 "${target_file}"
}

render_template \
  "${install_dir}/deploy/j-backup-worker.service" \
  /etc/systemd/system/j-backup-worker.service
install -m 0644 "${install_dir}/deploy/j-backup-worker.timer" \
  /etc/systemd/system/j-backup-worker.timer

if [[ "${apache_service}" == "apache2" ]]; then
  render_template "${install_dir}/deploy/apache-j-backup.conf" \
    /etc/apache2/conf-available/j-backup.conf
  for php_conf_dir in /etc/php/*/apache2/conf.d; do
    if [[ -d "${php_conf_dir}" ]]; then
      install -m 0644 "${install_dir}/deploy/php-j-backup.ini" \
        "${php_conf_dir}/99-j-backup.ini"
    fi
  done
  a2enmod headers >/dev/null
  a2enconf j-backup >/dev/null
else
  render_template "${install_dir}/deploy/apache-j-backup.conf" \
    /etc/httpd/conf.d/j-backup.conf
  install -m 0644 "${install_dir}/deploy/php-j-backup.ini" \
    /etc/php.d/99-j-backup.ini
fi

systemctl daemon-reload
systemctl enable --now j-backup-worker.timer
systemctl enable --now "${apache_service}"
if [[ "${apache_service}" == "apache2" ]]; then
  apache2ctl configtest
else
  httpd -t
fi
systemctl restart "${apache_service}"
systemctl start j-backup-worker.service

echo
echo "J-BACKUP berhasil dipasang."
echo "Lokasi aplikasi : ${install_dir}"
echo "Lokasi database : ${data_dir}/j-backup.sqlite"
echo "Data realtime   : ${realtime_dir}"
echo "Lokasi backup   : ${backup_dir}"
echo "Buka            : http://ALAMAT-SERVER${app_url_path}/"
echo "Key SSH         : dikelola otomatis oleh aplikasi"
