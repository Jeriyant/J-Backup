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
        ["backup", "7Z", "Backup"],
        ["realtime", "↕", "Realtime"],
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
        pathChecks: {
            realtime: null,
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

    const dateTime = (value) => {
        if (!value) return "—";
        return new Intl.DateTimeFormat("id-ID", {
            dateStyle: "medium",
            timeStyle: "short",
        }).format(new Date(value));
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

    function toast(message) {
        toastElement.textContent = message;
        toastElement.hidden = false;
        clearTimeout(toastElement.timer);
        toastElement.timer = setTimeout(() => {
            toastElement.hidden = true;
        }, 3400);
    }

    function showModal(content, wide = false) {
        modal.className = wide ? "modal wide" : "modal";
        modal.innerHTML = `<button class="modal-close" data-action="close-modal" aria-label="Tutup">×</button>${content}`;
        if (!modal.open) modal.showModal();
    }

    function closeModal() {
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
                    <h1>${setupRequired ? "Buat akses administrator" : "Selamat datang kembali"}</h1>
                    <p class="muted">${setupRequired
                        ? "Akun ini melindungi konfigurasi dan pekerjaan backup."
                        : "Masuk untuk mengelola sinkronisasi dan backup server."}</p>
                    <form class="form" data-form="auth">
                        <label>Username<input name="username" autocomplete="username" minlength="3" required></label>
                        <label>Password<input name="password" type="password" minlength="1"
                            autocomplete="${setupRequired ? "new-password" : "current-password"}" required></label>
                        ${error ? `<p class="auth-error">${escapeHtml(error)}</p>` : ""}
                        <button class="button primary wide action-access" type="submit">
                            <span>→</span>${setupRequired ? "Buat akun & masuk" : "Masuk ke dashboard"}
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
        state.pathChecks.realtime =
            state.dashboard.path_checks?.realtime || null;
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
        if (["backup", "realtime"].includes(state.tab)) renderApp();
        try {
            state.storage = await api(`${state.explorerKind}_list`, { query: { path } });
            state.storagePath = state.storage.path;
        } catch (error) {
            state.storage = null;
            state.storageError = error.message;
            throw error;
        } finally {
            state.storageLoading = false;
            if (["backup", "realtime"].includes(state.tab)) renderApp();
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
            <h2>${payload.imported_count || 0} sumber berhasil ditambahkan</h2>
            <p class="muted">${payload.failed_count || 0} baris tidak dapat diimpor.</p>
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
                            <button class="button manual-action" data-action="manual"><span>▶</span>Jalankan manual</button>
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
                    <section id="view">${renderView()}</section>
                </main>
                <footer class="app-copyright">Copyright © JERIYANT - BARAMCITY</footer>
            </div>`;
    }

    function renderView() {
        if (state.tab === "sources") return renderSources();
        if (state.tab === "history") return renderHistory();
        if (state.tab === "schedules") return renderSchedules();
        if (state.tab === "storage") return renderStorage();
        if (state.tab === "backup") return renderExplorer("backup");
        if (state.tab === "realtime") return renderExplorer("realtime");
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
        const sshConnected = d.settings.ssh_connected === true;
        const sshTarget = d.settings.ssh_connected_target
            || (d.settings.remote_host
                ? `${d.settings.remote_user}@${d.settings.remote_host}`
                : "Host sumber belum diatur");
        const critical = [];
        const warnings = [];
        if (!workerReady) critical.push("Worker tidak terhubung");
        if (!d.disk.available) {
            critical.push("Folder backup tidak tersedia");
        } else if (d.disk.free < Number(d.settings.minimum_free_bytes || 0)) {
            critical.push("Ruang disk di bawah batas minimum");
        }
        if (Number(system.cpu_percent) >= 95) critical.push("CPU mencapai batas kritis");
        else if (Number(system.cpu_percent) >= 80) warnings.push("Penggunaan CPU tinggi");
        if (Number(system.memory?.used_percent) >= 95) critical.push("Memory mencapai batas kritis");
        else if (Number(system.memory?.used_percent) >= 80) warnings.push("Penggunaan memory tinggi");
        if (Number.isFinite(state.latencyMs) && state.latencyMs > 500) {
            critical.push("Latensi browser sangat tinggi");
        } else if (Number.isFinite(state.latencyMs) && state.latencyMs > 250) {
            warnings.push("Latensi browser tinggi");
        }
        if (activeSources.length && !sshConnected) warnings.push("SSH sumber belum terhubung");
        if (!enabledSchedules.length) warnings.push("Tidak ada jadwal aktif");
        if (failures) warnings.push(`${failures} pekerjaan gagal dalam 24 jam`);

        const healthLevel = critical.length
            ? "critical"
            : active
                ? "running"
                : warnings.length
                    ? "warning"
                    : "healthy";
        const headline = {
            critical: "Sistem memerlukan tindakan segera",
            warning: "Sistem berjalan dengan peringatan",
            running: `${active?.type === "sync" ? "Sinkronisasi" : "Backup"} sedang berjalan`,
            healthy: "Seluruh komponen terpantau normal",
        }[healthLevel];
        const detail = critical.length
            ? [...critical, ...warnings].join(" · ")
            : active
                ? `${active.source_name} sedang diproses. ${d.queue_count} pekerjaan menunggu.${warnings.length ? ` Catatan: ${warnings.join(" · ")}` : ""}`
                : warnings.length
                    ? warnings.join(" · ")
                    : "Worker, SSH, disk, resource host, dan jadwal berada dalam kondisi siap.";
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
                        <small class="health-scope">Worker · SSH · disk · CPU · memory · jadwal · pekerjaan</small></div>
                    <div class="health"><span>${healthBadge}</span></div>
                </article>
                <div class="metrics">
                    <article class="metric"><p>Sumber aktif</p><strong>${d.sources.filter((item) => item.enabled).length}</strong><small>dari ${d.sources.length} terdaftar</small></article>
                    <article class="metric"><p>Job berhasil</p><strong>${successes}</strong><small>dari ${d.jobs.length} riwayat terakhir</small></article>
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
                            <span><i class="${schedule.enabled ? "online" : ""}"></i>${schedule.type === "sync" ? "Realtime rsync" : "Backup 7z"}</span>
                            <strong>${schedule.enabled ? scheduleText(schedule) : "Nonaktif"}</strong>
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
        const filtered = d.sources.filter((item) => item.name.toLowerCase().includes(state.query.toLowerCase()));
        return `
            <article class="panel full">
                <div class="panel-heading">
                    <div><p class="eyebrow">SUMBER BACKUP</p><h2>Daftar sumber</h2>
                        <p class="muted">Setiap sumber dapat berisi satu atau banyak path folder remote.</p></div>
                    <div class="toolbar">
                        <button class="button ghost" data-action="import-sources">
                            <span>⇧</span>Import Excel
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
                        </article>`).join("") || `<div class="empty"><strong>Belum ada sumber</strong>Tambahkan nama dan satu atau beberapa path folder remote.</div>`}
                </div>
                ${state.selected.size ? `
                    <div class="selection-bar"><strong>${state.selected.size} sumber dipilih</strong><span>Jalankan hanya untuk pilihan ini.</span>
                        <button class="button ghost" data-run="sync"><span>↕</span>Sinkronkan</button>
                        <button class="button primary" data-run="backup"><span>7Z</span>Buat backup</button></div>` : ""}
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
            <h2>Riwayat sinkronisasi & backup</h2></div>
            <div class="toolbar">
                <span class="tag">${state.dashboard.jobs.length} pekerjaan</span>
                ${historyJobs.length ? `
                    <button class="button danger" data-action="clear-job-history">
                        <span>×</span>Hapus riwayat
                    </button>` : ""}
                ${cancellableJobs.length ? `
                    <button class="button danger" data-action="cancel-all-jobs">
                        <span>■</span>Batalkan semua (${cancellableJobs.length})
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
        return `<div class="table-wrap"><table><thead><tr><th>Sumber</th><th>Proses</th><th>Status</th><th>Ukuran</th><th>Waktu</th><th></th></tr></thead>
            <tbody>${sortedJobs.map((job) => `<tr>
                <td><strong>${escapeHtml(job.source_name)}</strong><small>${escapeHtml(job.output_path || job.error || "Menunggu proses")}</small></td>
                <td>${job.type === "sync" ? "Sinkronisasi" : "Backup 7z"}</td>
                <td><span class="status status-${escapeHtml(job.status)}">${statusText(job.status)}</span></td>
                <td>${job.size_bytes ? bytes(job.size_bytes) : "—"}</td><td>${dateTime(job.started_at || job.queued_at)}</td>
                <td><button class="row-button" data-job-id="${escapeHtml(job.id)}" aria-label="Lihat detail">→</button></td>
            </tr>`).join("")}</tbody></table></div>`;
    }

    function renderSchedules() {
        return `<div class="grid cards-2">${state.dashboard.schedules.map((schedule) => `
            <article class="panel schedule-card schedule-${schedule.type}">
                <div class="schedule-top"><div class="schedule-icon">${schedule.type === "sync" ? "↕" : "7Z"}</div>
                    <label class="switch"><input type="checkbox" data-schedule-enabled="${schedule.type}" ${schedule.enabled ? "checked" : ""}><span></span></label></div>
                <p class="eyebrow">${schedule.type === "sync" ? "RSYNC" : "KOMPRESI 7Z"}</p>
                <h2>Jadwal ${schedule.type === "sync" ? "sinkronisasi" : "backup"}</h2>
                <p class="muted">${schedule.type === "sync" ? "Menyalin folder remote ke data realtime lokal." : "Membuat dan memverifikasi arsip di folder tujuan."}</p>
                <div class="schedule-controls">
                    <label class="field">Pola jadwal
                        <select data-schedule-mode="${schedule.type}">
                            <option value="minutes" ${schedule.mode === "minutes" ? "selected" : ""}>Setiap Menit</option>
                            <option value="hours" ${schedule.mode === "hours" ? "selected" : ""}>Setiap Jam</option>
                            <option value="daily" ${schedule.mode === "daily" ? "selected" : ""}>Setiap Hari</option>
                        </select>
                    </label>
                    ${["minutes", "hours"].includes(schedule.mode) ? `
                        <label class="field">Jalankan setiap
                            <span class="interval-input">
                                <input type="number" min="1" max="${schedule.mode === "minutes" ? 1440 : 168}"
                                    data-schedule-interval="${schedule.type}" value="${schedule.interval_value}">
                                <b>${schedule.mode === "minutes" ? "menit" : "jam"}</b>
                            </span>
                        </label>` : `
                        <label class="field">Waktu mulai
                            <input class="time-input" type="time" data-schedule-time="${schedule.type}" value="${schedule.time}">
                        </label>`}
                </div>
                <div class="schedule-foot">${schedule.enabled ? `Aktif · ${scheduleText(schedule)}` : `Nonaktif · ${scheduleText(schedule)}`}</div>
            </article>`).join("")}</div>`;
    }

    function renderStorage() {
        const isSystemMount = (path) => /^\/(?:usr\/lib\/wsl|proc|sys|dev|run)(?:\/|$)/.test(String(path));
        const disks = [...(state.disks?.disks || [])].sort((left, right) => {
            const systemOrder = Number(isSystemMount(left.path)) - Number(isSystemMount(right.path));
            return systemOrder || String(left.path).localeCompare(String(right.path));
        });
        const target = state.dashboard.disk;
        const realtimePath = state.dashboard.settings.staging_dir;
        const realtimeMount = diskForPath(realtimePath, disks);
        const realtimeTarget = realtimeMount
            ? { ...realtimeMount, path: realtimePath, available: true }
            : { path: realtimePath, available: false, used: 0, total: 0, free: 0, used_percent: 0 };
        return `<div class="grid storage-layout">
            <article class="panel capacity"><div class="panel-heading"><div><p class="eyebrow">DISK TUJUAN</p>
                <h2>${target.available ? "Storage terhubung" : "Storage tidak tersedia"}</h2></div>
                <span class="status ${target.available ? "status-success" : "status-failed"}">${target.available ? "Online" : "Periksa"}</span></div>
                <p class="path">${escapeHtml(target.path)}</p>
                <div class="capacity-number"><strong>${bytes(target.used)}</strong><span>dari ${bytes(target.total)}</span></div>
                <div class="capacity-track"><i style="width:${Math.min(target.used_percent, 100)}%"></i></div>
                <div class="capacity-foot"><span>${target.used_percent}% terpakai</span><strong>${bytes(target.free)} tersedia</strong></div>
            </article>
            <article class="panel capacity"><div class="panel-heading"><div><p class="eyebrow">DISK TUJUAN REALTIME</p>
                <h2>${realtimeTarget.available ? "Storage terhubung" : "Storage tidak tersedia"}</h2></div>
                <span class="status ${realtimeTarget.available ? "status-success" : "status-failed"}">${realtimeTarget.available ? "Online" : "Periksa"}</span></div>
                <p class="path">${escapeHtml(realtimeTarget.path)}</p>
                <div class="capacity-number"><strong>${bytes(realtimeTarget.used)}</strong><span>dari ${bytes(realtimeTarget.total)}</span></div>
                <div class="capacity-track"><i style="width:${Math.min(realtimeTarget.used_percent, 100)}%"></i></div>
                <div class="capacity-foot"><span>${realtimeTarget.used_percent}% terpakai</span><strong>${bytes(realtimeTarget.free)} tersedia</strong></div>
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
            : state.dashboard.settings.staging_dir;
        const listing = state.storage;
        const query = state.storageQuery.toLowerCase();
        const entries = (listing?.entries || []).filter((entry) =>
            entry.name.toLowerCase().includes(query)
        );
        const pathParts = state.storagePath ? state.storagePath.split("/") : [];
        const breadcrumbs = [
            `<button data-storage-path="">${kind === "backup" ? "Backup" : "Realtime"}</button>`,
            ...pathParts.map((part, index) => {
                const path = pathParts.slice(0, index + 1).join("/");
                return `<span>›</span><button data-storage-path="${escapeHtml(path)}">${escapeHtml(part)}</button>`;
            }),
        ].join("");
        return `<div class="grid storage-layout explorer-layout">
            <article class="panel storage-explorer">
                <div class="panel-heading">
                    <div><p class="eyebrow">FILE EXPLORER</p><h2>${kind === "backup" ? "File hasil backup" : "Data realtime"}</h2>
                        <p class="muted">${kind === "backup" ? "Jelajahi, download, atau upload arsip pada folder tujuan." : "Jelajahi dan download data hasil sinkronisasi terbaru."}</p></div>
                    <div class="toolbar storage-toolbar">
                        <button class="button ghost" data-action="storage-refresh"><span>↻</span>Refresh</button>
                        ${kind === "backup" ? `
                        <label class="button primary upload-button"><span>⇧</span>Upload .7z
                            <input id="storage-upload" type="file" accept=".7z,application/x-7z-compressed">
                        </label>` : ""}
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
                                <span>${entry.type === "directory" ? "Folder" : bytes(entry.size)}</span>
                                <span>${dateTime(entry.modified_at)}</span>
                                <span class="file-actions">${entry.type === "file" ? `
                                    <a class="row-button download-button"
                                        href="api.php?action=${kind}_download&path=${encodeURIComponent(entry.path)}"
                                        download="${escapeHtml(entry.name)}" title="Download ${escapeHtml(entry.name)}"
                                        aria-label="Download ${escapeHtml(entry.name)}">
                                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M12 3v11m0 0 4-4m-4 4-4-4M5 18v2h14v-2"/>
                                            </svg>
                                        </a>` : `
                                    <button class="row-button" data-storage-path="${escapeHtml(entry.path)}"
                                        aria-label="Buka ${escapeHtml(entry.name)}">→</button>`}</span>
                            </div>`).join("")}
                    </div>
                ` : `
                    <div class="empty"><strong>${query ? "Tidak ada hasil" : "Folder ini kosong"}</strong>
                        ${query ? "Coba kata pencarian lain." : kind === "backup" ? "Upload file .7z atau buka folder lain." : "Belum ada data realtime hasil sinkronisasi."}</div>
                `}
                <footer class="storage-foot">
                    <span>${entries.length} item ditampilkan</span>
                    <code>${escapeHtml(listing?.root || rootPath)}${state.storagePath ? `/${escapeHtml(state.storagePath)}` : ""}</code>
                </footer>
            </article>
        </div>`;
    }

    function pathCheckMarkup(kind, result = null) {
        const label = kind === "realtime" ? "Realtime" : "Backup";
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

    function renderSettings() {
        const s = state.dashboard.settings;
        const sshConnected = s.ssh_connected === true;
        return `<div class="grid settings-grid">
            <form class="panel form" data-form="settings-ssh"><div class="panel-heading"><div><p class="eyebrow">KONEKSI SUMBER</p><h2>SSH & RSYNC</h2></div></div>
                <div class="form-grid">
                    <label>Host sumber<input name="remote_host" value="${escapeHtml(s.remote_host)}" placeholder="192.168.1.1"></label>
                    <label>Port SSH<input name="remote_port" type="number" min="1" max="65535" value="${s.remote_port}"></label>
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
                    <label>Tipe key<select name="ssh_key_type">
                        <option value="rsa4096" ${s.ssh_key_type === "rsa4096" ? "selected" : ""}>RSA 4096 bit</option>
                        <option value="ed25519" ${s.ssh_key_type === "ed25519" ? "selected" : ""}>Ed25519</option>
                    </select></label>
                    <label>Komentar key<input name="ssh_key_comment" maxlength="128" value="${escapeHtml(s.ssh_key_comment)}" placeholder="Jeriyant-Key-RSA"></label>
                    <label class="span-2">Private key<input name="ssh_key_path" value="${escapeHtml(s.ssh_key_path)}">
                        <small>Dikelola pada folder data aplikasi agar dapat dibaca worker dengan aman; bukan di /root.</small></label>
                    <div class="ssh-tools span-2">
                        <button class="button ${sshConnected ? "danger ssh-disconnect" : "ssh-setup"}" type="button"
                            data-action="${sshConnected ? "ssh-disconnect" : "ssh-connect"}">
                            <span>${sshConnected ? "×" : "⇥"}</span>${sshConnected ? "Disconnect" : "Connect"}
                        </button>
                        <button class="button ssh-test" type="button" data-action="ssh-test"><span>✓</span>Tes koneksi</button>
                        <small>${sshConnected
                            ? `Terhubung ke ${escapeHtml(s.ssh_connected_target || `${s.remote_user}@${s.remote_host}`)}. Disconnect mencabut key remote dan menghapus key lokal.`
                            : "Connect membuat key, memasangnya ke server sumber, lalu menguji login tanpa password."}</small>
                    </div>
                </div>
            </form>
            <form class="panel form" data-form="settings-backup"><div class="panel-heading"><div><p class="eyebrow">HASIL BACKUP</p><h2>Lokasi & Penamaan</h2></div></div>
                <div class="form-grid">
                    <label class="span-2">Folder tujuan<input name="backup_dir" value="${escapeHtml(s.backup_dir)}"></label>
                    <label class="span-2">Template nama file<input name="filename_template" value="${escapeHtml(s.filename_template)}">
                        <small>Gunakan {date}, {time}, dan {name}.</small></label>
                    <label>Kompresi 7z<select name="compression_level">${[0,1,3,5,7,9].map((level) => `<option value="${level}" ${Number(s.compression_level) === level ? "selected" : ""}>Level ${level}</option>`).join("")}</select></label>
                    <label>Minimum ruang kosong<input name="minimum_free_bytes" type="number" min="0" value="${s.minimum_free_bytes}"></label>
                    <label>Zona waktu<input name="timezone" value="${escapeHtml(s.timezone)}"></label>
                    <div class="span-2">${pathCheckMarkup("backup", state.pathChecks.backup)}</div>
                    <div class="path-panel-actions span-2">
                        <button class="button path-test" type="button" data-action="test-path" data-path-kind="backup"><span>✓</span>Tes akses folder</button>
                        <button class="button primary" type="submit"><span>✓</span>Simpan pengaturan backup</button>
                    </div>
                </div>
            </form>
            <form class="panel form settings-realtime-panel" data-form="settings-realtime"><div class="panel-heading"><div><p class="eyebrow">DATA REALTIME</p><h2>Tujuan RSYNC</h2></div></div>
                <div class="form-grid">
                    <label class="span-2">Folder data realtime<input name="staging_dir" value="${escapeHtml(s.staging_dir)}">
                        <small>Data terbaru hasil sinkronisasi disimpan di sini dan menjadi sumber pembuatan backup.</small></label>
                    <div class="span-2">${pathCheckMarkup("realtime", state.pathChecks.realtime)}</div>
                    <div class="path-panel-actions span-2">
                        <button class="button path-test" type="button" data-action="test-path" data-path-kind="realtime"><span>✓</span>Tes akses folder</button>
                        <button class="button primary" type="submit"><span>✓</span>Simpan pengaturan realtime</button>
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

    function renderAbout() {
        const s = state.dashboard.settings;
        return `<div class="grid about-grid">
            <article class="panel about-hero">
                <div class="brand"><span class="brand-mark">J</span><span class="brand-copy"><strong>J-BACKUP</strong><small>Server data safety</small></span></div>
                <p class="eyebrow">TENTANG APLIKASI</p>
                <h2>Backup universal berbasis PHP</h2>
                <p class="muted">Sinkronisasi sumber melalui SSH/rsync, kompresi 7z terjadwal, verifikasi hasil, dan file explorer terintegrasi.</p>
                <span class="tag">Versi ${escapeHtml(state.dashboard.version)}</span>
            </article>
            <form class="panel form" data-form="settings-about">
                <div class="panel-heading"><div><p class="eyebrow">PEMBARUAN</p><h2>GitHub repository</h2></div></div>
                <label>Repository<input name="github_repository" value="${escapeHtml(s.github_repository)}" placeholder="owner/repository">
                    <small>Format owner/repository, misalnya jeriyant/j-backup.</small></label>
                <div class="about-actions">
                    <button class="button ghost" type="button" data-action="check-update"><span>↻</span>Cek pembaruan</button>
                    <button class="button primary" type="submit"><span>✓</span>Simpan repository</button>
                </div>
            </form>
            <article class="panel developer-card">
                <p class="eyebrow">TENTANG PENGEMBANG</p>
                <div class="developer-heading">
                    <span class="brand-mark">J</span>
                    <div><h2>JERIYANT - BARAMCITY</h2>
                        <p>Seorang Penikmat Teknologi Kelas Berat</p></div>
                </div>
                <p class="developer-location">Laboratorium Uji Teknis Berbasi di Suatu Daerah Terdalam, Terdepan, dan Terluar di Kalimantan Barat</p>
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
                        minlength="3" maxlength="64" autocomplete="username" required>
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
            <p class="muted">Masukkan satu path folder remote per baris. Alias opsional dapat ditulis sebagai <code>alias=/path/folder</code>.</p>
            <form class="form" data-form="${source ? "source-edit" : "source-add"}">
                ${source ? `<input type="hidden" name="id" value="${source.id}">` : ""}
                <label>Nama sumber<input name="name" value="${escapeHtml(source?.name || "")}" placeholder="JERIYANT" maxlength="128" required></label>
                <label>Mode arsip<select name="archive_mode">
                    <option value="combined" ${source?.archive_mode === "separate" ? "" : "selected"}>Gabungkan menjadi satu file 7z</option>
                    <option value="separate" ${source?.archive_mode === "separate" ? "selected" : ""}>Satu file 7z untuk setiap path</option>
                </select></label>
                <label>Subfolder hasil (opsional)<input name="output_subdirectory" value="${escapeHtml(source?.output_subdirectory || "")}" placeholder="Kosong"></label>
                <label>Path sumber<textarea name="paths" rows="8" placeholder="/var/lib/mysql/JERIYANT&#10;/var/lib/mysql/JERIYANT_sys&#10;/var/lib/mysql/JERIYANT_sakep" required>${escapeHtml(paths)}</textarea>
                    <small>Folder dapat berasal dari database, website, konfigurasi, dokumen, atau direktori lainnya.</small></label>
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
                    <li><strong>mode_arsip:</strong> isi <code>gabung</code> atau <code>terpisah</code>.</li>
                    <li>Untuk beberapa path, buat baris baru di dalam sel Excel dengan <code>Alt+Enter</code>. Tanda <code>|</code> juga didukung.</li>
                    <li>Alias path dapat ditulis sebagai <code>alias=/path/folder</code>.</li>
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

    function manualDialog() {
        showModal(`<p class="eyebrow">EKSEKUSI MANUAL</p><h2>Jalankan pekerjaan sekarang</h2>
            <p class="muted">${state.selected.size ? `${state.selected.size} sumber terpilih akan diproses.` : "Semua sumber aktif akan diproses berurutan."}</p>
            <div class="choices"><button class="choice" data-run="sync"><i>↕</i><span><strong>Sinkronisasi</strong><small>Tarik folder remote ke data realtime</small></span></button>
                <button class="choice" data-run="backup"><i>7Z</i><span><strong>Buat backup</strong><small>Kompres & verifikasi di tujuan</small></span></button></div>`);
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

    function jobDialog(id) {
        const job = state.dashboard.jobs.find((item) => item.id === id);
        if (!job) return;
        showModal(`<p class="eyebrow">DETAIL PEKERJAAN</p><h2>${escapeHtml(job.source_name)}</h2>
            <div class="job-meta"><span class="status status-${escapeHtml(job.status)}">${statusText(job.status)}</span>
                <span>${job.type === "sync" ? "Sinkronisasi" : "Backup 7z"}</span><span>${dateTime(job.queued_at)}</span></div>
            ${job.output_path ? `<p class="path">${escapeHtml(job.output_path)}</p>` : ""}
            ${job.outputs?.length ? `<div class="job-outputs">${job.outputs.map((output) => `
                <div class="detail-line"><span>${escapeHtml(output.source_alias || "Arsip gabungan")}</span>
                    <code>${escapeHtml(output.archive_path)}</code></div>`).join("")}</div>` : ""}
            ${job.error ? `<p class="error-box">${escapeHtml(job.error)}</p>` : ""}
            <div class="detail-line"><span>Verifikasi tujuan</span><strong>${escapeHtml(job.verification || "Belum tersedia")}</strong></div>
            ${job.checksum ? `<div class="detail-line"><span>SHA-256</span><code>${escapeHtml(job.checksum)}</code></div>` : ""}
            <pre class="log">${escapeHtml(job.log || "Log pekerjaan belum tersedia.")}</pre>
            ${["queued","running"].includes(job.status) ? `<button class="button danger wide" data-cancel-job="${escapeHtml(job.id)}"><span>■</span>Batalkan pekerjaan</button>` : ""}`, true);
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
                <span>■</span>Ya, batalkan semua pekerjaan
            </button>
        `);
    }

    function clearJobHistoryDialog() {
        showModal(`
            <p class="eyebrow">HAPUS RIWAYAT</p>
            <h2>Hapus seluruh riwayat pekerjaan?</h2>
            <p class="muted">Pekerjaan yang sudah selesai, gagal, atau dibatalkan akan
                dihapus permanen. Antrean dan pekerjaan yang masih berjalan tetap dipertahankan.</p>
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
                && !workerIsReady(response.worker_heartbeat)
            ) {
                throw new Error(
                    "Worker root tidak aktif. Periksa j-backup-worker.timer dan pastikan worker memakai database aplikasi yang sama."
                );
            }
        }
        throw new Error(
            "Worker belum menyelesaikan pengujian folder. Periksa status timer."
        );
    }

    async function runPathCheck(kind) {
        const formName = kind === "realtime"
            ? "settings-realtime"
            : "settings-backup";
        const inputName = kind === "realtime" ? "staging_dir" : "backup_dir";
        const form = document.querySelector(`[data-form="${formName}"]`);
        const path = String(form?.elements?.[inputName]?.value || "").trim();
        if (!path.startsWith("/")) {
            throw new Error("Folder harus berupa path absolut Linux.");
        }

        showModal(`
            <div class="ssh-wait"><i></i>
                <p class="eyebrow">MENUNGGU WORKER</p>
                <h2>Menguji akses folder</h2>
                <p class="path">${escapeHtml(path)}</p>
                <p class="muted">Worker akan membuat lalu menghapus file uji kecil. Folder dan izin tidak akan diubah.</p>
            </div>
        `, true);
        const response = await api("path_task_create", {
            method: "POST",
            body: { kind, path },
        });
        const task = await waitForPathTask(response.task.id);
        const result = task.result;
        state.pathChecks[kind] = result;

        const current = document.querySelector(`#path-check-${kind}`);
        if (current) {
            current.outerHTML = pathCheckMarkup(kind, result);
        }
        showModal(pathCheckDetailMarkup(result), true);
        toast(result.ready
            ? "Folder siap digunakan oleh worker."
            : "Folder belum dapat digunakan oleh worker.");
    }

    function currentSshSettings() {
        const form = document.querySelector('[data-form="settings-ssh"]');
        if (!form) throw new Error("Form pengaturan tidak ditemukan.");
        const values = new FormData(form);
        return {
            remote_host: String(values.get("remote_host") || "").trim(),
            remote_port: Number(values.get("remote_port") || 22),
            remote_user: String(values.get("remote_user") || "").trim(),
            ssh_key_path: String(values.get("ssh_key_path") || "").trim(),
            ssh_key_type: String(values.get("ssh_key_type") || "ed25519"),
            ssh_key_comment: String(values.get("ssh_key_comment") || "J-BACKUP-Key").trim(),
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
                showModal(`
                    <p class="eyebrow">KONEKSI SSH DIPUTUS</p>
                    <h2>Disconnect berhasil</h2>
                    <p class="muted">Public key J-BACKUP telah dicabut dari server sumber.</p>
                    <div class="connection-success"><i>✓</i><span><strong>${escapeHtml(task.result.target)}</strong>
                        <small>Key lokal, known_hosts, dan password tersimpan sudah dihapus</small></span></div>
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
                } else if (["backup", "realtime"].includes(state.tab) && !state.storageLoading) {
                    state.explorerKind = state.tab;
                    state.storage = null;
                    state.storagePath = "";
                    state.storageQuery = "";
                    state.storageError = "";
                    await loadStorage(state.storagePath);
                } else if (state.tab === "settings") {
                    await revealStoredSshPassword();
                }
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
            } else if (target.dataset.action === "add") sourceDialog();
            else if (target.dataset.action === "manual") manualDialog();
            else if (target.dataset.action === "test-path") {
                await runPathCheck(target.dataset.pathKind);
            }
            else if (target.dataset.action === "close-modal") closeModal();
            else if (target.dataset.action === "select-all") {
                const filtered = state.dashboard.sources.filter((item) => item.name.toLowerCase().includes(state.query.toLowerCase()));
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
                const result = await api("jobs_history_clear", {
                    method: "POST",
                    body: {},
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
                const result = await api("update_check");
                toast(result.update_available
                    ? `Versi ${result.latest_version} tersedia.`
                    : `J-BACKUP ${result.current_version} sudah terbaru.`);
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
            } else if (target.dataset.action === "copy-public-key") {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(state.publicKey);
                } else {
                    const publicKey = document.querySelector("#ssh-public-key");
                    publicKey?.select();
                    document.execCommand("copy");
                }
                toast("Public key disalin.");
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
                    body: { id: Number(target.dataset.toggleId), enabled: target.checked },
                });
                await loadDashboard();
            } else if (target.dataset.scheduleEnabled) {
                await updateSchedule(target.dataset.scheduleEnabled, { enabled: target.checked });
            } else if (target.dataset.scheduleMode) {
                await updateSchedule(target.dataset.scheduleMode, { mode: target.value });
            } else if (target.dataset.scheduleInterval) {
                await updateSchedule(target.dataset.scheduleInterval, {
                    interval_value: Number(target.value),
                });
            } else if (target.dataset.scheduleTime) {
                await updateSchedule(target.dataset.scheduleTime, { time: target.value });
            } else if (target.name === "ssh_key_type") {
                const keyPath = document.querySelector('[name="ssh_key_path"]');
                if (keyPath) {
                    keyPath.value = keyPath.value.replace(
                        /\/id_(?:ed25519|rsa)$/,
                        target.value === "rsa4096" ? "/id_rsa" : "/id_ed25519"
                    );
                }
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
            } else if (kind.startsWith("settings-")) {
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
            if (!state.poller) {
                state.poller = setInterval(() => {
                    if (document.visibilityState === "visible" && state.mode === "ready") {
                        loadDashboard(false, true).then(() => {
                            const editing = document.querySelector("input:focus, textarea:focus, select:focus");
                            if (!modal.open && !editing && state.tab !== "settings") renderApp();
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
