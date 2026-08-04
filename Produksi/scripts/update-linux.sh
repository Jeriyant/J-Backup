#!/usr/bin/env bash
set -euo pipefail

app_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
data_dir="$(readlink -m -- "${JBACKUP_DATA_DIR:-${app_dir}/storage}")"
repository="${JBACKUP_GITHUB_REPOSITORY:-Jeriyant/J-Backup}"
asset_name="${JBACKUP_UPDATE_ASSET:-j-backup-dist.zip}"
api_url="https://api.github.com/repos/${repository}/releases/latest"
progress_file="${data_dir}/update-progress.json"
lock_file="${data_dir}/update.lock"
staging_dir="${data_dir}/staging"
backup_root="${data_dir}/update-backups"
user_agent="J-BACKUP-Updater/${HOSTNAME:-server}"

mkdir -p "${staging_dir}" "${backup_root}"

json_escape() {
  local value="${1//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  value="${value//$'\r'/}"
  printf '%s' "${value}"
}

write_progress() {
  local stage="$1" percent="$2" message="$3"
  local received="${4:-0}" total="${5:-0}"
  local temporary="${progress_file}.tmp.$$"
  printf '{"stage":"%s","percent":%s,"message":"%s","bytes_received":%s,"bytes_total":%s,"updated_at":%s}\n' \
    "${stage}" "${percent}" "$(json_escape "${message}")" \
    "${received}" "${total}" "$(date +%s)" >"${temporary}"
  mv -f -- "${temporary}" "${progress_file}"
}

fail() {
  local message="$1"
  write_progress "error" 0 "${message}" 0 0 || true
  echo "ERROR: ${message}" >&2
  exit 1
}

command -v php >/dev/null 2>&1 || fail "PHP CLI tidak tersedia."
command -v rsync >/dev/null 2>&1 || fail "rsync tidak tersedia."

exec 9>"${lock_file}"
if command -v flock >/dev/null 2>&1; then
  flock -n 9 || fail "Update lain sedang berjalan."
fi

temporary_root="$(mktemp -d "${staging_dir}/update-XXXXXX")"
metadata_file="${temporary_root}/release.json"
archive_file="${temporary_root}/${asset_name}"
checksum_file="${temporary_root}/${asset_name}.sha256"
extract_dir="${temporary_root}/extract"

cleanup() {
  rm -rf -- "${temporary_root}" 2>/dev/null || true
}
trap cleanup EXIT

download_file() {
  local url="$1" destination="$2" accept="${3:-application/octet-stream}"
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL --connect-timeout 20 --max-time 600 --retry 3 --retry-delay 2 \
      -A "${user_agent}" -H "Accept: ${accept}" \
      -H "X-GitHub-Api-Version: 2022-11-28" \
      -o "${destination}" "${url}"
  elif command -v wget >/dev/null 2>&1; then
    wget -q --tries=3 --timeout=60 -U "${user_agent}" \
      -O "${destination}" "${url}"
  else
    return 1
  fi
  [[ -s "${destination}" ]]
}

write_progress "check" 3 "Memeriksa GitHub Release..." 0 0
download_file "${api_url}" "${metadata_file}" "application/vnd.github+json" \
  || fail "Metadata GitHub Release tidak dapat diunduh."

release_values="$({ php -r '
  $payload = json_decode((string) file_get_contents($argv[1]), true);
  if (!is_array($payload) || empty($payload["tag_name"])) {
      fwrite(STDERR, "Release GitHub tidak valid.\n");
      exit(2);
  }
  $assetName = $argv[2];
  $download = "";
  $checksum = "";
  $digest = "";
  foreach ((array) ($payload["assets"] ?? []) as $asset) {
      $name = (string) ($asset["name"] ?? "");
      if ($name === $assetName) {
          $download = (string) ($asset["browser_download_url"] ?? "");
          $rawDigest = (string) ($asset["digest"] ?? "");
          if (preg_match("/^sha256:([a-f0-9]{64})$/i", $rawDigest, $match)) {
              $digest = strtolower($match[1]);
          }
      }
      if ($name === $assetName . ".sha256") {
          $checksum = (string) ($asset["browser_download_url"] ?? "");
      }
  }
  echo (string) $payload["tag_name"], "\n";
  echo ltrim((string) $payload["tag_name"], "vV"), "\n";
  echo $download, "\n", $checksum, "\n", $digest, "\n";
' "${metadata_file}" "${asset_name}"; } )" || fail "Metadata release gagal dibaca."

tag="$(printf '%s\n' "${release_values}" | sed -n '1p')"
remote_version="$(printf '%s\n' "${release_values}" | sed -n '2p')"
download_url="$(printf '%s\n' "${release_values}" | sed -n '3p')"
checksum_url="$(printf '%s\n' "${release_values}" | sed -n '4p')"
expected_sha="$(printf '%s\n' "${release_values}" | sed -n '5p')"

[[ "${remote_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][A-Za-z0-9.-]+)?$ ]] \
  || fail "Versi release tidak valid: ${remote_version}"
[[ -n "${download_url}" ]] || fail "Aset ${asset_name} tidak tersedia pada release ${tag}."

local_version="0.0.0"
if [[ -f "${app_dir}/version.json" ]]; then
  local_version="$(php -r '
    $value = json_decode((string) file_get_contents($argv[1]), true);
    echo (string) ($value["version"] ?? "0.0.0");
  ' "${app_dir}/version.json")"
fi

version_gt() {
  [[ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | tail -n 1)" == "$1" && "$1" != "$2" ]]
}

if ! version_gt "${remote_version}" "${local_version}"; then
  write_progress "done" 100 "J-BACKUP sudah versi terbaru (${local_version})." 0 0
  echo "J-BACKUP sudah versi terbaru (${local_version})."
  exit 0
fi

write_progress "download" 10 "Mengunduh ${asset_name}..." 0 0
download_file "${download_url}" "${archive_file}" \
  || fail "Paket update gagal diunduh."
archive_size="$(wc -c <"${archive_file}" | tr -d ' ')"
write_progress "download" 45 "Paket selesai diunduh." "${archive_size}" "${archive_size}"

if [[ -n "${checksum_url}" ]]; then
  download_file "${checksum_url}" "${checksum_file}" "text/plain" \
    || fail "Checksum release gagal diunduh."
  checksum_value="$(grep -oE '[a-fA-F0-9]{64}' "${checksum_file}" | head -n 1 || true)"
  [[ -n "${checksum_value}" ]] || fail "Checksum release tidak valid."
  if [[ -n "${expected_sha}" && "${expected_sha,,}" != "${checksum_value,,}" ]]; then
    fail "Checksum GitHub dan file checksum tidak cocok."
  fi
  expected_sha="${checksum_value,,}"
fi
[[ "${expected_sha}" =~ ^[a-f0-9]{64}$ ]] \
  || fail "Release tidak menyediakan checksum SHA-256 yang dapat diverifikasi."

actual_sha="$(php -r 'echo hash_file("sha256", $argv[1]);' "${archive_file}")"
[[ "${actual_sha,,}" == "${expected_sha,,}" ]] \
  || fail "Verifikasi SHA-256 paket update gagal."

write_progress "extract" 55 "Memeriksa dan mengekstrak paket..." 0 0
mkdir -p "${extract_dir}"
php -r '
  $archive = new ZipArchive();
  if ($archive->open($argv[1]) !== true) {
      fwrite(STDERR, "ZIP tidak dapat dibuka.\n");
      exit(2);
  }
  for ($index = 0; $index < $archive->numFiles; $index++) {
      $name = str_replace("\\", "/", (string) $archive->getNameIndex($index));
      if ($name === "" || str_starts_with($name, "/")
          || preg_match("#(^|/)\.\.(/|$)#", $name)) {
          fwrite(STDERR, "Path ZIP tidak aman: {$name}\n");
          exit(3);
      }
  }
  if (!$archive->extractTo($argv[2])) {
      fwrite(STDERR, "ZIP gagal diekstrak.\n");
      exit(4);
  }
  $archive->close();
' "${archive_file}" "${extract_dir}" || fail "Paket update gagal diekstrak."

for required in index.php api.php version.json src assets bin deploy scripts; do
  [[ -e "${extract_dir}/${required}" ]] || fail "Paket update tidak lengkap: ${required} hilang."
done
package_version="$(php -r '
  $value = json_decode((string) file_get_contents($argv[1]), true);
  echo (string) ($value["version"] ?? "");
' "${extract_dir}/version.json")"
[[ "${package_version}" == "${remote_version}" ]] \
  || fail "Versi paket (${package_version}) tidak cocok dengan release (${remote_version})."

[[ -w "${app_dir}" && -w "${app_dir}/scripts" ]] \
  || fail "Folder aplikasi tidak dapat ditulis oleh Web UI. Jalankan ulang installer untuk memperbaiki izin update."

backup_dir="${backup_root}/$(date +%Y%m%d-%H%M%S)-v${local_version}"
mkdir -p "${backup_dir}"
write_progress "install" 68 "Membuat cadangan kode versi ${local_version}..." 0 0
for directory in src assets bin deploy scripts; do
  [[ -d "${app_dir}/${directory}" ]] && rsync -a "${app_dir}/${directory}/" "${backup_dir}/${directory}/"
done
for file in index.php api.php version.json og.png .htaccess "Cara Install.md"; do
  [[ -f "${app_dir}/${file}" ]] && install -D -m 0644 "${app_dir}/${file}" "${backup_dir}/${file}"
done

rollback() {
  write_progress "rollback" 82 "Pemasangan gagal; memulihkan versi sebelumnya..." 0 0 || true
  for directory in src assets bin deploy scripts; do
    if [[ -d "${backup_dir}/${directory}" ]]; then
      mkdir -p "${app_dir}/${directory}"
      rsync -a --delete "${backup_dir}/${directory}/" "${app_dir}/${directory}/" || true
    fi
  done
  for file in index.php api.php version.json og.png .htaccess "Cara Install.md"; do
    [[ -f "${backup_dir}/${file}" ]] && cp -f -- "${backup_dir}/${file}" "${app_dir}/${file}" || true
  done
}

write_progress "install" 78 "Memasang kode J-BACKUP ${remote_version}..." 0 0
install_failed=0
for directory in src assets bin deploy scripts; do
  mkdir -p "${app_dir}/${directory}"
  rsync -a --delete "${extract_dir}/${directory}/" "${app_dir}/${directory}/" \
    || install_failed=1
done
for file in index.php api.php version.json og.png .htaccess "Cara Install.md"; do
  if [[ -f "${extract_dir}/${file}" ]]; then
    cp -f -- "${extract_dir}/${file}" "${app_dir}/${file}" || install_failed=1
  fi
done
if (( install_failed != 0 )); then
  rollback
  fail "File update gagal dipasang; versi sebelumnya sudah dipulihkan."
fi

chmod 0755 "${app_dir}/bin/worker.php" "${app_dir}/scripts/update-linux.sh" 2>/dev/null || true
chmod -R g+rwX "${app_dir}/src" "${app_dir}/assets" "${app_dir}/bin" \
  "${app_dir}/deploy" "${app_dir}/scripts" 2>/dev/null || true

write_progress "done" 100 "Update selesai. J-BACKUP ${remote_version} siap digunakan." 0 0
echo "J-BACKUP berhasil diperbarui dari ${local_version} ke ${remote_version}."
