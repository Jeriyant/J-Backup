(() => {
    "use strict";

const app = document.querySelector("#app");
    const modal = document.querySelector("#modal");
    const toastElement = document.querySelector("#toast");
    const days = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"];
    const navItems = [
        ["overview", "⌂", "Dashboard"],
        ["sources", "▦", "Sumber"],
        ["history", "↺", "Riwayat"],
        ["schedules", "◷", "Jadwal"],
        ["storage", "▤", "Penyimpanan"],
        ["explorer", `<svg class="nav-explorer-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3.75 7.25A2.25 2.25 0 0 1 6 5h4.1l1.7 2.05H18A2.25 2.25 0 0 1 20.25 9.3v7.45A2.25 2.25 0 0 1 18 19H6a2.25 2.25 0 0 1-2.25-2.25V7.25Z"></path><path d="M3.75 9h16.5"></path></svg>`, "File Explorer"],
        ["notifications", "✉", "Notifikasi"],
        ["settings", "⚙", "Pengaturan"],
        ["about", "i", "About"],
    ];
    const state = {
        mode: "loading",
        csrf: "",
        dashboard: null,
        tab: "overview",
        selected: new Set(),
        query: "",
        poller: null,
        publicKey: "",
        storage: null,
        storagePath: "",
        storageQuery: "",
        storageLoading: false,
        storageError: "",
        explorerKind: "backup",
        disks: null,
        latencyMs: null,
        accountMenu: false,
        lastActivityAt: Date.now(),
        idleTimer: null,
        mobileMenu: false,
        activeJobId: null,
        activeJobStatus: null,
        activeJobPoller: null,
        update: {
            checked: false,
            checking: false,
            release: null,
            error: "",
            applying: false,
            progress: null,
        },
        pathChecks: {
            rsync: null,
            backup: null,
        },
    };

    const escapeHtml = (value) => String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");

    const bytes = (value) => {
        const number = Number(value) || 0;
        if (number <= 0) return "0 B";
        const units = ["B", "KB", "MB", "GB", "TB"];
        const index = Math.min(Math.floor(Math.log(number) / Math.log(1024)), units.length - 1);
        return `${(number / (1024 ** index)).toFixed(index > 2 ? 1 : 0)} ${units[index]}`;
    };

    const byteUnitValue = (value) => {
        const number = Math.max(0, Number(value) || 0);
        const units = ["B", "KB", "MB", "GB", "TB"];
        const index = number > 0
            ? Math.min(Math.floor(Math.log(number) / Math.log(1024)), units.length - 1)
            : 2;
        return { value: Number((number / (1024 ** index)).toFixed(2)), unit: units[index] };
    };

    const backupFileNextRun = (unit, interval, time) => {
        const next = new Date();
        if (unit === "day") {
            const [hour, minute] = String(time || "00:00").split(":").map(Number);
            next.setHours(Number.isFinite(hour) ? hour : 0, Number.isFinite(minute) ? minute : 0, 0, 0);
            if (next <= new Date()) next.setDate(next.getDate() + 1);
        } else {
            const multiplier = unit === "hour" ? 60 * 60 * 1000 : 60 * 1000;
            next.setTime(next.getTime() + Math.max(1, Number(interval) || 1) * multiplier);
        }
        return new Intl.DateTimeFormat("id-ID", {
            day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit",
        }).format(next);
    };

    function updateBackupFileIntervalControls(form) {
        const unit = form?.elements?.telegram_backup_file_interval_unit;
        const interval = form?.querySelector("[data-backup-file-interval]");
        const startTime = form?.querySelector("[data-backup-file-start-time]");
        const label = form?.querySelector("[data-backup-file-schedule-label]");
        const help = form?.querySelector("[data-backup-file-schedule-help]");
        const next = form?.querySelector("[data-backup-file-next-run]");
        if (!unit || !interval || !startTime) return;
        const daily = unit.value === "day";
        interval.hidden = daily;
        interval.disabled = daily;
        startTime.hidden = !daily;
        startTime.disabled = !daily;
        if (label) label.textContent = daily ? "Waktu mulai pengiriman" : "Interval Pengiriman";
        if (help) help.textContent = daily
            ? "File akan dikirim satu kali setiap hari pada waktu ini."
            : "Masukkan jumlah menit atau jam antar-pengiriman.";
        if (next) next.textContent = backupFileNextRun(unit.value, interval.value, startTime.value);
    }

    const dateTime = (value) => {
        if (!value) return "—";
        return new Intl.DateTimeFormat("id-ID", {
            dateStyle: "medium",
            timeStyle: "short",
        }).format(new Date(value));
    };

    const jobDuration = (startedAt, finishedAt) => {
        const started = Date.parse(startedAt || "");
        const finished = Date.parse(finishedAt || "");
        if (!Number.isFinite(started) || !Number.isFinite(finished) || finished < started) return "—";
        const seconds = Math.floor((finished - started) / 1000);
        if (seconds < 60) return `${seconds} dtk`;
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;
        if (minutes < 60) return `${minutes} mnt ${remainingSeconds} dtk`;
        return `${Math.floor(minutes / 60)} jam ${minutes % 60} mnt`;
    };

    const duration = (seconds) => {
        if (seconds === null || seconds === undefined) return "—";
        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        return days ? `${days}h ${hours}j` : hours ? `${hours}j ${minutes}m` : `${minutes}m`;
    };

    const latencyClass = (milliseconds) => {
        if (!Number.isFinite(milliseconds)) return "latency-unknown";
        if (milliseconds <= 100) return "latency-good";
        if (milliseconds <= 250) return "latency-warning";
        return "latency-bad";
    };

    const sourceInitials = (name) => {
        const parts = String(name ?? "").trim().split(/[\s_-]+/).filter(Boolean);
        if (!parts.length) return "?";
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
    };

    const stopIcon = () => `
        <svg class="button-icon" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="8.5"></circle>
            <rect x="9" y="9" width="6" height="6" rx="1"></rect>
        </svg>`;

    const detailIcon = () => `
        <svg class="row-action-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M2.75 12s3.5-6 9.25-6 9.25 6 9.25 6-3.5 6-9.25 6S2.75 12 2.75 12Z"></path>
            <circle cx="12" cy="12" r="2.75"></circle>
        </svg>`;

    const openIcon = () => `
        <svg class="row-action-icon open-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M8 5.5 14.5 12 8 18.5"></path>
            <path d="M4.5 12h10"></path>
        </svg>`;

    const loginIcon = () => `
        <svg class="button-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M13 5h5a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-5"></path>
            <path d="m10 8 4 4-4 4"></path>
            <path d="M14 12H3"></path>
        </svg>`;

    const diskForPath = (path, disks) => {
        const normalized = String(path ?? "").replace(/\/+$/, "") || "/";
        return [...disks]
            .filter((disk) => {
                const mount = String(disk.path ?? "").replace(/\/+$/, "") || "/";
                return mount === "/" || normalized === mount || normalized.startsWith(`${mount}/`);
            })
            .sort((left, right) => String(right.path).length - String(left.path).length)[0] || null;
    };

    const statusText = (status) => ({
        queued: "Antrean",
        running: "Berjalan",
        success: "Sukses",
        failed: "Gagal",
        cancelled: "Dibatalkan",
        cancel_requested: "Membatalkan",
    }[status] || status);

    const verificationText = (verification) => ({
        "destination-present": "Tujuan tersedia",
        "destination-present-and-readable": "Arsip tersedia dan dapat dibaca",
        failed: "Verifikasi gagal",
    }[verification] || "Belum tersedia");

    const workerIsReady = (heartbeat, maximumAge = 45000) => {
        if (!heartbeat) return false;
        const timestamp = Date.parse(heartbeat);
        return Number.isFinite(timestamp) && Date.now() - timestamp <= maximumAge;
    };

    const scheduleText = (schedule) => {
        if (schedule.mode === "minutes") {
            return `Setiap ${schedule.interval_value} menit`;
        }
        if (schedule.mode === "hours") {
            return `Setiap ${schedule.interval_value} jam`;
        }
        if (schedule.mode === "daily") {
            return `Setiap hari pukul ${schedule.time}`;
        }
        return `Setiap hari pukul ${schedule.time}`;
    };

    const timezoneOptions = (selected) => {
        const fallback = ["Asia/Jakarta", "Asia/Makassar", "Asia/Jayapura", "UTC"];
        const timezones = typeof Intl.supportedValuesOf === "function"
            ? Intl.supportedValuesOf("timeZone")
            : fallback;
        if (!timezones.includes(selected)) timezones.push(selected);
        return [...new Set(timezones)].sort().map((timezone) =>
            `<option value="${escapeHtml(timezone)}" ${timezone === selected ? "selected" : ""}>${escapeHtml(timezone)}</option>`
        ).join("");
    };

    async function api(action, {
        method = "GET",
        body,
        query = {},
        background = false,
    } = {}) {
        const parameters = new URLSearchParams({ action, ...query });
        const response = await fetch(`api.php?${parameters.toString()}`, {
            method,
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                ...(state.csrf ? { "X-CSRF-Token": state.csrf } : {}),
                ...(background ? { "X-JBACKUP-Background": "1" } : {}),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.error || `Permintaan gagal (${response.status}).`);
        return payload;
    }

    function promoteToast() {
        if (typeof toastElement.showPopover !== "function") return;
        if (toastElement.matches(":popover-open")) toastElement.hidePopover();
        toastElement.showPopover();
    }

    function toast(message) {
        toastElement.textContent = message;
        toastElement.hidden = false;
        promoteToast();
        clearTimeout(toastElement.timer);
        toastElement.timer = setTimeout(() => {
            if (typeof toastElement.hidePopover === "function"
                && toastElement.matches(":popover-open")) {
                toastElement.hidePopover();
            }
            toastElement.hidden = true;
        }, 3400);
    }

    function releaseNotesPreview(notes, limit = 3) {
        return String(notes || "")
            .split(/\r?\n/)
            .map((line) => line.trim().replace(/^[-*+]\s+/, ""))
            .filter((line) => line && !line.startsWith("#"))
            .slice(0, limit)
            .join(" \u00b7 ");
    }

    function renderUpdateBanner() {
        const release = state.update.release;
        if (!release?.update_available) return "";
        if (localStorage.getItem("jbackup-update-dismissed") === release.tag) return "";
        const preview = releaseNotesPreview(release.notes);
        return `<aside class="update-banner" aria-live="polite">
            <span class="update-banner-icon">\u2191</span>
            <div class="update-banner-copy"><strong>J-BACKUP ${escapeHtml(release.latest_version)} tersedia</strong>
                <small>${escapeHtml(preview || "Versi terbaru siap dipasang dari GitHub Release.")}</small></div>
            <div class="update-banner-actions">
                ${release.installable ? `<button class="button primary" data-action="install-update"><span>\u2193</span>Update sekarang</button>` : ""}
                <a class="button ghost" href="${escapeHtml(release.release_url || "#")}" target="_blank" rel="noopener noreferrer">Lihat rilis</a>
                <button class="icon-button" data-action="dismiss-update" aria-label="Ingatkan nanti">\u00d7</button>
            </div>
        </aside>`;
    }

    async function checkForUpdates({ silent = false, showResult = false } = {}) {
        if (state.update.checking) return state.update.release;
        state.update.checking = true;
        state.update.error = "";
        try {
            const release = await api("update_check", { background: silent });
            state.update.checked = true;
            state.update.release = release;
            if (state.mode === "ready") renderApp();
            if (showResult) updateReleaseDialog(release);
            return release;
        } catch (error) {
            state.update.checked = true;
            state.update.error = error.message;
            if (!silent) showModal(`<p class="eyebrow">PEMBARUAN</p><h2>GitHub tidak dapat diperiksa</h2><p class="muted">${escapeHtml(error.message)}</p>`);
            return null;
        } finally {
            state.update.checking = false;
        }
    }

    function updateReleaseDialog(release = state.update.release) {
        if (!release) return;
        const preview = releaseNotesPreview(release.notes, 8);
        const available = Boolean(release.update_available);
        showModal(`<p class="eyebrow">PEMBARUAN GITHUB</p>
            <h2>${available ? `Versi ${escapeHtml(release.latest_version)} tersedia` : "Aplikasi sudah terbaru"}</h2>
            <div class="update-version-grid"><span><small>Versi terpasang</small><strong>${escapeHtml(release.current_version)}</strong></span><span><small>GitHub Release</small><strong>${escapeHtml(release.latest_version)}</strong></span></div>
            ${preview ? `<p class="update-release-notes">${escapeHtml(preview)}</p>` : ""}
            ${available && !release.installable ? `<div class="error-box">Release ditemukan, tetapi aset j-backup-dist.zip atau checksum belum tersedia.</div>` : ""}
            <div class="update-dialog-actions">
                ${available && release.installable ? `<button class="button primary" data-action="confirm-install-update"><span>\u2193</span>Pasang update</button>` : ""}
                <a class="button ghost" href="${escapeHtml(release.release_url || "#")}" target="_blank" rel="noopener noreferrer">Buka GitHub Release</a>
            </div>`);
    }

    function renderUpdateProgress(progress) {
        const percent = Math.max(0, Math.min(100, Number(progress?.percent) || 0));
        const host = modal.querySelector("[data-update-progress]");
        if (!host) return;
        host.innerHTML = `<div class="update-progress-heading"><strong>${escapeHtml(progress?.message || "Mempersiapkan update...")}</strong><span>${percent}%</span></div><div class="update-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${percent}"><i style="width:${percent}%"></i></div>`;
    }

    async function installUpdate() {
        if (state.update.applying) return;
        state.update.applying = true;
        showModal(`<p class="eyebrow">PEMBARUAN SISTEM</p><h2>Memasang update J-BACKUP</h2><p class="muted">Jangan menutup halaman sampai proses selesai. Database, key SSH, RSYNC, dan BACKUP tetap dipertahankan.</p><div data-update-progress></div>`, true);
        renderUpdateProgress({ stage: "check", percent: 0, message: "Memulai updater..." });
        let progressTimer = setInterval(async () => {
            try {
                const progress = await api("update_progress", { background: true });
                state.update.progress = progress;
                renderUpdateProgress(progress);
            } catch (_) {
                // Request utama tetap menentukan keberhasilan update.
            }
        }, 500);
        try {
            const result = await api("update_apply", { method: "POST", body: {} });
            clearInterval(progressTimer);
            progressTimer = null;
            state.update.applying = false;
            showModal(`<p class="eyebrow">UPDATE SELESAI</p><h2>J-BACKUP ${escapeHtml(result.version)} siap digunakan</h2><p class="muted">Muat ulang aplikasi untuk menggunakan versi terbaru.</p><button class="button primary wide" data-action="reload-app"><span>\u21bb</span>Muat ulang sekarang</button>`);
        } catch (error) {
            if (progressTimer) clearInterval(progressTimer);
            state.update.applying = false;
            showModal(`<p class="eyebrow">UPDATE GAGAL</p><h2>Versi lama tetap dipertahankan</h2><div class="error-box">${escapeHtml(error.message)}</div><button class="button ghost wide" data-action="check-update"><span>\u21bb</span>Periksa kembali</button>`);
        }
    }

    function showModal(content, wide = false) {
        stopActiveJobPolling();
        state.activeJobId = null;
        state.activeJobStatus = null;
        const useTopLayerToast = typeof toastElement.showPopover === "function";
        modal.className = `${wide ? "modal wide" : "modal"}${useTopLayerToast ? "" : " modal-fallback"}`;
        modal.innerHTML = `<button class="modal-close" data-action="close-modal" aria-label="Tutup">×</button><div class="modal-content">${content}</div>`;
        if (!modal.open) {
            if (useTopLayerToast) modal.showModal();
            else modal.show();
        }
        if (!toastElement.hidden) promoteToast();
    }

    const languageText = {
        id: { "Server data safety": "Keamanan data server", "File Explorer": "Penjelajah File", "Worker": "Pekerja", "Storage": "Penyimpanan", "Connect": "Hubungkan", "Disconnect": "Putuskan koneksi", "Upload": "Unggah", "Refresh": "Muat ulang" },
        en: { "Dasbor": "Dashboard", "Sumber": "Sources", "Riwayat": "History", "Jadwal": "Schedules", "Penyimpanan": "Storage", "Penjelajah File": "File Explorer", "Notifikasi": "Notifications", "Pengaturan": "Settings", "Jalankan": "Run", "KONEKSI SUMBER": "SOURCE CONNECTION", "HASIL BACKUP": "BACKUP OUTPUT", "Lokasi & Penamaan": "Location & Naming", "Folder tujuan": "Destination folder", "Kompresi 7z": "7z Compression", "Minimum ruang kosong": "Minimum free space", "Zona waktu": "Time zone", "Tes akses folder": "Test folder access", "Simpan": "Save", "DATA RSYNC": "RSYNC DATA", "Tujuan RSYNC": "RSYNC Destination", "Folder data RSYNC": "RSYNC data folder", "Data Backup": "Backup Data", "Data RSYNC": "RSYNC Data", "Bahasa aplikasi": "Application language", "Bahasa Indonesia": "Indonesian", "Bahasa Inggris": "English" }
    };

    function applyLanguage() {
        const language = state.dashboard?.settings?.language === "en" ? "en" : "id";
        document.documentElement.lang = language;
        const translations = Object.entries(languageText[language]).sort((a, b) => b[0].length - a[0].length);
        const translate = (value) => translations.reduce((text, [from, to]) => text.split(from).join(to), value);
        const walker = document.createTreeWalker(app, NodeFilter.SHOW_TEXT);
        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach((node) => { node.nodeValue = translate(node.nodeValue); });
        app.querySelectorAll("[aria-label], [title], [placeholder]").forEach((element) => ["aria-label", "title", "placeholder"].forEach((attribute) => {
            if (element.hasAttribute(attribute)) element.setAttribute(attribute, translate(element.getAttribute(attribute)));
        }));
    }

    function closeModal() {
        stopActiveJobPolling();
        state.activeJobId = null;
        state.activeJobStatus = null;
        if (modal.open) modal.close();
    }

    function renderAuth(setupRequired, error = "") {
        state.mode = setupRequired ? "setup" : "login";
        app.innerHTML = `
            <main class="auth-shell">
                <section class="auth-card">
                    <div class="brand">
                        <span class="brand-mark">J</span>
                        <span class="brand-copy"><strong>J-BACKUP</strong><small>Server data safety</small></span>
                    </div>
                    <p class="eyebrow">${setupRequired ? "PENGATURAN PERTAMA" : "AREA ADMINISTRATOR"}</p>
                    <h1>${setupRequired ? "Buat akses administrator" : "Selamat Datang"}</h1>
                    <p class="muted">${setupRequired
                        ? "Akun ini melindungi konfigurasi dan pekerjaan backup."
                        : "Masuk untuk mengelola RSYNC dan backup server."}</p>
                    <form class="form" data-form="auth">
                        <label>Username<input name="username" autocomplete="username" required><small>Masukkan username administrator Anda.</small></label>
                        <label>Password<span class="password-control">
                            <input id="auth-password" name="password" type="password" minlength="1"
                                autocomplete="${setupRequired ? "new-password" : "current-password"}" required>
                            <button class="password-toggle" type="button" data-action="toggle-auth-password"
                                aria-label="Tampilkan password" aria-pressed="false" title="Tampilkan password">
                                <span class="eye-icon" aria-hidden="true"></span>
                            </button>
                        </span><small>Masukkan password administrator Anda.</small></label>
                        ${error ? `<p class="auth-error">${escapeHtml(error)}</p>` : ""}
                        <button class="button primary wide action-access" type="submit">
                            ${loginIcon()}${setupRequired ? "Buat akun & masuk" : "Masuk ke dashboard"}
                        </button>
                    </form>
                </section>
            </main>`;
    }

    async function measureLatency() {
        const samples = [];
        for (let index = 0; index < 3; index += 1) {
            const target = new URL("assets/app.css", window.location.href);
            target.searchParams.set("latency", `${Date.now()}-${index}`);
            const started = performance.now();
            try {
                const response = await fetch(target, {
                    method: "HEAD",
                    cache: "no-store",
                    credentials: "same-origin",
                });
                if (response.ok) {
                    samples.push(Math.max(0, performance.now() - started));
                }
            } catch {
                return null;
            }
        }
        if (!samples.length) return null;
        samples.sort((left, right) => left - right);
        return Math.round(samples[Math.floor(samples.length / 2)]);
    }

    async function loadDashboard(render = true, background = false) {
        const latencyPromise = measureLatency();
        state.dashboard = await api("dashboard", { background });
        state.pathChecks.rsync =
            state.dashboard.path_checks?.rsync || null;
        state.pathChecks.backup =
            state.dashboard.path_checks?.backup || null;
        state.latencyMs = await latencyPromise;
        if (render) {
            renderApp();
            if (state.tab === "settings") await revealStoredSshPassword();
        }
    }

    async function revealStoredSshPassword() {
        if (!state.dashboard?.settings?.ssh_password_saved) return;
        const input = document.querySelector("#ssh-password");
        if (!input || input.value) return;
        const result = await api("ssh_password_reveal", {
            method: "POST",
            body: {},
        });
        if (document.querySelector("#ssh-password") === input) {
            input.value = result.password;
            input.dataset.secretLoaded = "true";
        }
    }

    async function loadStorage(path = state.storagePath) {
        state.storagePath = path;
        state.storageLoading = true;
        state.storageError = "";
        if (state.tab === "explorer") renderApp();
        try {
            state.storage = await api(`${state.explorerKind}_list`, { query: { path } });
            state.storagePath = state.storage.path;
        } catch (error) {
            state.storage = null;
            state.storageError = error.message;
            throw error;
        } finally {
            state.storageLoading = false;
            if (state.tab === "explorer") renderApp();
        }
    }

    async function loadDisks() {
        state.disks = await api("disk_list");
        if (state.tab === "storage") renderApp();
    }

    async function uploadStorageFile(file) {
        if (!file) return;
        const form = new FormData();
        form.append("path", state.storagePath);
        form.append("kind", state.explorerKind);
        form.append("file", file);
        const response = await fetch("api.php?action=storage_upload", {
            method: "POST",
            credentials: "same-origin",
            headers: { "X-CSRF-Token": state.csrf },
            body: form,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.error || `Upload gagal (${response.status}).`);
        }
        toast(`${payload.file.name} berhasil diupload.`);
        await loadStorage();
        await loadDashboard(false);
    }

    async function importSourcesFile(file) {
        if (!file) throw new Error("Pilih file Excel atau CSV terlebih dahulu.");
        const form = new FormData();
        form.append("file", file);
        const response = await fetch("api.php?action=source_import", {
            method: "POST",
            credentials: "same-origin",
            headers: { "X-CSRF-Token": state.csrf },
            body: form,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok && !Array.isArray(payload.errors)) {
            throw new Error(
                payload.error || `Impor gagal (${response.status}).`
            );
        }
        await loadDashboard();
        showModal(`
            <p class="eyebrow">HASIL IMPORT</p>
            <h2>${payload.imported_count || 0} sumber berhasil diproses</h2>
            <p class="muted">${payload.created_count || 0} baru · ${payload.updated_count || 0} diperbarui · ${payload.failed_count || 0} gagal.</p>
            <div class="import-summary">
                <div><strong>${payload.imported_count || 0}</strong><span>Berhasil</span></div>
                <div class="${payload.failed_count ? "has-errors" : ""}">
                    <strong>${payload.failed_count || 0}</strong><span>Gagal</span>
                </div>
            </div>
            ${payload.errors?.length ? `
                <div class="import-errors">
                    ${payload.errors.map((error) => `
                        <div><strong>Baris ${error.row}${error.name ? ` · ${escapeHtml(error.name)}` : ""}</strong>
                            <span>${escapeHtml(error.message)}</span></div>
                    `).join("")}
                </div>` : ""}
            <button class="button primary wide" data-action="close-modal">
                <span>✓</span>Selesai
            </button>
        `, true);
    }

    function renderApp() {
        const dashboard = state.dashboard;
        if (!dashboard) return;
        state.mode = "ready";
        const workerReady = workerIsReady(dashboard.worker_heartbeat);
        const workerWorking = workerReady && Boolean(dashboard.active_job);
        const workerLabel = workerWorking
            ? "Worker sedang bekerja"
            : workerReady
                ? "Worker siap"
                : "Worker tidak terhubung";
        const title = navItems.find(([id]) => id === state.tab)?.[2] || "J-BACKUP";
        app.innerHTML = `
            <div class="app-shell">
                <aside class="sidebar ${state.mobileMenu ? "mobile-open" : ""}">
                    <div class="brand">
                        <span class="brand-mark">J</span>
                        <span class="brand-copy"><strong>J-BACKUP</strong><small>Server data safety</small></span>
                    </div>
                    <nav class="nav" aria-label="Navigasi utama">
                        ${navItems.map(([id, number, label]) => `
                            <button data-tab="${id}" class="${state.tab === id ? "active" : ""}">
                                <i>${number}</i>${label}
                            </button>`).join("")}
                    </nav>
                    <div class="sidebar-footer">
                        <div class="service-state ${workerReady ? "" : "offline"}"><i></i><span>
                            <strong>${workerLabel}</strong>
                            <small>Versi ${escapeHtml(dashboard.version)}</small></span></div>
                        <button class="icon-button" data-action="theme" aria-label="Ganti tema">◐</button>
                    </div>
                </aside>
                ${state.mobileMenu ? `
                    <button class="sidebar-scrim" data-action="mobile-menu-close"
                        aria-label="Tutup menu navigasi"></button>` : ""}
                <main class="main">
                    <header class="topbar">
                        <div class="topbar-title">
                            <button class="mobile-menu-button" data-action="mobile-menu"
                                aria-label="Buka menu navigasi" aria-expanded="${state.mobileMenu}">
                                <span></span><span></span><span></span>
                            </button>
                            <div><p class="eyebrow">${escapeHtml(title.toUpperCase())}</p><h1>${escapeHtml(title)}</h1></div>
                        </div>
                        <div class="top-actions">
                            <button class="button manual-action" data-action="manual"><span>▶</span>Jalankan</button>
                            <div class="account-control">
                                <button class="user-chip" data-action="account-menu"
                                    aria-expanded="${state.accountMenu}" title="Menu akun">
                                    <i>${escapeHtml(dashboard.user.username.slice(0, 1).toUpperCase())}</i>
                                    ${escapeHtml(dashboard.user.username)}
                                    <span class="account-chevron">⌄</span>
                                </button>
                                ${state.accountMenu ? `
                                    <div class="account-menu">
                                        <button data-action="account-settings">
                                            <span class="account-menu-icon">⚙</span>
                                            <span><strong>Pengaturan akun</strong><small>Username, password & timeout</small></span>
                                        </button>
                                        <button class="account-logout" data-action="logout">
                                            <span class="account-menu-icon">↪</span>
                                            <span><strong>Logout</strong><small>Keluar dari aplikasi</small></span>
                                        </button>
                                    </div>` : ""}
                            </div>
                        </div>
                    </header>
                    ${renderUpdateBanner()}
                    <section id="view">${renderView()}</section>
                </main>
                <footer class="app-copyright">Copyright © JERIYANT - BARAMCITY</footer>
            </div>`;
        applyLanguage();
    }

    function renderView() {
        if (state.tab === "sources") return renderSources();
        if (state.tab === "history") return renderHistory();
        if (state.tab === "schedules") return renderSchedules();
        if (state.tab === "storage") return renderStorage();
        if (state.tab === "explorer") return renderExplorer(state.explorerKind);
        if (state.tab === "notifications") return renderNotifications();
        if (state.tab === "settings") return renderSettings();
        if (state.tab === "about") return renderAbout();
        return renderOverview();
    }

    function renderOverview() {
        const d = state.dashboard;
        const active = d.active_job;
        const system = d.system || {};
        const workerReady = workerIsReady(d.worker_heartbeat);
        const activeSources = d.sources.filter((item) => item.enabled);
        const enabledSchedules = d.schedules.filter((schedule) => schedule.enabled);
        const recentFailureLimit = Date.now() - 86400000;
        const failures = d.jobs.filter((job) =>
            job.status === "failed"
            && Date.parse(job.finished_at || job.started_at || job.queued_at) >= recentFailureLimit
        ).length;
        const successes = d.jobs.filter((job) => job.status === "success").length;
        const failedJobs = d.jobs.filter((job) => job.status === "failed").length;
        const sshConnected = d.settings.ssh_connected === true;
        const sshTarget = d.settings.ssh_connected_target
            || (d.settings.remote_host
                ? `${d.settings.remote_user}@${d.settings.remote_host}`
                : "Host sumber belum diatur");
        const critical = [];
        const warnings = [];
        if (!workerReady) critical.push("Worker Tidak Terhubung");
        if (!d.disk.available) {
            critical.push("Folder BACKUP Tidak Tersedia");
        } else if (d.disk.free < Number(d.settings.minimum_free_bytes || 0)) {
            critical.push("Ruang Disk Di Bawah Batas Minimum");
        }
        if (Number(system.cpu_percent) >= 95) critical.push("CPU Mencapai Batas Kritis");
        else if (Number(system.cpu_percent) >= 80) warnings.push("Penggunaan CPU Tinggi");
        if (Number(system.memory?.used_percent) >= 95) critical.push("Memory Mencapai Batas Kritis");
        else if (Number(system.memory?.used_percent) >= 80) warnings.push("Penggunaan Memory Tinggi");
        if (Number.isFinite(state.latencyMs) && state.latencyMs > 500) {
            critical.push("Latensi Browser Sangat Tinggi");
        } else if (Number.isFinite(state.latencyMs) && state.latencyMs > 250) {
            warnings.push("Latensi Browser Tinggi");
        }
        if (activeSources.length && !sshConnected) warnings.push("SSH Sumber Belum Terhubung");
        if (!enabledSchedules.length) warnings.push("Tidak Ada Jadwal Aktif");
        if (failures) warnings.push(`${failures} Pekerjaan Gagal Dalam 24 Jam`);

        const healthLevel = critical.length
            ? "critical"
            : active
                ? "running"
                : warnings.length
                    ? "warning"
                    : "healthy";
        const headline = {
            critical: "Kritis",
            warning: "Peringatan",
            running: "Bekerja",
            healthy: "Normal",
        }[healthLevel];
        const detail = critical.length
            ? [...critical, ...warnings].join(" · ")
            : active
                ? `${active.source_name} Sedang Diproses. ${d.queue_count} Pekerjaan Menunggu.${warnings.length ? ` Catatan: ${warnings.join(" · ")}` : ""}`
                : warnings.length
                    ? warnings.join(" · ")
                    : "Worker Aktif · SSH Terhubung · Disk & Resource Normal · Jadwal Aktif";
        const healthBadge = {
            critical: "!",
            warning: "WARN",
            running: "RUN",
            healthy: "OK",
        }[healthLevel];
        return `
            <div class="grid overview-grid">
                <article class="hero hero-status-${healthLevel}">
                    <div class="hero-copy"><p class="eyebrow">KESEHATAN SISTEM</p><h2>${escapeHtml(headline)}</h2>
                        <p>${escapeHtml(detail)}</p>
                        <small class="health-scope">Worker · SSH · Disk · CPU · Memory · Jadwal · Pekerjaan</small></div>
                    <div class="health"><span>${healthBadge}</span></div>
                </article>
                <div class="metrics">
                    <article class="metric"><p>Sumber aktif</p><strong>${d.sources.filter((item) => item.enabled).length}</strong><small>dari ${d.sources.length} terdaftar</small></article>
                    <article class="metric"><p>Job berhasil</p><strong>${successes}</strong><small>dari ${d.jobs.length} riwayat terakhir</small></article>
                    <article class="metric metric-failed"><p>Job gagal</p><strong>${failedJobs}</strong><small>dari ${d.jobs.length} riwayat terakhir</small></article>
                    <article class="metric"><p>Ruang tersedia</p><strong>${bytes(d.disk.free)}</strong><small>${d.disk.used_percent}% disk terpakai</small></article>
                </div>
                <article class="panel system-monitor">
                    <div class="panel-heading"><div><p class="eyebrow">HOST SERVER</p><h2>Informasi sistem</h2></div>
                        <span class="status ${workerReady ? "status-success" : "status-failed"}">${workerReady ? "Live" : "Offline"}</span></div>
                    <div class="system-metrics">
                        <div><span>Uptime</span><strong>${duration(system.uptime_seconds)}</strong></div>
                        <div><span>CPU</span><strong>${system.cpu_percent ?? "—"}%</strong><small>${system.cpu_cores || "—"} core</small></div>
                        <div><span>Memory</span><strong>${system.memory?.used_percent ?? "—"}%</strong><small>${bytes(system.memory?.used)} / ${bytes(system.memory?.total)}</small></div>
                        <div><span>Latensi</span><strong class="latency-value ${latencyClass(state.latencyMs)}">${state.latencyMs ?? "—"} ms</strong><small>Browser → server</small></div>
                    </div>
                </article>
                <article class="panel schedule-monitor">
                    <div class="panel-heading"><div><p class="eyebrow">OTOMASI</p><h2>Status jadwal</h2></div>
                        <button class="text-button" data-tab="schedules">Atur jadwal →</button></div>
                    <div class="schedule-status-list">
                        ${d.schedules.map((schedule) => `<div>
                            <span><i class="${schedule.enabled ? "online" : ""}"></i>RSYNC & BACKUP</span>
                            <div style="text-align: right;">
                                <strong>${schedule.enabled ? scheduleText(schedule) : "Nonaktif"}</strong>
                                ${schedule.enabled && schedule.next_run ? `<p style="font-size: 11px; font-weight: 600; margin: 2px 0 0;">Next Run: ${escapeHtml(schedule.next_run)}</p>` : ""}
                            </div>
                        </div>`).join("")}
                    </div>
                </article>
                <article class="panel disk-panel">
                    <div class="panel-heading"><div><p class="eyebrow">PENYIMPANAN TUJUAN</p><h2>Kapasitas disk</h2></div>
                        <button class="text-button" data-tab="storage">Lihat detail →</button></div>
                    <div class="disk-summary">
                        <div class="disk-ring" style="--used:${Math.min(d.disk.used_percent * 3.6, 360)}deg">
                            <span><strong>${d.disk.used_percent}%</strong>terpakai</span></div>
                        <div class="disk-copy"><p class="path">${escapeHtml(d.disk.path)}</p>
                            <div class="disk-legend"><span><i></i>Terpakai <strong>${bytes(d.disk.used)}</strong></span>
                                <span><i></i>Tersedia <strong>${bytes(d.disk.free)}</strong></span></div></div>
                    </div>
                </article>
                <article class="panel ssh-status-panel ${sshConnected ? "connected" : "disconnected"}">
                    <div class="panel-heading"><div><p class="eyebrow">KONEKSI SUMBER</p><h2>Status SSH</h2></div>
                        <span class="connection-badge"><i></i>${sshConnected ? "Terhubung" : "Belum terhubung"}</span></div>
                    <div class="ssh-status-body">
                        <div class="ssh-status-icon">${sshConnected ? "✓" : "!"}</div>
                        <div>
                            <strong>${sshConnected ? "Akses tanpa password siap" : "Server sumber belum terhubung"}</strong>
                            <code>${escapeHtml(sshTarget)}</code>
                            <small>${sshConnected
                                ? `Terverifikasi ${dateTime(d.settings.ssh_connected_at)}`
                                : "Buka Pengaturan untuk membuat key dan menguji koneksi SSH."}</small>
                        </div>
                    </div>
                    <button class="button ssh-manage" type="button" data-tab="settings">
                        <span>⚙</span>${sshConnected ? "Kelola koneksi" : "Hubungkan SSH"}
                    </button>
                </article>
                <article class="panel recent-panel">
                    <div class="panel-heading"><div><p class="eyebrow">AKTIVITAS</p><h2>Riwayat terbaru</h2></div>
                        <button class="text-button" data-tab="history">Semua riwayat →</button></div>
                    ${jobTable(d.jobs.slice(0, 5))}
                </article>
            </div>`;
    }

    function renderSources() {
        const d = state.dashboard;
        const filtered = d.sources.filter((item) =>
            `${item.name}`.toLowerCase()
                .includes(state.query.toLowerCase())
        );
        return `
            <article class="panel full">
                <div class="panel-heading">
                    <div><p class="eyebrow">SUMBER BACKUP</p><h2>Daftar sumber</h2>
                        <p class="muted">Setiap sumber dapat berisi satu atau banyak path file atau folder remote.</p></div>
                    <div class="toolbar">
                        <button class="button excel-button" data-action="export-sources">
                            <svg class="excel-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 2h10l4 4v16H5z" fill="#fff"/><path d="M15 2v5h5" fill="#b7e1cd"/><path d="m7.4 9 2.1 3-2.2 3h2l1.3-2 1.3 2h2l-2.2-3 2.1-3h-2l-1.2 1.9L9.4 9z" fill="#217346"/></svg>Export Excel
                        </button>
                        <button class="button excel-button" data-action="import-sources">
                            <svg class="excel-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 2h10l4 4v16H5z" fill="#fff"/><path d="M15 2v5h5" fill="#b7e1cd"/><path d="m7.4 9 2.1 3-2.2 3h2l1.3-2 1.3 2h2l-2.2-3 2.1-3h-2l-1.2 1.9L9.4 9z" fill="#217346"/></svg>Import Excel
                        </button>
                        <button class="button primary" data-action="add"><span>＋</span>Tambah sumber</button></div>
                </div>
                <div class="tools">
                    <label class="search"><input id="source-search" value="${escapeHtml(state.query)}" placeholder="Cari sumber…" aria-label="Cari sumber"></label>
                    <span>${state.selected.size} dipilih</span>
                    <button class="text-button" data-action="select-all">${state.selected.size === filtered.length && filtered.length ? "Batalkan pilihan" : "Pilih semua"}</button>
                    <button class="button danger source-bulk-delete" data-action="delete-selected"
                        ${state.selected.size ? "" : "disabled"}>
                        <span>×</span>Hapus dipilih
                    </button>
                </div>
                <div>
                    ${filtered.map((item) => `
                        <article class="database-row ${item.enabled ? "" : "off"}">
                            <input type="checkbox" data-select-id="${item.id}" ${state.selected.has(item.id) ? "checked" : ""} aria-label="Pilih ${escapeHtml(item.name)}">
                            <div class="database-icon">${escapeHtml(sourceInitials(item.name))}</div>
                            <div class="database-copy"><div class="database-title">
                                <span class="source-id">${escapeHtml(item.id)}</span>
                                <strong>${escapeHtml(item.name)}</strong>
                            </div>
                                <small>${item.paths.map((path) => escapeHtml(path.path)).join(" · ")}</small></div>
                            <span class="tag">${item.archive_mode === "separate" ? "Terpisah" : "Gabungkan"}</span>
                            <label class="switch"><input type="checkbox" data-toggle-id="${item.id}" ${item.enabled ? "checked" : ""}><span></span></label>
                            <button class="row-button" data-edit-id="${item.id}" aria-label="Edit ${escapeHtml(item.name)}">✎</button>
                            <button class="row-button" data-delete-id="${item.id}" aria-label="Hapus ${escapeHtml(item.name)}">×</button>
                        </article>`).join("") || `<div class="empty"><strong>Belum ada sumber</strong>Tambahkan nama dan satu atau beberapa path file atau folder remote.</div>`}
                </div>
                ${state.selected.size ? `
                    <div class="selection-bar"><strong>${state.selected.size} sumber dipilih</strong><span>Jalankan hanya untuk pilihan ini.</span>
                        <button class="button primary" data-run="backup"><span>↕</span>RSYNC & BACKUP</button></div>` : ""}
            </article>`;
    }

    function renderHistory() {
        const cancellableJobs = state.dashboard.jobs.filter(
            (job) => ["queued", "running"].includes(job.status)
        );
        const historyJobs = state.dashboard.jobs.filter(
            (job) => !["queued", "running", "cancel_requested"].includes(job.status)
        );
        return `<article class="panel full"><div class="panel-heading"><div><p class="eyebrow">AUDIT PEKERJAAN</p>
            <h2>Riwayat RSYNC & BACKUP</h2></div>
            <div class="toolbar">
                <span class="tag">${state.dashboard.jobs.length} pekerjaan</span>
                ${historyJobs.length ? `
                    <button class="button danger" data-action="clear-job-history">
                        <span>×</span>Hapus riwayat
                    </button>` : ""}
                ${cancellableJobs.length ? `
                    <button class="button danger" data-action="cancel-all-jobs">
                        <span>${stopIcon()}</span>Batalkan semua (${cancellableJobs.length})
                    </button>` : ""}
            </div></div>
            ${jobTable(state.dashboard.jobs)}</article>`;
    }

    function jobTable(jobs) {
        if (!jobs.length) return `<div class="empty"><strong>Belum ada pekerjaan</strong>Riwayat akan muncul di sini.</div>`;
        const sortedJobs = [...jobs].sort((left, right) => {
            const leftTime = Date.parse(left.started_at || left.queued_at || "") || 0;
            const rightTime = Date.parse(right.started_at || right.queued_at || "") || 0;
            return rightTime - leftTime || Number(right.id) - Number(left.id);
        });
        return `<div class="table-wrap jobs-table-wrap"><table class="jobs-table"><thead><tr><th>Sumber</th><th>Proses</th><th>Status</th><th>Ukuran</th><th>Durasi</th><th>Waktu</th><th></th></tr></thead>
            <tbody>${sortedJobs.map((job) => `<tr>
                <td class="job-source" data-label="Sumber"><strong>${escapeHtml(job.source_name)}</strong><small>${escapeHtml(job.output_path || job.error || "Menunggu proses")}</small></td>
                <td data-label="Proses">RSYNC & BACKUP</td>
                <td class="job-status-cell" data-label="Status"><span class="status status-${escapeHtml(job.status)}">${statusText(job.status)}</span>${jobTableProgressMarkup(job)}</td>
                <td>${job.size_bytes ? bytes(job.size_bytes) : "—"}</td><td>${jobDuration(job.started_at, job.finished_at)}</td><td>${dateTime(job.started_at || job.queued_at)}</td>
                <td class="job-detail-cell"><button class="row-button" data-job-id="${escapeHtml(job.id)}" aria-label="Lihat detail" title="Lihat detail">${detailIcon()}</button></td>
            </tr>`).join("")}</tbody></table></div>`;
    }

    function jobTableProgressMarkup(job) {
        if (!jobIsActive(job)) return "";
        const queued = job.status === "queued";
        const progress = Math.max(0, Math.min(100, Number(job.progress) || 0));
        const label = queued ? "Menunggu" : `${progress}%`;
        const width = queued ? 34 : progress;
        return `<div class="table-job-progress ${queued ? "is-indeterminate" : ""}" role="progressbar" aria-label="Progress ${escapeHtml(job.source_name)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${progress}"><span><i style="width:${width}%"></i></span><small>${label}</small></div>`;
    }

    function renderSchedules() {
        return `<div class="grid cards-2">${state.dashboard.schedules.map((schedule) => `
            <form class="panel schedule-card schedule-${schedule.type} ${schedule.enabled ? "is-enabled" : "is-disabled"}"
                data-form="schedule-${schedule.type}">
                <div class="schedule-top"><div class="schedule-icon">↕</div>
                    <label class="switch"><input type="checkbox" data-schedule-enabled="${schedule.type}" ${schedule.enabled ? "checked" : ""}><span></span></label></div>
                <p class="eyebrow">ALUR TERPADU</p>
                <h2>Jadwal RSYNC & BACKUP</h2>
                <p class="muted">Setiap sumber menjalankan RSYNC terlebih dahulu, lalu langsung dikompresi dan diverifikasi.</p>
                ${schedule.enabled ? `<div class="schedule-controls">
                    <label class="field">Pola jadwal
                        <select name="mode" data-schedule-mode="${schedule.type}">
                            <option value="minutes" ${schedule.mode === "minutes" ? "selected" : ""}>Setiap Menit</option>
                            <option value="hours" ${schedule.mode === "hours" ? "selected" : ""}>Setiap Jam</option>
                            <option value="daily" ${schedule.mode === "daily" ? "selected" : ""}>Setiap Hari</option>
                        </select>
                    </label>
                    ${["minutes", "hours"].includes(schedule.mode) ? `
                        <label class="field">Jalankan setiap
                            <span class="interval-input">
                                <input type="number" min="1" max="${schedule.mode === "minutes" ? 1440 : 168}"
                                    name="interval_value" value="${schedule.interval_value}" required>
                                <b>${schedule.mode === "minutes" ? "menit" : "jam"}</b>
                            </span>
                        </label>` : `
                        <label class="field">Waktu mulai
                            <input class="time-input" type="time" name="time" value="${schedule.time}" step="60" required>
                        </label>`}
                    <button class="button primary schedule-save" type="submit">
                        <span>✓</span>Simpan
                    </button>
                </div>
                <div class="schedule-foot" style="display: flex; flex-direction: column; align-items: flex-start; gap: 4px;">
                    <div>Aktif · ${scheduleText(schedule)}</div>
                    ${schedule.next_run ? `<div style="font-weight: 600; font-size: 12px;">⏰ Next Run: ${escapeHtml(schedule.next_run)}</div>` : ""}
                </div>`
                : `<div class="schedule-foot">Jadwal nonaktif</div>`}
            </form>`).join("")}</div>`;
    }

    function renderStorage() {
        const isSystemMount = (path) => /^\/(?:usr\/lib\/wsl|proc|sys|dev|run)(?:\/|$)/.test(String(path));
        const disks = [...(state.disks?.disks || [])].sort((left, right) => {
            const systemOrder = Number(isSystemMount(left.path)) - Number(isSystemMount(right.path));
            return systemOrder || String(left.path).localeCompare(String(right.path));
        });
        const target = state.dashboard.disk;
        const rsyncPath = state.dashboard.settings.rsync_dir;
        const rsyncMount = diskForPath(rsyncPath, disks);
        const rsyncTarget = rsyncMount
            ? { ...rsyncMount, path: rsyncPath, available: true }
            : { path: rsyncPath, available: false, used: 0, total: 0, free: 0, used_percent: 0 };
        return `<div class="grid storage-layout">
            <article class="panel capacity"><div class="panel-heading"><div><p class="eyebrow">DISK TUJUAN</p>
                <h2>${target.available ? "Storage terhubung" : "Storage tidak tersedia"}</h2></div>
                <span class="status ${target.available ? "status-success" : "status-failed"}">${target.available ? "Online" : "Periksa"}</span></div>
                <p class="path">${escapeHtml(target.path)}</p>
                <div class="capacity-number"><strong>${bytes(target.used)}</strong><span>dari ${bytes(target.total)}</span></div>
                <div class="capacity-track"><i style="width:${Math.min(target.used_percent, 100)}%"></i></div>
                <div class="capacity-foot"><span>${target.used_percent}% terpakai</span><strong>${bytes(target.free)} tersedia</strong></div>
            </article>
            <article class="panel capacity"><div class="panel-heading"><div><p class="eyebrow">DISK TUJUAN RSYNC</p>
                <h2>${rsyncTarget.available ? "Storage terhubung" : "Storage tidak tersedia"}</h2></div>
                <span class="status ${rsyncTarget.available ? "status-success" : "status-failed"}">${rsyncTarget.available ? "Online" : "Periksa"}</span></div>
                <p class="path">${escapeHtml(rsyncTarget.path)}</p>
                <div class="capacity-number"><strong>${bytes(rsyncTarget.used)}</strong><span>dari ${bytes(rsyncTarget.total)}</span></div>
                <div class="capacity-track"><i style="width:${Math.min(rsyncTarget.used_percent, 100)}%"></i></div>
                <div class="capacity-foot"><span>${rsyncTarget.used_percent}% terpakai</span><strong>${bytes(rsyncTarget.free)} tersedia</strong></div>
            </article>
            <section class="storage-mounts">
                <div class="panel-heading"><div><p class="eyebrow">DISK HOST</p><h2>Penyimpanan tersedia</h2></div>
                    <button class="button ghost" data-action="disk-refresh"><span>↻</span>Refresh disk</button></div>
                <div class="grid disk-list">
                    ${disks.map((disk) => `<article class="panel capacity">
                <div class="panel-heading"><div><p class="eyebrow">MOUNT HOST</p><h2>${escapeHtml(disk.path)}</h2></div>
                    <span class="status status-success">Online</span></div>
                <div class="capacity-number"><strong>${bytes(disk.used)}</strong><span>dari ${bytes(disk.total)}</span></div>
                <div class="capacity-track"><i style="width:${Math.min(disk.used_percent, 100)}%"></i></div>
                <div class="capacity-foot"><span>${disk.used_percent}% terpakai</span><strong>${bytes(disk.free)} tersedia</strong></div>
                    </article>`).join("") || `<article class="panel"><div class="empty"><strong>Disk belum dimuat</strong>Tekan refresh untuk membaca mount yang tersedia.</div></article>`}
                </div>
            </section>
        </div>`;
    }

    function renderExplorer(kind) {
        const rootPath = kind === "backup"
            ? state.dashboard.settings.backup_dir
            : state.dashboard.settings.rsync_dir;
        const listing = state.storage;
        const query = state.storageQuery.toLowerCase();
        const entries = (listing?.entries || []).filter((entry) =>
            entry.name.toLowerCase().includes(query)
        );
        const pathParts = state.storagePath ? state.storagePath.split("/") : [];
        const breadcrumbs = [
            `<button data-storage-path="">${kind === "backup" ? "BACKUP" : "RSYNC"}</button>`,
            ...pathParts.map((part, index) => {
                const path = pathParts.slice(0, index + 1).join("/");
                return `<span>›</span><button data-storage-path="${escapeHtml(path)}">${escapeHtml(part)}</button>`;
            }),
        ].join("");
        return `<div class="grid storage-layout explorer-layout">
            <nav class="explorer-kind-switch" aria-label="Pilih lokasi File Explorer">
                <button class="${kind === "backup" ? "active" : ""}" data-explorer-kind="backup"
                    aria-pressed="${kind === "backup"}"><span>7Z</span>BACKUP</button>
                <button class="${kind === "rsync" ? "active" : ""}" data-explorer-kind="rsync"
                    aria-pressed="${kind === "rsync"}"><span>↕</span>RSYNC</button>
            </nav>
            <article class="panel storage-explorer">
                <div class="panel-heading">
                    <div><p class="eyebrow">FILE EXPLORER</p><h2>${kind === "backup" ? "Data BACKUP" : "Data RSYNC"}</h2>
                        <p class="muted">${kind === "backup" ? "Jelajahi, download, atau upload arsip pada folder tujuan." : "Jelajahi, download, atau upload data RSYNC terbaru."}</p></div>
                    <div class="toolbar storage-toolbar">
                        <button class="button ghost" data-action="storage-refresh"><span>↻</span>Refresh</button>
                        <label class="button primary upload-button"><span>⇧</span>${kind === "backup" ? "Upload .7z" : "Upload file"}
                            <input id="storage-upload" type="file"
                                ${kind === "backup" ? 'accept=".7z,application/x-7z-compressed"' : ""}>
                        </label>
                    </div>
                </div>
                <div class="storage-navigation">
                    <nav class="breadcrumbs" aria-label="Lokasi folder">${breadcrumbs}</nav>
                    <label class="search"><input id="storage-search" value="${escapeHtml(state.storageQuery)}"
                        placeholder="Cari file atau folder…" aria-label="Cari file backup"></label>
                </div>
                ${state.storageLoading ? `
                    <div class="storage-loading"><i></i><span>Membaca folder tujuan…</span></div>
                ` : state.storageError ? `
                    <div class="empty storage-error"><strong>Folder tidak dapat dibaca</strong>
                        ${escapeHtml(state.storageError)} Buka Pengaturan lalu jalankan Tes akses folder.</div>
                ` : !listing ? `
                    <div class="empty"><strong>File explorer belum dimuat</strong>Tekan refresh untuk membaca folder tujuan.</div>
                ` : entries.length ? `
                    <div class="file-list">
                        <div class="file-row file-head"><span>Nama</span><span>Ukuran</span><span>Diubah</span><span></span></div>
                        ${entries.map((entry) => `
                            <div class="file-row">
                                <div class="file-primary">
                                    <i class="file-icon ${entry.type}">${entry.type === "directory" ? "▰" : "7Z"}</i>
                                    ${entry.type === "directory"
                                        ? `<button class="file-name" data-storage-path="${escapeHtml(entry.path)}">${escapeHtml(entry.name)}</button>`
                                        : `<span class="file-name">${escapeHtml(entry.name)}</span>`}
                                </div>
                                <span>${entry.type === "directory" ? `${bytes(entry.size)} · Folder` : bytes(entry.size)}</span>
                                <span>${dateTime(entry.modified_at)}</span>
                                <span class="file-actions">
                                    <a class="row-button download-button"
                                        href="api.php?action=${kind}_download&path=${encodeURIComponent(entry.path)}"
                                        download="${escapeHtml(entry.type === "directory" ? `${entry.name}.zip` : entry.name)}"
                                        title="${entry.type === "directory" ? "Download folder sebagai ZIP" : "Download"} ${escapeHtml(entry.name)}"
                                        aria-label="Download ${escapeHtml(entry.name)}">
                                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M12 3v11m0 0 4-4m-4 4-4-4M5 18v2h14v-2"/>
                                            </svg>
                                        </a>
                                    ${entry.type === "directory" ? `
                                    <button class="row-button" data-storage-path="${escapeHtml(entry.path)}"
                                        aria-label="Buka ${escapeHtml(entry.name)}">${openIcon()}</button>` : ""}
                                    <button class="row-button storage-delete-button" data-action="storage-delete"
                                        data-storage-delete-path="${escapeHtml(entry.path)}"
                                        aria-label="Hapus ${escapeHtml(entry.name)}" title="Hapus ${escapeHtml(entry.name)}">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16m-10 4v6m4-6v6M9 7l1-3h4l1 3m-8 0 1 13h8l1-13"/></svg>
                                    </button>
                                </span>
                            </div>`).join("")}
                    </div>
                ` : `
                    <div class="empty"><strong>${query ? "Tidak ada hasil" : "Folder ini kosong"}</strong>
                        ${query ? "Coba kata pencarian lain." : kind === "backup" ? "Upload file .7z atau buka folder lain." : "Belum ada data RSYNC."}</div>
                `}
                <footer class="storage-foot">
                    <span>${entries.length} item ditampilkan</span>
                    <code>${escapeHtml(listing?.root || rootPath)}${state.storagePath ? `/${escapeHtml(state.storagePath)}` : ""}</code>
                </footer>
            </article>
        </div>`;
    }

    function storageDeleteDialog(entry) {
        const kindLabel = entry.type === "directory" ? "folder beserta seluruh isinya" : "file";
        showModal(`
            <p class="eyebrow danger-copy">HAPUS ${entry.type === "directory" ? "FOLDER" : "FILE"}</p>
            <h2>Hapus ${escapeHtml(entry.name)}?</h2>
            <p class="muted">${kindLabel.charAt(0).toUpperCase() + kindLabel.slice(1)} ini akan dihapus permanen dari ${state.explorerKind === "backup" ? "BACKUP" : "RSYNC"} dan tidak dapat dipulihkan.</p>
            <button class="button danger wide" data-action="storage-delete-confirm" data-storage-delete-path="${escapeHtml(entry.path)}">Hapus permanen</button>
        `);
    }

    function pathCheckMarkup(kind, result = null) {
        const label = kind === "rsync" ? "RSYNC" : "BACKUP";
        if (!result) {
            return `<div class="path-check" id="path-check-${kind}">
                <span class="path-check-icon">?</span>
                <span><strong>Akses folder belum diuji</strong>
                    <small>Tes dijalankan langsung oleh worker.</small></span>
            </div>`;
        }
        return `<div class="path-check ${result.ready ? "ready" : "failed"}" id="path-check-${kind}">
            <span class="path-check-icon">${result.ready ? "✓" : "!"}</span>
            <span><strong>${escapeHtml(result.message)}</strong>
                <small>${escapeHtml(label)} · worker ${escapeHtml(result.worker_user)}
                ${result.ready ? ` · tersedia ${bytes(result.free_bytes)}` : ""}</small></span>
        </div>`;
    }

    function storagePathCheckMarkup(rsync = null, backup = null) {
        const results = [
            ["RSYNC", rsync],
            ["BACKUP", backup],
        ];
        if (!rsync && !backup) {
            return `<div class="path-check" id="path-check-storage"><span class="path-check-icon">?</span><span><strong>Akses folder belum diuji</strong><small>Folder RSYNC dan BACKUP akan diuji langsung oleh worker.</small></span></div>`;
        }
        const ready = results.every(([, result]) => result?.ready === true);
        const failed = results.some(([, result]) => result && result.ready !== true);
        const status = ready ? "ready" : failed ? "failed" : "";
        const summary = results.map(([label, result]) => `${label}: ${result?.ready ? "siap" : result ? "perlu diperiksa" : "belum diuji"}`).join(" · ");
        return `<div class="path-check ${status}" id="path-check-storage"><span class="path-check-icon">${ready ? "✓" : "!"}</span><span><strong>${ready ? "Folder RSYNC dan BACKUP siap digunakan." : "Status akses folder perlu diperiksa."}</strong><small>${escapeHtml(summary)}</small></span></div>`;
    }

    function pathCheckDetailMarkup(result) {
        const labels = {
            exists: "Folder tersedia",
            directory: "Path adalah direktori",
            readable: "Dapat dibaca",
            writable: "Dapat ditulis",
            test_file: "File uji dapat dibuat dan dihapus",
            disk: "Kapasitas disk dapat dibaca",
        };
        const checks = Object.entries(labels).map(([key, label]) => `
            <li class="${result.checks?.[key] ? "passed" : "failed"}">
                <i>${result.checks?.[key] ? "✓" : "×"}</i>
                <span>${escapeHtml(label)}</span>
            </li>`).join("");
        const commands = (result.commands || []).length
            ? `<div class="path-admin-help">
                <p class="eyebrow">PERINTAH ADMINISTRATOR</p>
                <p class="muted">Jalankan hanya pada server produksi setelah memeriksa path.</p>
                <pre>${escapeHtml(result.commands.join("\n"))}</pre>
            </div>`
            : "";
        return `
            <p class="eyebrow">${result.ready ? "FOLDER SIAP" : "AKSES FOLDER GAGAL"}</p>
            <h2>${escapeHtml(result.message)}</h2>
            <p class="path">${escapeHtml(result.path)}</p>
            <p class="muted">Pengujian dijalankan sebagai <strong>${escapeHtml(result.worker_user)}</strong>.
                ${escapeHtml(result.detail || "")}</p>
            <ul class="path-check-list">${checks}</ul>
            ${result.ready ? `<div class="path-space">
                <span><small>Total</small><strong>${bytes(result.total_bytes)}</strong></span>
                <span><small>Tersedia</small><strong>${bytes(result.free_bytes)}</strong></span>
            </div>` : ""}
            ${commands}`;
    }

    function storagePathCheckDetailMarkup(label, result) {
        const passed = Object.values(result.checks || {}).filter(Boolean).length;
        const total = Object.keys(result.checks || {}).length;
        return `<article class="storage-check-card ${result.ready ? "ready" : "failed"}"><div><p class="eyebrow">${label}</p><strong>${escapeHtml(result.message)}</strong></div><code>${escapeHtml(result.path)}</code><small>${passed}/${total} pemeriksaan lulus · worker ${escapeHtml(result.worker_user)}${result.ready ? ` · tersedia ${bytes(result.free_bytes)}` : ""}</small></article>`;
    }

    function renderSettings() {
        const s = state.dashboard.settings;
        const sshConnected = s.ssh_connected === true;
        const minimumFree = byteUnitValue(s.minimum_free_bytes);
        return `<div class="grid settings-grid">
            <form class="panel form" data-form="settings-language"><div class="panel-heading"><div><p class="eyebrow">PREFERENSI</p><h2>Bahasa aplikasi</h2></div></div>
                <div class="form-grid"><label class="span-2">Bahasa aplikasi<select name="language"><option value="id" ${s.language !== "en" ? "selected" : ""}>Bahasa Indonesia</option><option value="en" ${s.language === "en" ? "selected" : ""}>Bahasa Inggris</option></select><small>Pilih bahasa untuk seluruh antarmuka aplikasi.</small></label>
                    <div class="path-panel-actions span-2"><button class="button primary" type="submit"><span>✓</span>Simpan</button></div>
                </div>
            </form>
            <form class="panel form" data-form="settings-ssh"><div class="panel-heading"><div><p class="eyebrow">KONEKSI SUMBER</p><h2>SSH & RSYNC</h2></div></div>
                <div class="form-grid">
                    <label>Host sumber<input name="remote_host" value="${escapeHtml(s.remote_host)}" placeholder="192.168.1.1"><small>IP address atau nama domain server sumber.</small></label>
                    <label>Port SSH<input name="remote_port" type="number" min="1" max="65535" value="${s.remote_port}"><small>Umumnya 22; ubah jika SSH memakai port lain.</small></label>
                    <label>User SSH<input name="remote_user" value="${escapeHtml(s.remote_user)}" placeholder="root">
                        <small>Bebas diisi sesuai user yang tersedia pada server sumber.</small></label>
                    <label>Password SSH<span class="password-control">
                        <input id="ssh-password" name="ssh_password" type="password" autocomplete="current-password"
                            placeholder="${s.ssh_password_saved ? "Password terenkripsi sudah tersimpan" : "Diperlukan saat setup pertama"}">
                        <button class="password-toggle" type="button" data-action="toggle-ssh-password"
                            aria-label="Tampilkan password SSH" aria-pressed="false" title="Tampilkan password">
                            <i class="eye-icon"></i>
                        </button>
                    </span>
                        <span class="secret-status ${s.ssh_password_saved ? "saved" : ""}">
                            <i>${s.ssh_password_saved ? "✓" : "!"}</i>
                            ${s.ssh_password_saved ? "Tersimpan terenkripsi di server." : "Belum ada password tersimpan."}
                            ${s.ssh_password_saved ? `<button type="button" data-action="delete-ssh-password">Hapus</button>` : ""}
                        </span></label>
                    <input type="hidden" name="ssh_key_type" value="rsa4096">
                    <input type="hidden" name="ssh_key_comment" value="J-Backup-Key-RSA">
                    <div class="ssh-tools span-2">
                        <button class="button ${sshConnected ? "danger ssh-disconnect" : "ssh-setup"}" type="button"
                            data-action="${sshConnected ? "ssh-disconnect" : "ssh-connect"}">
                            <span>${sshConnected ? "×" : "⇥"}</span>${sshConnected ? "Disconnect" : "Connect"}
                        </button>
                        <button class="button ssh-test" type="button" data-action="ssh-test"><span>✓</span>Tes Koneksi</button>
                        <small>${sshConnected
                            ? `Terhubung ke ${escapeHtml(s.ssh_connected_target || `${s.remote_user}@${s.remote_host}`)}. Disconnect mencabut key remote dan menghapus key lokal.`
                            : "Connect otomatis membuat kunci RSA 4096-bit (J-Backup-Key-RSA), memasangnya ke server target via ssh-copy-id, lalu menguji login tanpa password."}</small>
                    </div>
                </div>
            </form>
            <form class="panel form" data-form="settings-backup"><div class="panel-heading"><div><p class="eyebrow">HASIL BACKUP</p><h2>Lokasi & Penamaan</h2></div></div>
                <div class="form-grid">
                    <label class="span-2">Folder RSYNC<input name="rsync_dir" value="${escapeHtml(s.rsync_dir)}">
                        <small>Data RSYNC terbaru disimpan di sini dan menjadi sumber pembuatan backup.</small></label>
                    <label class="span-2">Folder BACKUP<input name="backup_dir" value="${escapeHtml(s.backup_dir)}"><small>Path absolut Linux untuk menyimpan arsip BACKUP yang sudah dibuat.</small></label>
                    <label class="span-2">Template nama file<input name="filename_template" value="${escapeHtml(s.filename_template)}">
                        <small>Placeholder: {name}, {time}, {day}, {date}, {month}, {month_short}, {month_num}, {year}, {year_short}.</small></label>
                    <label>Kompresi 7z<select name="compression_level">${[0,1,3,5,7,9].map((level) => `<option value="${level}" ${Number(s.compression_level) === level ? "selected" : ""}>Level ${level}</option>`).join("")}</select><small>Level lebih tinggi menghemat ruang, tetapi proses lebih lama.</small></label>
                    <label>Minimum ruang kosong<div class="size-input"><input name="minimum_free_value" type="number" min="0" step="0.01" value="${minimumFree.value}"><select name="minimum_free_unit">${["KB", "MB", "GB", "TB"].map((unit) => `<option value="${unit}" ${minimumFree.unit === unit ? "selected" : ""}>${unit}</option>`).join("")}</select></div><small>BACKUP ditolak jika ruang tersisa berada di bawah nilai ini.</small></label>
                    <label>Zona waktu<select name="timezone">${timezoneOptions(s.timezone)}</select><small>Dipakai untuk jadwal dan penamaan waktu backup.</small></label>
                    <div class="span-2">${storagePathCheckMarkup(state.pathChecks.rsync, state.pathChecks.backup)}</div>
                    <div class="path-panel-actions span-2">
                        <button class="button path-test" type="button" data-action="test-path" data-path-kind="backup"><span>✓</span>Tes akses folder</button>
                        <button class="button primary" type="submit"><span>✓</span>Simpan</button>
                    </div>
                </div>
            </form>
            <section class="panel danger-zone">
                <div>
                    <p class="eyebrow">ZONA BERBAHAYA</p>
                    <h2>Reset Database</h2>
                    <p>Menghapus akun administrator, konfigurasi, daftar sumber, jadwal, riwayat, antrean, password SSH, dan seluruh file key SSH lokal. File hasil backup tidak dihapus.</p>
                </div>
                <button class="button danger" type="button" data-action="reset-database">
                    <span>↺</span>Reset Database
                </button>
            </section>
        </div>`;
    }

    function renderNotifications() {
        const s = state.dashboard.settings;
        const enabled = String(s.telegram_enabled) === "1" || String(s.telegram_enabled) === "true";
        const backupFileDaily = s.telegram_backup_file_interval_unit === "day";
        const backupFileStartTime = s.telegram_backup_file_start_time || "00:00";
        const defaultOrder = ["tipe", "waktu", "cpu", "memory", "job", "disk", "anydesk", "sumber", "info"];
        const fieldLabels = {
            waktu: "Waktu",
            cpu: "CPU",
            memory: "Memory",
            job: "Job",
            tipe: "Tipe",
            disk: "Disk",
            anydesk: "Anydesk",
            sumber: "Sumber",
            info: "Info",
        };
        const rawOrder = String(s.telegram_fields_order || "tipe,waktu,cpu,memory,job,disk,anydesk,sumber,info").split(",");
        const currentOrder = rawOrder.filter((k) => defaultOrder.includes(k));
        for (const k of defaultOrder) {
            if (!currentOrder.includes(k)) currentOrder.push(k);
        }
        const standbyTemplate = `J-BACKUP v.2.7.0
=================================
Tipe          : Standby
Waktu     : {{waktu}}
CPU          : {{cpu}}
Memory : {{ram}}
Job            : {{job_sukses}} Berhasil {{job_gagal}} Gagal
Disk          : {{disk}}
Anydesk : {{anydesk_id}}
Health      : {{kesehatan_system}}
=================================`;
        const jobTemplate = `J-BACKUP v.2.7.0
=================================
Tipe          : {{tipe}}
Waktu     : {{waktu}}
Sumber  : {{sumber}}
CPU          : {{cpu}}
Memory : {{ram}}
Job            : {{job_sukses}} Berhasil {{job_gagal}} Gagal
Disk          : {{disk}}
Anydesk : {{anydesk_id}}
Info          : {{info}}
=================================`;
        const templateValue = (key, fallback) => {
            const value = String(s[key] || "");
            return value === "" || value === "{{pesan_default}}" ? fallback : value;
        };
        const templateHint = "Placeholder: {{tipe}}, {{waktu}}, {{cpu}}, {{ram}}, {{job_sukses}}, {{job_gagal}}, {{disk}}, {{anydesk_id}}, {{kesehatan_system}}, {{sumber}}, {{info}}.";

        return `<div class="grid settings-grid notification-layout">
            <form class="panel form notification-connection" data-form="settings-telegram">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">KONEKSI TELEGRAM</p>
                        <h2>Pengaturan Koneksi</h2>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="telegram_enabled" data-action="toggle-telegram" ${enabled ? "checked" : ""}>
                        <span></span>
                    </label>
                </div>
                <div class="form-grid telegram-fields-container ${enabled ? "" : "is-hidden"}">
                    <label><span>Bot Token</span>
                        <input name="telegram_bot_token" type="text" autocomplete="off" value="${escapeHtml(s.telegram_bot_token || "")}" placeholder=""><small>Token dari @BotFather; disimpan terenkripsi setelah menekan Simpan.</small>
                    </label>
                    <label><span>Chat ID</span>
                        <div class="chat-id-input"><input name="telegram_chat_id" type="text" value="${escapeHtml(s.telegram_chat_id || "")}" placeholder="">
                            <button class="button ssh-test chat-id-lookup" type="button" data-action="lookup-telegram-chat-id">Ambil otomatis</button></div>
                        <small>Kirim pesan apa pun ke bot Anda, lalu tekan Ambil otomatis.</small>
                    </label>
                    <label class="span-2"><span>Anydesk ID</span>
                        <input name="anydesk_id" type="text" autocomplete="off" value="${escapeHtml(s.anydesk_id || "")}" placeholder="Contoh: 1234567890"><small>Opsional; ditampilkan pada pesan Telegram.</small>
                    </label>
                    <div class="path-panel-actions span-2" style="margin-top: 16px;">
                        <button class="button primary" type="submit">Simpan</button>
                        <button class="button ssh-test" type="button" data-action="test-telegram">Testing</button>
                    </div>
                </div>
            </form>
            <form class="panel form notification-standby" data-form="settings-telegram-standby">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">NOTIFIKASI TELEGRAM</p>
                        <h2>Mode Edit</h2>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="span-2 message-standby-control">
                        <div class="switch-row telegram-template-toggle message-editor-toggle"><div><strong>Pesan Standby</strong><small>Pesan status otomatis yang dikirim saat tidak ada pekerjaan aktif.</small></div><label class="switch"><input type="checkbox" name="telegram_standby_enabled" ${String(s.telegram_standby_enabled ?? "1") !== "0" ? "checked" : ""}><span></span></label></div>
                        <label class="message-interval"><span>Interval</span><div class="interval-control"><input name="telegram_standby_interval" type="number" min="1" max="9999" value="${escapeHtml(s.telegram_standby_interval || "1")}" placeholder="1"><select name="telegram_standby_interval_unit"><option value="minute" ${s.telegram_standby_interval_unit !== "hour" && s.telegram_standby_interval_unit !== "day" ? "selected" : ""}>Menit</option><option value="hour" ${s.telegram_standby_interval_unit === "hour" ? "selected" : ""}>Jam</option><option value="day" ${s.telegram_standby_interval_unit === "day" ? "selected" : ""}>Hari</option></select></div></label>
                        <label class="message-editor-card">
                        <span class="message-editor-label">Editor Pesan Standby</span>
                        <textarea name="telegram_standby_template" rows="11">${escapeHtml(templateValue("telegram_standby_template", standbyTemplate))}</textarea>
                        <small>${templateHint}</small>
                        </label>
                    </div>
                    <div class="span-2 message-job-control">
                    <div class="switch-row telegram-template-toggle message-editor-toggle">
                        <div><strong>Pesan RSYNC</strong><small>Pesan dikirim saat proses RSYNC dimulai, selesai, atau gagal.</small></div>
                        <label class="switch"><input type="checkbox" name="telegram_rsync_enabled" ${String(s.telegram_rsync_enabled ?? "1") !== "0" ? "checked" : ""}><span></span></label>
                    </div>
                    <label class="span-2 message-editor-card ${String(s.telegram_rsync_enabled ?? "1") !== "0" ? "" : "is-hidden"}"><span>Editor Pesan RSYNC</span>
                        <textarea name="telegram_rsync_template" rows="11">${escapeHtml(templateValue("telegram_rsync_template", jobTemplate))}</textarea>
                        <small>${templateHint}</small>
                    </label>
                    </div>
                    <div class="span-2 message-job-control">
                    <div class="switch-row telegram-template-toggle message-editor-toggle">
                        <div><strong>Pesan BACKUP</strong><small>Pesan dikirim saat proses BACKUP dimulai, selesai, atau gagal.</small></div>
                        <label class="switch"><input type="checkbox" name="telegram_backup_enabled" ${String(s.telegram_backup_enabled ?? "1") !== "0" ? "checked" : ""}><span></span></label>
                    </div>
                    <label class="span-2 message-editor-card ${String(s.telegram_backup_enabled ?? "1") !== "0" ? "" : "is-hidden"}"><span>Editor Pesan BACKUP</span>
                        <textarea name="telegram_backup_template" rows="11">${escapeHtml(templateValue("telegram_backup_template", jobTemplate))}</textarea>
                        <small>${templateHint}</small>
                    </label>
                    </div>
                    <div class="span-2" style="display: none;">
                    <input type="hidden" name="telegram_fields_order" value="${currentOrder.join(",")}">
                    <div class="span-2 telegram-order-list" style="display: flex; flex-direction: column; gap: 8px;">
                        ${currentOrder.map((key, index) => {
                            const isChecked = String(s[`telegram_field_${key}`] ?? "1") !== "0";
                            return `<div class="switch-row order-row" data-field-key="${key}" style="display: flex; align-items: center; justify-content: space-between;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="display: flex; gap: 2px;">
                                        <button type="button" class="btn-order" data-action="move-telegram-field-up" data-key="${key}" ${index === 0 ? "disabled" : ""} style="padding: 2px 6px; font-size: 11px; cursor: pointer;">▲</button>
                                        <button type="button" class="btn-order" data-action="move-telegram-field-down" data-key="${key}" ${index === currentOrder.length - 1 ? "disabled" : ""} style="padding: 2px 6px; font-size: 11px; cursor: pointer;">▼</button>
                                    </div>
                                    <span>${fieldLabels[key] || key}</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="telegram_field_${key}" ${isChecked ? "checked" : ""}>
                                    <span></span>
                                </label>
                            </div>`;
                        }).join("")}
                    </div>
                    </div>
                    <div class="path-panel-actions span-2" style="margin-top: 16px;">
                        <button class="button primary" type="submit"><span>✓</span>Simpan</button>
                        <button class="button ssh-test" type="button" data-action="test-telegram"><span>✓</span>Testing</button>
                    </div>
                </div>
            </form>
            <form class="panel form notification-backup-file" data-form="settings-telegram-backup-file">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">NOTIFIKASI TELEGRAM</p>
                        <h2>Mode Kirim &amp; Hapus</h2>
                    </div>
                </div>
                <div class="message-editor-card">
                <div class="switch-row message-editor-toggle">
                    <div><strong>Kirim File Daftar BACKUP</strong><small>Mengirim Daftar-File-BACKUP.txt ke Telegram dan mengganti pesan dokumen sebelumnya.</small></div>
                    <label class="switch"><input type="checkbox" name="telegram_backup_file_enabled" ${String(s.telegram_backup_file_enabled ?? "0") === "1" ? "checked" : ""}><span></span></label>
                </div>
                <label><span data-backup-file-schedule-label>${backupFileDaily ? "Waktu mulai pengiriman" : "Interval Pengiriman"}</span>
                    <div class="interval-control">
                        <input data-backup-file-interval name="telegram_backup_file_interval" type="number" min="1" max="9999" value="${escapeHtml(s.telegram_backup_file_interval || "60")}" placeholder="60" ${backupFileDaily ? "hidden disabled" : ""}>
                        <input data-backup-file-start-time name="telegram_backup_file_start_time" type="time" value="${escapeHtml(backupFileStartTime)}" ${backupFileDaily ? "" : "hidden disabled"}>
                        <select name="telegram_backup_file_interval_unit">
                            <option value="minute" ${s.telegram_backup_file_interval_unit !== "hour" && s.telegram_backup_file_interval_unit !== "day" ? "selected" : ""}>Menit</option>
                            <option value="hour" ${s.telegram_backup_file_interval_unit === "hour" ? "selected" : ""}>Jam</option>
                            <option value="day" ${s.telegram_backup_file_interval_unit === "day" ? "selected" : ""}>Hari</option>
                        </select>
                    </div>
                    <small data-backup-file-schedule-help>${backupFileDaily ? "File akan dikirim satu kali setiap hari pada waktu ini." : "Masukkan jumlah menit atau jam antar-pengiriman."}</small>
                    <div class="backup-file-next-run"><span>JALAN BERIKUTNYA</span><strong data-backup-file-next-run>${escapeHtml(state.dashboard.telegram_backup_file_next_run || backupFileNextRun(s.telegram_backup_file_interval_unit || "minute", s.telegram_backup_file_interval || "60", backupFileStartTime))}</strong></div>
                </label>
                </div>
                <div class="path-panel-actions" style="margin-top: 16px;">
                    <button class="button primary" type="submit"><span>✓</span>Simpan</button>
                </div>
            </form>
        </div>`;
    }

    function renderAbout() {
        return `<div class="grid about-grid">
            <article class="panel about-hero">
                <div class="brand"><span class="brand-mark">J</span><span class="brand-copy"><strong>J-BACKUP</strong><small>Server data safety</small></span></div>
                <p class="eyebrow">TENTANG APLIKASI</p>
                <h2>Backup universal berbasis PHP</h2>
                <p class="muted">RSYNC sumber melalui SSH, kompresi 7z terjadwal, verifikasi hasil, dan file explorer terintegrasi.</p>
                <span class="tag">Versi ${escapeHtml(state.dashboard.version)}</span>
            </article>
            <article class="panel form update-panel">
                <div class="panel-heading"><div><p class="eyebrow">PEMBARUAN</p><h2>GitHub Releases</h2></div></div>
                <div class="update-repository"><small>Sumber resmi</small><strong>Jeriyant/J-Backup</strong></div>
                <div class="update-version-grid"><span><small>Versi terpasang</small><strong>${escapeHtml(state.dashboard.version)}</strong></span><span><small>Versi GitHub</small><strong>${escapeHtml(state.update.release?.latest_version || (state.update.checking ? "Memeriksa..." : state.update.error ? "Gagal diperiksa" : "Belum diperiksa"))}</strong></span></div>
                <p class="muted">Aplikasi memeriksa rilis resmi GitHub secara otomatis. Data runtime tetap aman saat update dipasang.</p>
                <div class="about-actions">
                    <button class="button ghost" type="button" data-action="check-update"><span>↻</span>Cek pembaruan</button>
                    ${state.update.release?.update_available && state.update.release?.installable ? `<button class="button primary" type="button" data-action="install-update"><span>↓</span>Update sekarang</button>` : ""}
                </div>
            </article>
            <article class="panel developer-card">
                <p class="eyebrow">TENTANG PENGEMBANG</p>
                <div class="developer-heading">
                    <span class="brand-mark">J</span>
                    <div><h2>JERIYANT - BARAMCITY</h2>
                        <p>Seorang Penikmat Teknologi Kelas Berat</p></div>
                </div>
                <p class="developer-location">Laboratorium Uji Teknis Berbasis di Suatu Daerah Terpencil Kalimantan Barat</p>
            </article>
        </div>`;
    }

    function accountDialog() {
        const timeout = Number(
            state.dashboard?.settings?.session_timeout_minutes ?? 30
        );
        showModal(`
            <p class="eyebrow">PENGATURAN AKUN</p>
            <h2>Kelola administrator</h2>
            <p class="muted">Ubah identitas login dan tentukan kapan sesi berakhir jika tidak ada aktivitas.</p>
            <form class="form" data-form="account-settings">
                <label>Username
                    <input name="username" value="${escapeHtml(state.dashboard.user.username)}"
                        maxlength="64" autocomplete="username" required>
                </label>
                <label>Password saat ini
                    <input name="current_password" type="password" minlength="1"
                        autocomplete="current-password" required>
                    <small>Diperlukan untuk menyimpan perubahan akun.</small>
                </label>
                <label>Password baru (opsional)
                    <input name="new_password" type="password" minlength="1"
                        autocomplete="new-password" placeholder="Biarkan kosong jika tidak diubah">
                </label>
                <label>Logout otomatis
                    <select name="session_timeout_minutes">
                        ${[
                            [0, "Tidak pernah"],
                            [5, "5 menit"],
                            [15, "15 menit"],
                            [30, "30 menit"],
                            [60, "1 jam"],
                            [120, "2 jam"],
                            [240, "4 jam"],
                            [480, "8 jam"],
                        ].map(([value, label]) => `
                            <option value="${value}" ${timeout === value ? "selected" : ""}>${label}</option>
                        `).join("")}
                    </select>
                    <small>Dihitung sejak aktivitas terakhir pada halaman ini.</small>
                </label>
                <button class="button primary wide" type="submit">
                    <span>✓</span>Simpan pengaturan akun
                </button>
            </form>
        `);
    }

    function sourceDialog(source = null) {
        const paths = source?.paths?.map((item) =>
            item.alias === item.path.split("/").filter(Boolean).at(-1)
                ? item.path
                : `${item.alias}=${item.path}`
        ).join("\n") || "";
        showModal(`<p class="eyebrow">${source ? "EDIT SUMBER" : "SUMBER BARU"}</p><h2>${source ? "Perbarui sumber backup" : "Tambahkan sumber backup"}</h2>
            <p class="muted">Masukkan satu path file atau folder remote per baris. Alias opsional dapat ditulis sebagai <code>alias=/path/file-atau-folder</code>.</p>
            <form class="form" data-form="${source ? "source-edit" : "source-add"}">
                ${source ? `<input type="hidden" name="id" value="${source.id}">` : ""}
                <label>Nama sumber<input name="name" value="${escapeHtml(source?.name || "")}" maxlength="128" required><small>Nama harus unik agar mudah dibedakan pada daftar dan hasil backup.</small></label>
                <label>Mode arsip<select name="archive_mode">
                    <option value="combined" ${source?.archive_mode === "separate" ? "" : "selected"}>Gabungkan menjadi satu file 7z</option>
                    <option value="separate" ${source?.archive_mode === "separate" ? "selected" : ""}>Satu file 7z untuk setiap path</option>
                </select></label>
                <label><span>Subfolder hasil (opsional)</span><input name="output_subdirectory" value="${escapeHtml(source?.output_subdirectory || "")}" placeholder=""><small>Kosongkan untuk memakai nama sumber sebagai folder hasil.</small></label>
                <label>Path sumber<textarea name="paths" rows="8" placeholder="/var/lib/mysql/JERIYANT/&#10;/var/lib/mysql/JERIYANT_sys/&#10;/var/lib/mysql/JERIYANT_sakep/file.txt" required>${escapeHtml(paths)}</textarea>
                    <small>Akhiri path folder dengan <code>/</code> agar isi folder diproses RSYNC langsung ke alias. Path file tidak memakai <code>/</code> di akhir.</small></label>
                <button class="button primary wide action-add-dialog"><span>${source ? "✓" : "＋"}</span>${source ? "Simpan perubahan" : "Tambahkan sumber"}</button>
            </form>`);
    }

    function sourceImportDialog() {
        showModal(`
            <p class="eyebrow">IMPORT SUMBER</p>
            <h2>Import dari Excel</h2>
            <p class="muted">Gunakan file <code>.xlsx</code> atau <code>.csv</code>. Baris pertama harus berisi nama kolom berikut.</p>
            <div class="import-guide">
                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th>nama_sumber *</th>
                            <th>mode_arsip</th>
                            <th>subfolder_hasil</th>
                            <th>path_sumber *</th>
                            <th>aktif</th>
                        </tr></thead>
                        <tbody><tr>
                            <td>JERIYANT</td>
                            <td>gabung</td>
                            <td>jeriyant</td>
                            <td><code>/var/lib/mysql/JERIYANT<br>/var/lib/mysql/JERIYANT_sys</code></td>
                            <td>ya</td>
                        </tr></tbody>
                    </table>
                </div>
                <ul>
                    <li><strong>nama_sumber</strong> dan <strong>path_sumber</strong> wajib diisi.</li>
                    <li>Jika <strong>nama_sumber</strong> sama dengan data yang sudah ada, data tersebut diperbarui tanpa mengganti ID internal.</li>
                    <li><strong>mode_arsip:</strong> isi <code>gabung</code> atau <code>terpisah</code>.</li>
                    <li>Untuk beberapa path, buat baris baru di dalam sel Excel dengan <code>Alt+Enter</code>. Tanda <code>|</code> juga didukung.</li>
                    <li>Alias path dapat ditulis sebagai <code>alias=/path/file-atau-folder</code>.</li>
                    <li><strong>aktif:</strong> isi <code>ya</code> atau <code>tidak</code>. Jika kosong, sumber akan aktif.</li>
                </ul>
            </div>
            <form class="form" data-form="source-import">
                <label>File Excel atau CSV
                    <input name="file" type="file" accept=".xlsx,.csv"
                        aria-label="File Excel atau CSV" required>
                    <small>Maksimal 10 MB dan 1.000 sumber dalam satu file.</small>
                </label>
                <button class="button primary wide" type="submit">
                    <span>⇧</span>Import sumber
                </button>
            </form>
        `, true);
    }

    function exportSources() {
        const link = document.createElement("a");
        link.href = "api.php?action=source_export";
        link.download = "J-Backup-Sumber.xlsx";
        link.click();
    }

    function manualDialog() {
        showModal(`<p class="eyebrow">EKSEKUSI MANUAL</p><h2>Jalankan pekerjaan sekarang</h2>
            <p class="muted">${state.selected.size ? `${state.selected.size} sumber terpilih akan diproses.` : "Semua sumber aktif akan diproses berurutan."}</p>
            <div class="choices"><button class="choice" data-run="backup"><i>↕</i><span><strong>RSYNC & BACKUP</strong><small>Tarik data sumber, kompres, lalu verifikasi arsip.</small></span></button></div>`);
    }

    function resetDatabaseDialog() {
        showModal(`
            <p class="eyebrow danger-copy">RESET DATABASE</p>
            <h2>Kembalikan aplikasi ke setup awal?</h2>
            <p class="muted">Semua data aplikasi dan file SSH lokal akan dihapus permanen. Public key pada server sumber tidak dicabut oleh proses ini. Arsip backup tetap dipertahankan.</p>
            <div class="reset-warning">
                <strong>Public key server dipertahankan</strong>
                <span>Gunakan Disconnect sebelum reset apabila public key juga ingin dicabut dari server sumber.</span>
            </div>
            <form class="form" data-form="database-reset">
                <label>Ketik <code>RESET</code> untuk konfirmasi
                    <input id="reset-confirmation" name="confirmation" autocomplete="off"
                        spellcheck="false" placeholder="RESET" required pattern="RESET">
                </label>
                <button id="reset-confirm-button" class="button danger wide" disabled>
                    <span>↺</span>Hapus semua data aplikasi
                </button>
            </form>
        `);
    }

    const jobIsActive = (job) =>
        ["queued", "running", "cancel_requested"].includes(job?.status);

    function jobPhaseText(job) {
        if (job.status === "queued") return "Menunggu giliran worker";
        if (job.status === "cancel_requested") return "Menghentikan pekerjaan dengan aman";
        const progress = Number(job.progress) || 0;
        if (progress < 20) return "Menyalin data sumber melalui RSYNC";
        if (progress < 95) return "Membuat arsip BACKUP";
        return "Memverifikasi arsip hasil";
    }

    function jobElapsedText(job) {
        const started = Date.parse(job.started_at || job.queued_at || "");
        if (!Number.isFinite(started)) return "belum dimulai";
        const seconds = Math.max(0, Math.floor((Date.now() - started) / 1000));
        if (seconds < 60) return `${seconds} dtk`;
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `${minutes} mnt ${seconds % 60} dtk`;
        return `${Math.floor(minutes / 60)} jam ${minutes % 60} mnt`;
    }

    function stopActiveJobPolling() {
        if (state.activeJobPoller) clearTimeout(state.activeJobPoller);
        state.activeJobPoller = null;
    }

    function startActiveJobPolling(job) {
        stopActiveJobPolling();
        if (!jobIsActive(job) || !modal.open) return;
        state.activeJobPoller = setTimeout(pollActiveJobDialog, 900);
    }

    async function pollActiveJobDialog() {
        state.activeJobPoller = null;
        const id = state.activeJobId;
        if (!id || !modal.open) return;
        try {
            const response = await api("job_status", {
                query: { id },
                background: true,
            });
            if (state.activeJobId !== id || !modal.open) return;
            const index = state.dashboard.jobs.findIndex((item) => item.id === id);
            if (index >= 0) state.dashboard.jobs[index] = response.job;
            else state.dashboard.jobs.unshift(response.job);
            const log = modal.querySelector(".log");
            const followLog = Boolean(
                log && log.scrollHeight - log.scrollTop - log.clientHeight < 36
            );
            refreshOpenJobDialog();
            const refreshedLog = modal.querySelector(".log");
            if (followLog && refreshedLog) refreshedLog.scrollTop = refreshedLog.scrollHeight;
            if (state.activeJobId === id && jobIsActive(response.job) && modal.open) {
                state.activeJobPoller = setTimeout(pollActiveJobDialog, 1200);
            }
        } catch (error) {
            const liveCopy = modal.querySelector("[data-job-live-copy]");
            if (liveCopy) liveCopy.textContent = "Pembaruan tertunda; mencoba kembaliâ€¦";
            if (state.activeJobId === id && modal.open) {
                state.activeJobPoller = setTimeout(pollActiveJobDialog, 2500);
            }
        }
    }

    function jobDialog(id) {
        const job = state.dashboard.jobs.find((item) => item.id === id);
        if (!job) return;
        const progress = Math.max(0, Math.min(100, Number(job.progress) || 0));
        const active = jobIsActive(job);
        showModal(`<p class="eyebrow">DETAIL PEKERJAAN</p><h2>${escapeHtml(job.source_name)}</h2>
            <div class="job-meta"><span class="status status-${escapeHtml(job.status)}">${statusText(job.status)}</span>
                <span>RSYNC & BACKUP</span><span>${dateTime(job.queued_at)}</span></div>
            ${active ? `<div class="job-live-status"><i></i><span><strong data-job-live-phase>${escapeHtml(jobPhaseText(job))}</strong><small data-job-live-copy>Diperbarui otomatis &middot; berjalan ${escapeHtml(jobElapsedText(job))}</small></span></div>` : ""}
            ${job.output_path ? `<p class="path">${escapeHtml(job.output_path)}</p>` : ""}
            ${job.outputs?.length ? `<div class="job-outputs">${job.outputs.map((output) => `
                <div class="detail-line"><span>${escapeHtml(output.source_alias || "Arsip gabungan")}</span>
                    <code>${escapeHtml(output.archive_path)}</code></div>`).join("")}</div>` : ""}
            ${job.error ? `<p class="error-box">${escapeHtml(job.error)}</p>` : ""}
            ${active ? `<div class="job-progress" role="progressbar" aria-label="Progres pekerjaan" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${progress}">
                <div class="job-progress-heading"><span>Progres</span><strong data-job-progress-text>${progress}%</strong></div>
                <div class="job-progress-track"><i data-job-progress-bar class="${job.status === "running" ? "is-running" : ""}" style="width:${progress}%"></i></div>
            </div>
            <div data-job-stages>${jobStageMarkup(job)}</div>` : ""}
            <div data-job-transfer>${jobTransferMarkup(job)}</div>
            ${job.status !== "success" ? `<div class="detail-line"><span>Verifikasi tujuan</span><strong>${verificationText(job.verification)}</strong></div>
            ${job.checksum ? `<div class="detail-line"><span>SHA-256</span><code>${escapeHtml(job.checksum)}</code></div>` : ""}` : ""}
            <details class="job-log"><summary>Lihat log teknis</summary><pre class="log">${escapeHtml(job.log || "Log pekerjaan belum tersedia.")}</pre></details>
            ${["queued","running"].includes(job.status) ? `<button class="button danger wide" data-cancel-job="${escapeHtml(job.id)}"><span>${stopIcon()}</span>Batalkan pekerjaan</button>` : ""}`, true);
        state.activeJobId = id;
        state.activeJobStatus = job.status;
        startActiveJobPolling(job);
    }

    function jobStageMarkup(job) {
        const progress = Number(job.progress) || 0;
        const done = job.status === "success";
        const rsyncState = progress >= 20 || done ? "done" : job.status === "running" ? "active" : "";
        const backupState = done ? "done" : progress >= 20 && job.status === "running" ? "active" : "";
        const verifyState = done ? "done" : "";
        return `<div class="job-stages"><div class="job-stage ${rsyncState}"><i>1</i><span><strong>RSYNC</strong><small>${rsyncState === "done" ? "Selesai" : rsyncState === "active" ? "Sedang menyalin data" : "Menunggu"}</small></span></div><div class="job-stage ${backupState}"><i>2</i><span><strong>BACKUP</strong><small>${backupState === "done" ? "Selesai" : backupState === "active" ? "Sedang mengompresi arsip" : "Menunggu RSYNC"}</small></span></div><div class="job-stage ${verifyState}"><i>3</i><span><strong>Verifikasi arsip</strong><small>${verifyState === "done" ? "Berhasil diverifikasi" : "Menunggu BACKUP"}</small></span></div></div>`;
    }

    function jobTransferMarkup(job) {
        const status = job.status;
        const progress = Math.max(0, Math.min(100, Number(job.progress) || 0));
        if (status !== "running") return "";
        if (progress >= 95) {
            return `<div class="job-transfer"><span><small>Tahap</small><strong>VERIFIKASI</strong></span><span><small>Progres total</small><strong>${progress}%</strong></span><span><small>Pemeriksaan</small><strong>Arsip 7z</strong></span><span><small>Waktu berjalan</small><strong>${escapeHtml(jobElapsedText(job))}</strong></span></div>`;
        }
        if (progress >= 20) {
            const backupProgress = Math.max(0, Math.min(100, Math.round(((progress - 20) / 75) * 100)));
            return `<div class="job-transfer"><span><small>BACKUP</small><strong>${backupProgress}%</strong></span><span><small>Progres total</small><strong>${progress}%</strong></span><span><small>Proses</small><strong>Kompresi 7z</strong></span><span><small>Waktu berjalan</small><strong>${escapeHtml(jobElapsedText(job))}</strong></span></div>`;
        }
        const log = job.log;
        const lines = String(log || "").split(/\r?\n/).reverse();
        const line = lines.find((item) => /\d+%.*(?:B\/s|bytes\/sec)/i.test(item));
        const match = line?.match(/([\d.]+\s*[KMGT]?(?:i?B)?)\s+(\d+)%\s+([\d.]+\s*[KMGT]?i?B\/s)\s+(\d+:\d+:\d+)/i);
        if (!match) {
            return status === "running" ? `<div class="job-transfer waiting"><i></i><span>Menunggu statistik transfer RSYNC…</span></div>` : "";
        }
        const [, copied, percent, speed, eta] = match;
        const check = line.match(/to-chk=(\d+)\/(\d+)/i);
        const files = check ? `${Math.max(0, Number(check[2]) - Number(check[1]))} / ${check[2]} berkas` : escapeHtml(copied);
        return `<div class="job-transfer"><span><small>RSYNC</small><strong>${percent}%</strong></span><span><small>Berkas diproses</small><strong>${files}</strong></span><span><small>Kecepatan</small><strong>${escapeHtml(speed)}</strong></span><span><small>Estimasi selesai</small><strong>${escapeHtml(eta)}</strong></span></div>`;
    }

    function refreshOpenJobDialog() {
        if (!modal.open || !state.activeJobId) return;
        const job = state.dashboard.jobs.find((item) => item.id === state.activeJobId);
        if (!job) return;
        if (job.status !== state.activeJobStatus) {
            jobDialog(job.id);
            return;
        }
        const progress = Math.max(0, Math.min(100, Number(job.progress) || 0));
        const text = modal.querySelector("[data-job-progress-text]");
        const bar = modal.querySelector("[data-job-progress-bar]");
        const progressBox = modal.querySelector("[role=progressbar]");
        if (text) text.textContent = `${progress}%`;
        if (bar) bar.style.width = `${progress}%`;
        if (bar) bar.classList.toggle("is-running", job.status === "running");
        if (progressBox) progressBox.setAttribute("aria-valuenow", String(progress));
        const stages = modal.querySelector("[data-job-stages]");
        if (stages) stages.innerHTML = jobStageMarkup(job);
        const transfer = modal.querySelector("[data-job-transfer]");
        if (transfer) transfer.innerHTML = jobTransferMarkup(job);
        const livePhase = modal.querySelector("[data-job-live-phase]");
        if (livePhase) livePhase.textContent = jobPhaseText(job);
        const liveCopy = modal.querySelector("[data-job-live-copy]");
        if (liveCopy) liveCopy.textContent = `Diperbarui otomatis \u00b7 berjalan ${jobElapsedText(job)}`;
        const log = modal.querySelector(".log");
        if (log) log.textContent = job.log || "Log pekerjaan belum tersedia.";
    }

    function cancelAllJobsDialog() {
        const queued = state.dashboard.jobs.filter(
            (job) => job.status === "queued"
        ).length;
        const running = state.dashboard.jobs.filter(
            (job) => job.status === "running"
        ).length;
        showModal(`
            <p class="eyebrow">PEMBATALAN MASSAL</p>
            <h2>Batalkan semua pekerjaan?</h2>
            <p class="muted">Semua pekerjaan dalam antrean akan langsung dibatalkan.
                Pekerjaan yang sedang berjalan akan dihentikan dengan aman oleh worker.</p>
            <div class="reset-warning">
                <strong>${queued} antrean · ${running} sedang berjalan</strong>
                <span>Riwayat pekerjaan yang sudah selesai tidak akan dihapus.</span>
            </div>
            <button class="button danger wide" data-action="confirm-cancel-all-jobs">
                <span>${stopIcon()}</span>Ya, batalkan semua pekerjaan
            </button>
        `);
    }

    function clearJobHistoryDialog() {
        showModal(`
            <p class="eyebrow">HAPUS RIWAYAT</p>
            <h2>Pilih riwayat yang akan dihapus</h2>
            <p class="muted">Pekerjaan yang sudah selesai, gagal, atau dibatalkan akan
                dihapus permanen. Antrean dan pekerjaan yang masih berjalan tetap dipertahankan.</p>
            <label class="field">Jenis riwayat<select id="history-clear-status"><option value="all">Semua riwayat selesai</option><option value="success">Hanya sukses</option><option value="failed">Hanya gagal</option><option value="cancelled">Hanya dibatalkan</option></select></label>
            <button class="button danger wide" data-action="confirm-clear-job-history">
                <span>×</span>Ya, hapus riwayat
            </button>
        `);
    }

    async function startJobs(type) {
        const result = await api("jobs_create", {
            method: "POST",
            body: { type, source_ids: [...state.selected] },
        });
        closeModal();
        const createdIds = new Set(result.jobs.map((job) => job.id));
        state.dashboard.jobs = [
            ...result.jobs,
            ...state.dashboard.jobs.filter((job) => !createdIds.has(job.id)),
        ];
        renderApp();
        toast(`${result.jobs.length} pekerjaan masuk antrean.`);
        await loadDashboard();
    }

    async function updateSchedule(type, patch) {
        const current = state.dashboard.schedules.find((item) => item.type === type);
        await api("schedule_update", { method: "POST", body: { ...current, ...patch, type } });
        await loadDashboard();
        toast("Jadwal diperbarui.");
    }

    async function waitForPathTask(id) {
        for (let attempt = 0; attempt < 75; attempt += 1) {
            await new Promise((resolve) => setTimeout(resolve, 1000));
            const response = await api("path_task_status", { query: { id } });
            if (response.task.status === "success") return response.task;
            if (response.task.status === "failed") {
                throw new Error(
                    response.task.error || "Pengujian folder gagal dijalankan."
                );
            }
            if (
                response.task.status === "queued"
                && attempt >= 20
            ) {
                throw new Error(!workerIsReady(response.worker_heartbeat)
                    ? "Worker root tidak aktif. Periksa j-backup-worker.timer dan pastikan worker memakai database aplikasi yang sama."
                    : "Worker belum mengambil tes folder dalam 20 detik. Worker mungkin sedang memproses pekerjaan lain; coba lagi setelah pekerjaan selesai.");
            }
        }
        throw new Error(
            "Worker belum menyelesaikan pengujian folder. Periksa status timer."
        );
    }

    function configuredStoragePath(kind) {
        const inputName = kind === "rsync" ? "rsync_dir" : "backup_dir";
        const form = document.querySelector('[data-form="settings-backup"]');
        const path = String(
            form?.elements?.[inputName]?.value
            || state.dashboard?.settings?.[inputName]
            || ""
        ).trim();
        if (!path.startsWith("/")) {
            throw new Error("Folder harus berupa path absolut Linux.");
        }
        return path;
    }

    async function completedPathTask(task) {
        if (task.status === "success") return task;
        if (task.status === "failed") {
            throw new Error(task.error || "Pengujian folder gagal dijalankan.");
        }
        return waitForPathTask(task.id);
    }

    async function runPathCheck(kind, showResult = true) {
        const path = configuredStoragePath(kind);

        if (showResult) showModal(`
            <div class="ssh-wait"><i></i>
                <p class="eyebrow">MENUNGGU WORKER</p>
                <h2>Menguji akses folder</h2>
                <p class="path">${escapeHtml(path)}</p>
                <p class="muted">Worker akan membuat folder jika belum tersedia, lalu membuat dan menghapus file uji kecil.</p>
            </div>
        `, true);
        const response = await api("path_task_create", {
            method: "POST",
            body: { kind, path },
        });
        const task = await completedPathTask(response.task);
        const result = task.result;
        state.pathChecks[kind] = result;

        const current = document.querySelector("#path-check-storage");
        if (current) {
            current.outerHTML = storagePathCheckMarkup(
                state.pathChecks.rsync,
                state.pathChecks.backup
            );
        }
        if (showResult) {
            showModal(pathCheckDetailMarkup(result), true);
            toast(result.ready
                ? "Folder siap digunakan oleh worker."
                : "Folder belum dapat digunakan oleh worker.");
        }
        return result;
    }

    async function runStoragePathChecks() {
        const paths = {
            rsync: configuredStoragePath("rsync"),
            backup: configuredStoragePath("backup"),
        };
        showModal(`<div class="ssh-wait"><i></i><p class="eyebrow">MENUNGGU WORKER</p><h2>Mempersiapkan folder penyimpanan</h2><p class="muted">Folder RSYNC dan BACKUP sedang masuk antrean bersama agar diperiksa dalam satu siklus worker.</p></div>`, true);
        try {
            const queued = {};
            for (const kind of ["rsync", "backup"]) {
                const response = await api("path_task_create", {
                    method: "POST",
                    body: { kind, path: paths[kind] },
                });
                queued[kind] = response.task;
            }
            const results = {};
            for (const kind of ["rsync", "backup"]) {
                const task = await completedPathTask(queued[kind]);
                results[kind] = task.result;
                state.pathChecks[kind] = task.result;
            }
            const { rsync, backup } = results;
            const current = document.querySelector("#path-check-storage");
            if (current) current.outerHTML = storagePathCheckMarkup(rsync, backup);
            const ready = rsync.ready && backup.ready;
            showModal(`<p class="eyebrow">TES AKSES FOLDER</p><h2>${ready ? "Folder siap digunakan" : "Periksa folder penyimpanan"}</h2>${ready ? `<div class="storage-check-success"><i>✓</i><span><strong>Berhasil</strong><small>Folder RSYNC dan BACKUP siap digunakan oleh worker.</small></span></div>` : ""}<p class="muted">Ringkasan pemeriksaan Folder RSYNC dan BACKUP.</p><div class="storage-check-results">${storagePathCheckDetailMarkup("FOLDER RSYNC", rsync)}${storagePathCheckDetailMarkup("FOLDER BACKUP", backup)}</div>`, true);
            toast(ready ? "Folder RSYNC dan Backup siap digunakan." : "Salah satu folder belum dapat digunakan.");
        } catch (error) {
            showModal(`<p class="eyebrow">TES AKSES FOLDER GAGAL</p><h2>Pengujian tidak dapat diselesaikan</h2><p class="muted">${escapeHtml(error.message || "Terjadi kesalahan saat menguji folder.")}</p>`, true);
            toast(error.message || "Pengujian folder gagal dijalankan.");
        }
    }

    function currentSshSettings() {
        const form = document.querySelector('[data-form="settings-ssh"]');
        if (!form) throw new Error("Form pengaturan tidak ditemukan.");
        const values = new FormData(form);
        return {
            remote_host: String(values.get("remote_host") || "").trim(),
            remote_port: Number(values.get("remote_port") || 22),
            remote_user: String(values.get("remote_user") || "").trim(),
            ssh_key_type: String(values.get("ssh_key_type") || "rsa4096"),
            ssh_key_comment: String(values.get("ssh_key_comment") || "J-Backup-Key-RSA").trim(),
            password: String(document.querySelector("#ssh-password")?.value || ""),
        };
    }

    async function waitForSshTask(id, onUpdate) {
        for (let attempt = 0; attempt < 75; attempt += 1) {
            await new Promise((resolve) => setTimeout(resolve, 1000));
            const response = await api("ssh_task_status", { query: { id } });
            onUpdate?.(response.task);
            if (response.task.status === "success") return response.task;
            if (response.task.status === "failed") {
                const error = new Error(response.task.error || "Tindakan SSH gagal.");
                error.task = response.task;
                throw error;
            }
            if (
                response.task.status === "queued"
                && attempt >= 20
                && !workerIsReady(response.worker_heartbeat)
            ) {
                const error = new Error(
                    "Worker untuk database ini tidak aktif. Pastikan browser membuka instalasi WSL dan worker memakai file storage/j-backup.sqlite yang sama."
                );
                error.task = response.task;
                throw error;
            }
        }
        throw new Error("Worker belum menyelesaikan tindakan SSH. Periksa status timer.");
    }

    async function runSshTool(type, installKey = false) {
        const labels = {
            generate_key: "Pembuatan kunci",
            test_connection: "Pengujian koneksi",
            disconnect: "Pemutusan koneksi",
        };
        if (installKey) labels.generate_key = "Pemasangan login tanpa password";
        toast(`${labels[type]} masuk antrean worker…`);
        let terminalLog = "Tugas dimasukkan ke antrean.\nMenunggu worker mengambil tugas...";
        const terminal = () => `
            <pre class="ssh-terminal" id="ssh-terminal" aria-live="polite">${escapeHtml(terminalLog)}</pre>`;
        showModal(`
            <div class="ssh-wait"><i></i><p class="eyebrow">MENUNGGU WORKER</p>
                <h2>${escapeHtml(labels[type])} sedang diproses</h2>
                <p class="muted" id="ssh-progress-status">Menunggu worker menjalankan perintah.</p>
                ${terminal()}</div>
        `, true);
        try {
            const sshSettings = currentSshSettings();
            const password = sshSettings.password;
            delete sshSettings.password;
            if (type === "generate_key" && installKey) {
                await api("settings_update", {
                    method: "POST",
                    body: {
                        ...sshSettings,
                        ...(password ? { ssh_password: password } : {}),
                    },
                });
            }
            const response = await api("ssh_task_create", {
                method: "POST",
                body: {
                    type,
                    install_key: installKey,
                    ...sshSettings,
                    ...(installKey ? { password } : {}),
                },
            });
            const task = await waitForSshTask(response.task.id, (current) => {
                terminalLog = current.log || (
                    current.status === "queued"
                        ? "Tugas berada dalam antrean.\nMenunggu worker mengambil tugas..."
                        : "Worker sedang memproses tugas..."
                );
                const output = document.querySelector("#ssh-terminal");
                if (output) {
                    output.textContent = terminalLog;
                    output.scrollTop = output.scrollHeight;
                }
                const status = document.querySelector("#ssh-progress-status");
                if (status) {
                    status.textContent = current.status === "queued"
                        ? "Menunggu jadwal worker berikutnya."
                        : "Worker sedang menjalankan tindakan SSH.";
                }
            });
            terminalLog = task.log || terminalLog;
            if (type === "generate_key") {
                state.publicKey = task.result.public_key;
                showModal(`
                    <p class="eyebrow">PUBLIC KEY SSH</p>
                    <h2>${task.result.installed ? "Login tanpa password aktif" : "Kunci siap digunakan"}</h2>
                    <p class="muted">${escapeHtml(task.result.message)}</p>
                    ${task.result.installed ? `<div class="connection-success"><i>✓</i><span><strong>${escapeHtml(task.result.target)}</strong>
                        <small>Public key terpasang dan sudah diverifikasi</small></span></div>` : ""}
                    <label class="field">Salin ke authorized_keys server sumber
                        <textarea id="ssh-public-key" rows="5" readonly>${escapeHtml(task.result.public_key)}</textarea>
                    </label>
                    <p class="path">${escapeHtml(task.result.private_key_path)}</p>
                    <button class="button primary wide action-save" data-action="copy-public-key"><span>⧉</span>Salin public key</button>
                    ${terminal()}
                `, true);
            } else if (type === "test_connection") {
                showModal(`
                    <p class="eyebrow">TES KONEKSI</p>
                    <h2>Koneksi SSH berhasil</h2>
                    <p class="muted">Autentikasi private key diterima oleh server sumber.</p>
                    <div class="connection-success"><i>✓</i><span><strong>${escapeHtml(task.result.target)}</strong>
                        <small>${task.result.latency_ms ? `${task.result.latency_ms} ms` : "Terhubung"}</small></span></div>
                    ${terminal()}
                `, true);
            } else {
                const remoteKeyRemoved = task.result.remote_key_removed !== false;
                showModal(`
                    <p class="eyebrow">KONEKSI SSH DIPUTUS</p>
                    <h2>${remoteKeyRemoved ? "Disconnect berhasil" : "Status lokal dibersihkan"}</h2>
                    <p class="muted">${remoteKeyRemoved
                        ? "Public key J-BACKUP telah dicabut dari server sumber."
                        : "Key lokal hilang atau bermasalah. Status lokal sudah dibersihkan agar Connect ulang tersedia; public key lama mungkin masih tersimpan di server sumber."}</p>
                    <div class="connection-success"><i>✓</i><span><strong>${escapeHtml(task.result.target)}</strong>
                        <small>${remoteKeyRemoved
                            ? "Key lokal, known_hosts, dan password tersimpan sudah dihapus"
                            : "Silakan jalankan Connect untuk membuat dan memasang key pengganti"}</small></span></div>
                    ${terminal()}
                `, true);
            }
            await loadDashboard();
            toast(task.result.message);
        } catch (error) {
            terminalLog = error.task?.log || terminalLog;
            showModal(`
                <p class="eyebrow">TINDAKAN SSH GAGAL</p>
                <h2>Periksa konfigurasi koneksi</h2>
                <p class="error-box">${escapeHtml(error.message)}</p>
                ${terminal()}
            `, true);
            throw error;
        }
    }

    document.addEventListener("click", async (event) => {
        const target = event.target.closest("button");
        if (!target) return;
        try {
            if (target.dataset.tab) {
                state.accountMenu = false;
                state.mobileMenu = false;
                state.tab = target.dataset.tab;
                renderApp();
                if (state.tab === "storage") {
                    await loadDisks();
                } else if (state.tab === "explorer" && !state.storageLoading) {
                    state.storage = null;
                    state.storagePath = "";
                    state.storageQuery = "";
                    state.storageError = "";
                    await loadStorage(state.storagePath);
                } else if (state.tab === "settings") {
                    await revealStoredSshPassword();
                }
            } else if (target.dataset.explorerKind) {
                const kind = target.dataset.explorerKind;
                if (!["backup", "rsync"].includes(kind) || kind === state.explorerKind) return;
                state.explorerKind = kind;
                state.storage = null;
                state.storagePath = "";
                state.storageQuery = "";
                state.storageError = "";
                renderApp();
                await loadStorage("");
            } else if (target.dataset.action === "storage-delete") {
                const entry = state.storage?.entries?.find(
                    (item) => item.path === target.dataset.storageDeletePath
                );
                if (entry) storageDeleteDialog(entry);
            } else if (target.dataset.action === "storage-delete-confirm") {
                const path = target.dataset.storageDeletePath;
                const entry = state.storage?.entries?.find((item) => item.path === path);
                if (!entry) throw new Error("File atau folder yang dipilih tidak ditemukan.");
                const result = await api(`${state.explorerKind}_delete`, {
                    method: "POST",
                    body: { path },
                });
                closeModal();
                toast(`${result.deleted} berhasil dihapus.`);
                await loadStorage();
            } else if (target.dataset.storagePath !== undefined) {
                state.storageQuery = "";
                await loadStorage(target.dataset.storagePath);
            } else if (target.dataset.action === "storage-refresh") {
                await loadStorage();
            } else if (target.dataset.action === "disk-refresh") {
                await loadDisks();
            } else if (target.dataset.action === "toggle-ssh-password") {
                const password = document.querySelector("#ssh-password");
                const visible = password?.type === "password";
                if (password) password.type = visible ? "text" : "password";
                target.classList.toggle("visible", visible);
                target.setAttribute("aria-pressed", String(visible));
                target.setAttribute(
                    "aria-label",
                    visible ? "Sembunyikan password SSH" : "Tampilkan password SSH"
                );
                target.title = visible ? "Sembunyikan password" : "Tampilkan password";
            } else if (target.dataset.action === "toggle-auth-password") {
                const password = document.querySelector("#auth-password");
                const visible = password?.type === "password";
                if (password) password.type = visible ? "text" : "password";
                target.classList.toggle("visible", visible);
                target.setAttribute("aria-pressed", String(visible));
                target.setAttribute(
                    "aria-label",
                    visible ? "Sembunyikan password" : "Tampilkan password"
                );
            } else if (target.dataset.action === "theme") {
                const next = document.documentElement.dataset.theme === "dark" ? "light" : "dark";
                document.documentElement.dataset.theme = next;
                localStorage.setItem("jbackup-theme", next);
            } else if (target.dataset.action === "mobile-menu") {
                state.mobileMenu = !state.mobileMenu;
                state.accountMenu = false;
                renderApp();
            } else if (target.dataset.action === "mobile-menu-close") {
                state.mobileMenu = false;
                renderApp();
            } else if (target.dataset.action === "account-menu") {
                state.accountMenu = !state.accountMenu;
                renderApp();
            } else if (target.dataset.action === "account-settings") {
                state.accountMenu = false;
                renderApp();
                accountDialog();
            } else if (target.dataset.action === "logout") {
                state.accountMenu = false;
                await api("logout", { method: "POST", body: {} });
                state.dashboard = null;
                state.selected.clear();
                if (state.poller) clearInterval(state.poller);
                state.poller = null;
                await boot();
            } else if (target.dataset.action === "import-sources") {
                sourceImportDialog();
            } else if (target.dataset.action === "export-sources") {
                exportSources();
            } else if (target.dataset.action === "add") sourceDialog();
            else if (target.dataset.action === "manual") manualDialog();
            else if (target.dataset.action === "test-path") {
                await runStoragePathChecks();
            }
            else if (target.dataset.action === "close-modal") closeModal();
            else if (target.dataset.action === "select-all") {
                const filtered = state.dashboard.sources.filter((item) =>
                    `${item.name}`.toLowerCase()
                        .includes(state.query.toLowerCase())
                );
                state.selected = state.selected.size === filtered.length
                    ? new Set()
                    : new Set(filtered.map((item) => item.id));
                renderApp();
            } else if (target.dataset.action === "delete-selected") {
                const ids = [...state.selected];
                if (!ids.length) return;
                if (confirm(
                    `Hapus ${ids.length} sumber terpilih dari daftar?\n\n`
                    + "Riwayat pekerjaan dan file hasil backup tidak ikut dihapus."
                )) {
                    const result = await api("sources_delete", {
                        method: "POST",
                        body: { ids },
                    });
                    state.selected.clear();
                    toast(`${result.deleted_count} sumber dihapus.`);
                    await loadDashboard();
                }
            } else if (target.dataset.run) await startJobs(target.dataset.run);
            else if (target.dataset.editId) {
                const source = state.dashboard.sources.find(
                    (item) => item.id === Number(target.dataset.editId)
                );
                if (source) sourceDialog(source);
            }
            else if (target.dataset.deleteId) {
                const id = Number(target.dataset.deleteId);
                const item = state.dashboard.sources.find((source) => source.id === id);
                if (confirm(`Hapus ${item.name} dari daftar?`)) {
                    await api("source_delete", { method: "POST", body: { id } });
                    state.selected.delete(id);
                    toast("Sumber dihapus.");
                    await loadDashboard();
                }
            } else if (target.dataset.jobId) jobDialog(target.dataset.jobId);
            else if (target.dataset.cancelJob) {
                await api("job_cancel", { method: "POST", body: { id: target.dataset.cancelJob } });
                closeModal();
                toast("Permintaan pembatalan dikirim.");
                await loadDashboard();
            } else if (target.dataset.action === "cancel-all-jobs") {
                cancelAllJobsDialog();
            } else if (target.dataset.action === "confirm-cancel-all-jobs") {
                const result = await api("jobs_cancel_all", {
                    method: "POST",
                    body: {},
                });
                closeModal();
                toast(result.total
                    ? `${result.total} pekerjaan dibatalkan atau sedang dihentikan.`
                    : "Tidak ada pekerjaan aktif atau antrean.");
                await loadDashboard();
            } else if (target.dataset.action === "clear-job-history") {
                clearJobHistoryDialog();
            } else if (target.dataset.action === "confirm-clear-job-history") {
                const status = document.querySelector("#history-clear-status")?.value || "all";
                const result = await api("jobs_history_clear", {
                    method: "POST",
                    body: { status },
                });
                closeModal();
                toast(`${result.deleted} riwayat pekerjaan dihapus.`);
                await loadDashboard();
            } else if (target.dataset.scheduleDay) {
                const schedule = state.dashboard.schedules.find((item) => item.type === target.dataset.scheduleDay);
                const day = Number(target.dataset.day);
                const nextDays = schedule.days.includes(day)
                    ? schedule.days.filter((value) => value !== day)
                    : [...schedule.days, day];
                if (nextDays.length) await updateSchedule(schedule.type, { days: nextDays });
            } else if (target.dataset.action === "check-update") {
                await checkForUpdates({ showResult: true });
            } else if (target.dataset.action === "install-update") {
                updateReleaseDialog();
            } else if (target.dataset.action === "confirm-install-update") {
                await installUpdate();
            } else if (target.dataset.action === "dismiss-update") {
                if (state.update.release?.tag) {
                    localStorage.setItem("jbackup-update-dismissed", state.update.release.tag);
                }
                renderApp();
            } else if (target.dataset.action === "reload-app") {
                window.location.reload();
            } else if (target.dataset.action === "reset-database") {
                resetDatabaseDialog();
            } else if (target.dataset.action === "ssh-connect") {
                await runSshTool("generate_key", true);
            } else if (target.dataset.action === "ssh-disconnect") {
                if (confirm(
                    "Disconnect akan mencabut public key J-BACKUP dari server sumber dan menghapus key serta password SSH lokal. Lanjutkan?"
                )) {
                    await runSshTool("disconnect");
                }
            } else if (target.dataset.action === "ssh-test") {
                await runSshTool("test_connection");
            } else if (target.dataset.action === "delete-ssh-password") {
                if (confirm("Hapus password SSH yang tersimpan?")) {
                    await api("ssh_password_delete", { method: "POST", body: {} });
                    const password = document.querySelector("#ssh-password");
                    if (password) password.value = "";
                    toast("Password SSH tersimpan telah dihapus.");
                    await loadDashboard();
                }
            } else if (target.dataset.action === "delete-telegram-token") {
                if (confirm("Hapus token bot Telegram yang tersimpan?")) {
                    await api("telegram_token_delete", {
                        method: "POST",
                        body: {},
                    });
                    toast("Token bot Telegram telah dihapus.");
                    await loadDashboard();
                }
            } else if (target.dataset.action === "test-telegram") {
                const form = document.querySelector(
                    '[data-form="settings-telegram"]'
                );
                const data = Object.fromEntries(new FormData(form).entries());
                await api("telegram_test", {
                    method: "POST",
                    body: data,
                });
                toast("Pesan tes berhasil dikirim ke Telegram.");
                await loadDashboard();
            } else if (target.dataset.action === "lookup-telegram-chat-id") {
                const form = document.querySelector('[data-form="settings-telegram"]');
                const token = String(form?.elements?.telegram_bot_token?.value || "").trim();
                const result = await api("telegram_chat_id_lookup", {
                    method: "POST",
                    body: { telegram_bot_token: token },
                });
                const input = form?.elements?.telegram_chat_id;
                if (input) input.value = result.chat_id;
                toast(`Chat ID ${result.chat_id} ditemukan. Tekan Simpan untuk menyimpannya.`);
            } else if (target.dataset.action === "copy-public-key") {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(state.publicKey);
                } else {
                    const publicKey = document.querySelector("#ssh-public-key");
                    publicKey?.select();
                    document.execCommand("copy");
                }
                toast("Public key disalin.");
            } else {
                const btn = target.closest('[data-action="move-telegram-field-up"], [data-action="move-telegram-field-down"]');
                if (btn) {
                    const isUp = btn.dataset.action === "move-telegram-field-up";
                    const row = btn.closest(".order-row");
                    const list = btn.closest(".telegram-order-list");
                    const orderInput = document.querySelector('[name="telegram_fields_order"]');
                    if (row && list && orderInput) {
                        if (isUp && row.previousElementSibling) {
                            list.insertBefore(row, row.previousElementSibling);
                        } else if (!isUp && row.nextElementSibling) {
                            list.insertBefore(row.nextElementSibling, row);
                        }
                        const rows = Array.from(list.querySelectorAll(".order-row"));
                        const newKeys = rows.map((r) => r.dataset.fieldKey);
                        const orderStr = newKeys.join(",");
                        orderInput.value = orderStr;
                        if (state.dashboard?.settings) {
                            state.dashboard.settings.telegram_fields_order = orderStr;
                        }
                        rows.forEach((r, idx) => {
                            const upBtn = r.querySelector('[data-action="move-telegram-field-up"]');
                            const downBtn = r.querySelector('[data-action="move-telegram-field-down"]');
                            if (upBtn) upBtn.disabled = idx === 0;
                            if (downBtn) downBtn.disabled = idx === rows.length - 1;
                        });
                    }
                }
            }
        } catch (error) {
            toast(error.message);
        }
    });

    document.addEventListener("change", async (event) => {
        const target = event.target;
        try {
            if (target.dataset.selectId) {
                const id = Number(target.dataset.selectId);
                target.checked ? state.selected.add(id) : state.selected.delete(id);
                renderApp();
            } else if (target.dataset.toggleId) {
                await api("source_update", {
                    method: "POST",
                    body: {
                        id: target.dataset.toggleId,
                        enabled: target.checked ? "1" : "0",
                    },
                });
                await loadDashboard();
            } else if (target.dataset.action === "toggle-telegram") {
                const container = document.querySelector(".telegram-fields-container");
                if (container) container.classList.toggle("is-hidden", !target.checked);
                if (!target.checked) {
                    await api("settings_update", {
                        method: "POST",
                        body: { telegram_enabled: "0" },
                    });
                    toast("Notifikasi Telegram dinonaktifkan.");
                    await loadDashboard();
                }
            } else if (target.dataset.scheduleEnabled) {
                await updateSchedule(target.dataset.scheduleEnabled, { enabled: target.checked });
            } else if (target.dataset.scheduleMode) {
                const schedule = state.dashboard.schedules.find(
                    (item) => item.type === target.dataset.scheduleMode
                );
                if (schedule) {
                    schedule.mode = target.value;
                    renderApp();
                }
            } else if (target.name === "ssh_key_type") {
                const keyPath = document.querySelector('[name="ssh_key_path"]');
                if (keyPath) {
                    keyPath.value = keyPath.value.replace(
                        /\/id_(?:ed25519|rsa)$/,
                        target.value === "rsa4096" ? "/id_rsa" : "/id_ed25519"
                    );
                }
            } else if (target.name === "telegram_backup_file_interval_unit" || target.matches("[data-backup-file-start-time], [data-backup-file-interval]")) {
                updateBackupFileIntervalControls(target.closest("form"));
            } else if (target.id === "storage-upload") {
                await uploadStorageFile(target.files?.[0]);
                target.value = "";
            }
        } catch (error) {
            toast(error.message);
        }
    });

    document.addEventListener("input", (event) => {
        if (event.target.id === "source-search") {
            state.query = event.target.value;
            const cursor = event.target.selectionStart;
            renderApp();
            const next = document.querySelector("#source-search");
            next?.focus();
            next?.setSelectionRange(cursor, cursor);
        } else if (event.target.id === "storage-search") {
            state.storageQuery = event.target.value;
            const cursor = event.target.selectionStart;
            renderApp();
            const next = document.querySelector("#storage-search");
            next?.focus();
            next?.setSelectionRange(cursor, cursor);
        } else if (event.target.id === "reset-confirmation") {
            const button = document.querySelector("#reset-confirm-button");
            if (button) button.disabled = event.target.value !== "RESET";
        } else if (event.target.matches("[data-backup-file-start-time], [data-backup-file-interval]")) {
            updateBackupFileIntervalControls(event.target.closest("form"));
        }
    });

    document.addEventListener("submit", async (event) => {
        const form = event.target;
        const kind = form.dataset.form;
        if (!kind) return;
        event.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            if (kind === "auth") {
                await api(state.mode === "setup" ? "setup" : "login", {
                    method: "POST",
                    body: data,
                });
                await boot();
            } else if (kind === "account-settings") {
                const result = await api("account_update", {
                    method: "POST",
                    body: data,
                });
                state.lastActivityAt = Date.now();
                closeModal();
                toast(`Akun ${result.user.username} berhasil diperbarui.`);
                await loadDashboard();
            } else if (kind === "source-import") {
                const file = form.querySelector('[name="file"]')?.files?.[0];
                await importSourcesFile(file);
            } else if (kind === "source-add" || kind === "source-edit") {
                data.paths = String(data.paths || "")
                    .split(/\r?\n/)
                    .map((path) => path.trim())
                    .filter(Boolean);
                await api(kind === "source-edit" ? "source_update" : "source_create", {
                    method: "POST",
                    body: data,
                });
                closeModal();
                toast(kind === "source-edit"
                    ? "Sumber berhasil diperbarui."
                    : "Sumber berhasil ditambahkan.");
                await loadDashboard();
            } else if (kind === "schedule-backup") {
                const type = "backup";
                const patch = {
                    mode: data.mode,
                    interval_value: Number(data.interval_value || 1),
                };
                if (data.time) patch.time = data.time;
                await updateSchedule(type, patch);
            } else if (kind.startsWith("settings-")) {
                if (kind === "settings-backup") {
                    const powers = { KB: 1, MB: 2, GB: 3, TB: 4 };
                    const value = Math.max(0, Number(data.minimum_free_value || 0));
                    const unit = String(data.minimum_free_unit || "MB").toUpperCase();
                    data.minimum_free_bytes = Math.round(value * (1024 ** (powers[unit] || 2)));
                    delete data.minimum_free_value;
                    delete data.minimum_free_unit;
                }
                if (kind === "settings-telegram-standby") {
                    const fields = [
                        "telegram_field_waktu",
                        "telegram_field_cpu",
                        "telegram_field_memory",
                        "telegram_field_job",
                        "telegram_field_tipe",
                        "telegram_field_disk",
                        "telegram_field_anydesk",
                        "telegram_field_sumber",
                        "telegram_field_info",
                    ];
                    for (const key of fields) {
                        data[key] = form.querySelector(`[name="${key}"]`)?.checked ? "1" : "0";
                    }
                    data.telegram_standby_enabled = form.querySelector('[name="telegram_standby_enabled"]')?.checked ? "1" : "0";
                    data.telegram_rsync_enabled = form.querySelector('[name="telegram_rsync_enabled"]')?.checked ? "1" : "0";
                    data.telegram_backup_enabled = form.querySelector('[name="telegram_backup_enabled"]')?.checked ? "1" : "0";
                    data.telegram_fields_order = form.querySelector('[name="telegram_fields_order"]')?.value || "tipe,waktu,cpu,memory,job,disk,anydesk,sumber,info";
                } else if (kind === "settings-telegram") {
                    data.telegram_enabled = form.querySelector('[name="telegram_enabled"]')?.checked ? "1" : "0";
                }
                if (kind === "settings-telegram-backup-file") {
                    data.telegram_backup_file_enabled = form.querySelector('[name="telegram_backup_file_enabled"]')?.checked ? "1" : "0";
                }
                await api("settings_update", { method: "POST", body: data });
                toast("Pengaturan panel berhasil disimpan.");
                await loadDashboard();
            } else if (kind === "database-reset") {
                let result = await api("reset_database", {
                    method: "POST",
                    body: data,
                });
                closeModal();
                if (state.poller) clearInterval(state.poller);
                state.poller = null;
                state.dashboard = null;
                state.selected.clear();
                state.storage = null;
                state.mode = "loading";
                toast(result.warnings?.[0] || "Database berhasil direset.");
                await boot();
            }
        } catch (error) {
            if (kind === "auth") renderAuth(state.mode === "setup", error.message);
            else toast(error.message);
        }
    });

    async function boot() {
        try {
            const status = await api("status");
            state.csrf = status.csrf_token;
            if (status.setup_required || !status.authenticated) {
                renderAuth(status.setup_required);
                return;
            }
            state.lastActivityAt = Date.now();
            await loadDashboard();
            setTimeout(() => {
                if (state.mode === "ready" && !state.update.checked) {
                    checkForUpdates({ silent: true });
                }
            }, 1200);
            if (!state.poller) {
                state.poller = setInterval(() => {
                    if (document.visibilityState === "visible" && state.mode === "ready") {
                        loadDashboard(false, true).then(() => {
                            refreshOpenJobDialog();
                            const editing = document.querySelector("input:focus, textarea:focus, select:focus");
                            if (!modal.open && !editing && !["settings", "notifications", "schedules"].includes(state.tab)) renderApp();
                        }).catch(() => {});
                    }
                }, 5000);
            }
        } catch (error) {
            app.innerHTML = `<main class="auth-shell"><section class="auth-card"><p class="eyebrow">SERVICE TIDAK TERSEDIA</p>
                <h1>J-BACKUP belum dapat dimuat</h1><p class="muted">${escapeHtml(error.message)}</p></section></main>`;
        }
    }

    const savedTheme = localStorage.getItem("jbackup-theme");
    document.documentElement.dataset.theme = savedTheme || (matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
    const recordActivity = () => {
        if (state.mode === "ready") state.lastActivityAt = Date.now();
    };
    ["pointerdown", "keydown", "input", "touchstart"].forEach((eventName) => {
        document.addEventListener(eventName, recordActivity, { passive: true });
    });
    state.idleTimer = setInterval(async () => {
        if (state.mode !== "ready" || !state.dashboard) return;
        const minutes = Number(
            state.dashboard.settings.session_timeout_minutes ?? 30
        );
        if (minutes <= 0 || Date.now() - state.lastActivityAt < minutes * 60000) {
            return;
        }
        state.mode = "logging-out";
        try {
            await api("logout", { method: "POST", body: {} });
        } catch (_) {
            // The server may already have expired the session.
        }
        state.dashboard = null;
        if (state.poller) clearInterval(state.poller);
        state.poller = null;
        await boot();
        toast("Sesi berakhir karena tidak ada aktivitas.");
    }, 15000);
    boot();
})();
