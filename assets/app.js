(() => {
    "use strict";

const app = document.querySelector("#app");
    const modal = document.querySelector("#modal");
    const toastElement = document.querySelector("#toast");
    const days = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"];
    const navItems = [
        ["overview", "⌂", "Ringkasan"],
        ["databases", "▦", "Database"],
        ["history", "↺", "Riwayat"],
        ["schedules", "◷", "Jadwal"],
        ["storage", "▤", "Penyimpanan"],
        ["settings", "⚙", "Pengaturan"],
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

    async function api(action, { method = "GET", body, query = {} } = {}) {
        const parameters = new URLSearchParams({ action, ...query });
        const response = await fetch(`api.php?${parameters.toString()}`, {
            method,
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                ...(state.csrf ? { "X-CSRF-Token": state.csrf } : {}),
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
                        <label>Password<input name="password" type="password" minlength="6"
                            autocomplete="${setupRequired ? "new-password" : "current-password"}" required></label>
                        ${error ? `<p class="auth-error">${escapeHtml(error)}</p>` : ""}
                        <button class="button primary wide action-access" type="submit">
                            <span>→</span>${setupRequired ? "Buat akun & masuk" : "Masuk ke dashboard"}
                        </button>
                    </form>
                </section>
            </main>`;
    }

    async function loadDashboard(render = true) {
        state.dashboard = await api("dashboard");
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
        if (state.tab === "storage") renderApp();
        try {
            state.storage = await api("storage_list", { query: { path } });
            state.storagePath = state.storage.path;
        } finally {
            state.storageLoading = false;
            if (state.tab === "storage") renderApp();
        }
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

    function renderApp() {
        const dashboard = state.dashboard;
        if (!dashboard) return;
        state.mode = "ready";
        const workerReady = workerIsReady(dashboard.worker_heartbeat);
        const title = state.tab === "overview"
            ? "Kondisi backup hari ini"
            : navItems.find(([id]) => id === state.tab)?.[2] || "J-BACKUP";
        app.innerHTML = `
            <div class="app-shell">
                <aside class="sidebar">
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
                            <strong>${workerReady ? "Worker siap" : "Worker tidak terhubung"}</strong>
                            <small>Versi ${escapeHtml(dashboard.version)}</small></span></div>
                        <button class="icon-button" data-action="theme" aria-label="Ganti tema">◐</button>
                    </div>
                </aside>
                <main class="main">
                    <header class="topbar">
                        <div><p class="eyebrow">${escapeHtml(title.toUpperCase())}</p><h1>${escapeHtml(title)}</h1></div>
                        <div class="top-actions">
                            <button class="user-chip logout-chip" data-action="logout" title="Keluar"><i>${escapeHtml(dashboard.user.username.slice(0, 1).toUpperCase())}</i>${escapeHtml(dashboard.user.username)}</button>
                            <button class="button ghost" data-action="manual"><span>▶</span>Jalankan manual</button>
                        </div>
                    </header>
                    <section id="view">${renderView()}</section>
                </main>
            </div>`;
    }

    function renderView() {
        if (state.tab === "databases") return renderDatabases();
        if (state.tab === "history") return renderHistory();
        if (state.tab === "schedules") return renderSchedules();
        if (state.tab === "storage") return renderStorage();
        if (state.tab === "settings") return renderSettings();
        return renderOverview();
    }

    function renderOverview() {
        const d = state.dashboard;
        const active = d.active_job;
        const failures = d.jobs.filter((job) => job.status === "failed").length;
        const successes = d.jobs.filter((job) => job.status === "success").length;
        const next = d.schedules.find((schedule) => schedule.enabled);
        const headline = active
            ? `${active.type === "sync" ? "Sinkronisasi" : "Backup"} sedang berjalan`
            : failures ? "Ada pekerjaan yang perlu diperiksa" : "Semua sistem berjalan normal";
        const detail = active
            ? `${active.database_name} sedang diproses. ${d.queue_count} pekerjaan menunggu.`
            : next ? `${next.type === "sync" ? "Sinkronisasi" : "Backup"} aktif: ${scheduleText(next).toLowerCase()}.`
                : "Aktifkan jadwal atau jalankan pekerjaan secara manual.";
        return `
            <div class="grid overview-grid">
                <article class="hero">
                    <div><p class="eyebrow">STATUS SISTEM</p><h2>${escapeHtml(headline)}</h2><p>${escapeHtml(detail)}</p></div>
                    <div class="health"><span>${active ? "RUN" : failures ? "!" : "OK"}</span></div>
                </article>
                <div class="metrics">
                    <article class="metric"><p>Database aktif</p><strong>${d.databases.filter((item) => item.enabled).length}</strong><small>dari ${d.databases.length} terdaftar</small></article>
                    <article class="metric"><p>Job berhasil</p><strong>${successes}</strong><small>dari ${d.jobs.length} riwayat terakhir</small></article>
                    <article class="metric"><p>Ruang tersedia</p><strong>${bytes(d.disk.free)}</strong><small>${d.disk.used_percent}% disk terpakai</small></article>
                </div>
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
                <article class="panel recent-panel">
                    <div class="panel-heading"><div><p class="eyebrow">AKTIVITAS</p><h2>Riwayat terbaru</h2></div>
                        <button class="text-button" data-tab="history">Semua riwayat →</button></div>
                    ${jobTable(d.jobs.slice(0, 5))}
                </article>
            </div>`;
    }

    function renderDatabases() {
        const d = state.dashboard;
        const filtered = d.databases.filter((item) => item.name.toLowerCase().includes(state.query.toLowerCase()));
        return `
            <article class="panel full">
                <div class="panel-heading">
                    <div><p class="eyebrow">SUMBER DATA</p><h2>Daftar database</h2>
                        <p class="muted">Semua nama dimasukkan dari aplikasi; tidak ada daftar bawaan.</p></div>
                    <div class="toolbar"><button class="button ghost" data-action="import"><span>⇩</span>Impor daftar</button>
                        <button class="button primary" data-action="add"><span>＋</span>Tambah database</button></div>
                </div>
                <div class="tools">
                    <label class="search"><input id="database-search" value="${escapeHtml(state.query)}" placeholder="Cari nama database…" aria-label="Cari database"></label>
                    <span>${state.selected.size} dipilih</span>
                    <button class="text-button" data-action="select-all">${state.selected.size === filtered.length && filtered.length ? "Batalkan pilihan" : "Pilih semua"}</button>
                </div>
                <div>
                    ${filtered.map((item) => `
                        <article class="database-row ${item.enabled ? "" : "off"}">
                            <input type="checkbox" data-select-id="${item.id}" ${state.selected.has(item.id) ? "checked" : ""} aria-label="Pilih ${escapeHtml(item.name)}">
                            <div class="database-icon">DB</div>
                            <div class="database-copy"><strong>${escapeHtml(item.name)}</strong>
                                <small>${escapeHtml(item.include_sys ? `${item.name} + ${item.name}_sys` : item.name)}</small></div>
                            <span class="tag">${item.enabled ? "Aktif" : "Nonaktif"}</span>
                            <label class="switch"><input type="checkbox" data-toggle-id="${item.id}" ${item.enabled ? "checked" : ""}><span></span></label>
                            <button class="row-button" data-delete-id="${item.id}" aria-label="Hapus ${escapeHtml(item.name)}">×</button>
                        </article>`).join("") || `<div class="empty"><strong>Belum ada database</strong>Tambahkan satu nama atau impor banyak nama sekaligus.</div>`}
                </div>
                ${state.selected.size ? `
                    <div class="selection-bar"><strong>${state.selected.size} database dipilih</strong><span>Jalankan hanya untuk pilihan ini.</span>
                        <button class="button ghost" data-run="sync"><span>↕</span>Sinkronkan</button>
                        <button class="button primary" data-run="backup"><span>7Z</span>Buat backup</button></div>` : ""}
            </article>`;
    }

    function renderHistory() {
        return `<article class="panel full"><div class="panel-heading"><div><p class="eyebrow">AUDIT PEKERJAAN</p>
            <h2>Riwayat sinkronisasi & backup</h2></div><span class="tag">${state.dashboard.jobs.length} pekerjaan</span></div>
            ${jobTable(state.dashboard.jobs)}</article>`;
    }

    function jobTable(jobs) {
        if (!jobs.length) return `<div class="empty"><strong>Belum ada pekerjaan</strong>Riwayat akan muncul di sini.</div>`;
        return `<div class="table-wrap"><table><thead><tr><th>Database</th><th>Proses</th><th>Status</th><th>Ukuran</th><th>Waktu</th><th></th></tr></thead>
            <tbody>${jobs.map((job) => `<tr>
                <td><strong>${escapeHtml(job.database_name)}</strong><small>${escapeHtml(job.output_path || job.error || "Menunggu proses")}</small></td>
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
                <p class="muted">${schedule.type === "sync" ? "Menyalin folder remote ke staging lokal." : "Membuat dan memverifikasi arsip di folder tujuan."}</p>
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
        const disk = state.dashboard.disk;
        const listing = state.storage;
        const query = state.storageQuery.toLowerCase();
        const entries = (listing?.entries || []).filter((entry) =>
            entry.name.toLowerCase().includes(query)
        );
        const pathParts = state.storagePath ? state.storagePath.split("/") : [];
        const breadcrumbs = [
            `<button data-storage-path="">Backup</button>`,
            ...pathParts.map((part, index) => {
                const path = pathParts.slice(0, index + 1).join("/");
                return `<span>›</span><button data-storage-path="${escapeHtml(path)}">${escapeHtml(part)}</button>`;
            }),
        ].join("");
        return `<div class="grid storage-layout">
            <article class="panel capacity"><div class="panel-heading"><div><p class="eyebrow">DISK TUJUAN</p>
                <h2>${disk.available ? "Storage terhubung" : "Storage tidak tersedia"}</h2></div>
                <span class="status ${disk.available ? "status-success" : "status-failed"}">${disk.available ? "Online" : "Periksa"}</span></div>
                <p class="path">${escapeHtml(disk.path)}</p>
                <div class="capacity-number"><strong>${bytes(disk.used)}</strong><span>dari ${bytes(disk.total)}</span></div>
                <div class="capacity-track"><i style="width:${Math.min(disk.used_percent, 100)}%"></i></div>
                <div class="capacity-foot"><span>${disk.used_percent}% terpakai</span><strong>${bytes(disk.free)} tersedia</strong></div>
            </article>
            <article class="panel"><p class="eyebrow">VERIFIKASI TUJUAN</p><h2>Syarat backup sukses</h2>
                <ol class="checks"><li><i>1</i>Folder tanggal berhasil dibuat</li><li><i>2</i>File sementara memiliki ukuran valid</li>
                    <li><i>3</i>Arsip lulus pengujian 7z t</li><li><i>4</i>File final tersedia di folder tujuan</li></ol>
            </article>
            <article class="panel storage-explorer">
                <div class="panel-heading">
                    <div><p class="eyebrow">FILE EXPLORER</p><h2>File hasil backup</h2>
                        <p class="muted">Jelajahi, download, atau upload arsip pada folder tujuan.</p></div>
                    <div class="toolbar storage-toolbar">
                        <button class="button ghost" data-action="storage-refresh"><span>↻</span>Refresh</button>
                        <label class="button primary upload-button"><span>⇧</span>Upload .7z
                            <input id="storage-upload" type="file" accept=".7z,application/x-7z-compressed">
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
                                        href="api.php?action=storage_download&path=${encodeURIComponent(entry.path)}"
                                        download="${escapeHtml(entry.name)}" title="Download ${escapeHtml(entry.name)}"
                                        aria-label="Download ${escapeHtml(entry.name)}">⇩</a>` : `
                                    <button class="row-button" data-storage-path="${escapeHtml(entry.path)}"
                                        aria-label="Buka ${escapeHtml(entry.name)}">→</button>`}</span>
                            </div>`).join("")}
                    </div>
                ` : `
                    <div class="empty"><strong>${query ? "Tidak ada hasil" : "Folder ini kosong"}</strong>
                        ${query ? "Coba kata pencarian lain." : "Upload file .7z atau buka folder lain."}</div>
                `}
                <footer class="storage-foot">
                    <span>${entries.length} item ditampilkan</span>
                    <code>${escapeHtml(listing?.root || disk.path)}${state.storagePath ? `/${escapeHtml(state.storagePath)}` : ""}</code>
                </footer>
            </article>
        </div>`;
    }

    function renderSettings() {
        const s = state.dashboard.settings;
        return `<form class="grid settings-grid form" data-form="settings">
            <section class="panel"><div class="panel-heading"><div><p class="eyebrow">KONEKSI SUMBER</p><h2>SSH & rsync</h2></div></div>
                <div class="form-grid">
                    <label>Host sumber<input name="remote_host" value="${escapeHtml(s.remote_host)}" placeholder="192.168.1.10"></label>
                    <label>Port SSH<input name="remote_port" type="number" min="1" max="65535" value="${s.remote_port}"></label>
                    <label>User SSH<input name="remote_user" value="${escapeHtml(s.remote_user)}"></label>
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
                    <label class="span-2">Private key<input name="ssh_key_path" value="${escapeHtml(s.ssh_key_path)}"></label>
                    <label class="span-2">Root database remote<input name="remote_root" value="${escapeHtml(s.remote_root)}"></label>
                    <label class="span-2">Folder staging lokal<input name="staging_dir" value="${escapeHtml(s.staging_dir)}"></label>
                    <div class="ssh-tools span-2">
                        <button class="button ssh-setup" type="button" data-action="ssh-setup"><span>⇥</span>Setup koneksi</button>
                        <button class="button ssh-test" type="button" data-action="ssh-test"><span>✓</span>Tes koneksi</button>
                        <small>Password disimpan terenkripsi dan otomatis dipakai saat Setup koneksi. Setelah key aktif, gunakan Tes koneksi.</small>
                    </div>
                </div>
            </section>
            <section class="panel"><div class="panel-heading"><div><p class="eyebrow">HASIL BACKUP</p><h2>Lokasi & penamaan</h2></div></div>
                <div class="form-grid">
                    <label class="span-2">Folder tujuan<input name="backup_dir" value="${escapeHtml(s.backup_dir)}"></label>
                    <label class="span-2">Template nama file<input name="filename_template" value="${escapeHtml(s.filename_template)}">
                        <small>Gunakan {date}, {time}, dan {name}.</small></label>
                    <label>Kompresi 7z<select name="compression_level">${[0,1,3,5,7,9].map((level) => `<option value="${level}" ${Number(s.compression_level) === level ? "selected" : ""}>Level ${level}</option>`).join("")}</select></label>
                    <label>Minimum ruang kosong<input name="minimum_free_bytes" type="number" min="0" value="${s.minimum_free_bytes}"></label>
                    <label>Zona waktu<input name="timezone" value="${escapeHtml(s.timezone)}"></label>
                    <label>GitHub repository<input name="github_repository" value="${escapeHtml(s.github_repository)}" placeholder="owner/repository"></label>
                </div>
            </section>
            <div class="settings-actions"><button class="button ghost" type="button" data-action="check-update"><span>↻</span>Cek pembaruan</button>
                <button class="button primary action-save" type="submit"><span>✓</span>Simpan semua pengaturan</button></div>
        </form>`;
    }

    function databaseDialog() {
        showModal(`<p class="eyebrow">DATABASE BARU</p><h2>Tambahkan sumber backup</h2>
            <p class="muted">Nama digunakan untuk folder remote, staging, dan file output.</p>
            <form class="form" data-form="database-add">
                <label>Nama database<input name="name" placeholder="cusj_airupas" required></label>
                <label><span><input name="include_sys" type="checkbox" checked> Sertakan folder pasangan _sys</span></label>
                <button class="button primary wide action-add-dialog"><span>＋</span>Tambahkan database</button>
            </form>`);
    }

    function importDialog() {
        showModal(`<p class="eyebrow">IMPOR MASSAL</p><h2>Tempel daftar database</h2>
            <p class="muted">Pisahkan setiap nama dengan baris baru, koma, atau spasi.</p>
            <form class="form" data-form="database-import">
                <label>Daftar nama<textarea name="names" rows="8" placeholder="cusj_airupas&#10;cusj_anjungan&#10;cusj_badau" required></textarea></label>
                <label><span><input name="include_sys" type="checkbox" checked> Sertakan folder pasangan _sys</span></label>
                <button class="button primary wide action-import-dialog"><span>⇩</span>Impor daftar</button>
            </form>`);
    }

    function manualDialog() {
        showModal(`<p class="eyebrow">EKSEKUSI MANUAL</p><h2>Jalankan pekerjaan sekarang</h2>
            <p class="muted">${state.selected.size ? `${state.selected.size} database terpilih akan diproses.` : "Semua database aktif akan diproses berurutan."}</p>
            <div class="choices"><button class="choice" data-run="sync"><i>↕</i><span><strong>Sinkronisasi</strong><small>Tarik folder remote ke staging</small></span></button>
                <button class="choice" data-run="backup"><i>7Z</i><span><strong>Buat backup</strong><small>Kompres & verifikasi di tujuan</small></span></button></div>`);
    }

    function jobDialog(id) {
        const job = state.dashboard.jobs.find((item) => item.id === id);
        if (!job) return;
        showModal(`<p class="eyebrow">DETAIL PEKERJAAN</p><h2>${escapeHtml(job.database_name)}</h2>
            <div class="job-meta"><span class="status status-${escapeHtml(job.status)}">${statusText(job.status)}</span>
                <span>${job.type === "sync" ? "Sinkronisasi" : "Backup 7z"}</span><span>${dateTime(job.queued_at)}</span></div>
            ${job.output_path ? `<p class="path">${escapeHtml(job.output_path)}</p>` : ""}
            ${job.error ? `<p class="error-box">${escapeHtml(job.error)}</p>` : ""}
            <div class="detail-line"><span>Verifikasi tujuan</span><strong>${escapeHtml(job.verification || "Belum tersedia")}</strong></div>
            ${job.checksum ? `<div class="detail-line"><span>SHA-256</span><code>${escapeHtml(job.checksum)}</code></div>` : ""}
            <pre class="log">${escapeHtml(job.log || "Log pekerjaan belum tersedia.")}</pre>
            ${["queued","running"].includes(job.status) ? `<button class="button danger wide" data-cancel-job="${escapeHtml(job.id)}"><span>■</span>Batalkan pekerjaan</button>` : ""}`, true);
    }

    async function startJobs(type) {
        const result = await api("jobs_create", {
            method: "POST",
            body: { type, database_ids: [...state.selected] },
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

    function currentSshSettings() {
        const form = document.querySelector('[data-form="settings"]');
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
            } else {
                showModal(`
                    <p class="eyebrow">TES KONEKSI</p>
                    <h2>Koneksi SSH berhasil</h2>
                    <p class="muted">Autentikasi private key diterima oleh server sumber.</p>
                    <div class="connection-success"><i>✓</i><span><strong>${escapeHtml(task.result.target)}</strong>
                        <small>${task.result.latency_ms ? `${task.result.latency_ms} ms` : "Terhubung"}</small></span></div>
                    ${terminal()}
                `, true);
            }
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
                state.tab = target.dataset.tab;
                renderApp();
                if (state.tab === "storage" && !state.storageLoading) {
                    await loadStorage(state.storagePath);
                } else if (state.tab === "settings") {
                    await revealStoredSshPassword();
                }
            } else if (target.dataset.storagePath !== undefined) {
                state.storageQuery = "";
                await loadStorage(target.dataset.storagePath);
            } else if (target.dataset.action === "storage-refresh") {
                await loadStorage();
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
            } else if (target.dataset.action === "logout") {
                await api("logout", { method: "POST", body: {} });
                state.dashboard = null;
                state.selected.clear();
                await boot();
            } else if (target.dataset.action === "add") databaseDialog();
            else if (target.dataset.action === "import") importDialog();
            else if (target.dataset.action === "manual") manualDialog();
            else if (target.dataset.action === "close-modal") closeModal();
            else if (target.dataset.action === "select-all") {
                const filtered = state.dashboard.databases.filter((item) => item.name.toLowerCase().includes(state.query.toLowerCase()));
                state.selected = state.selected.size === filtered.length
                    ? new Set()
                    : new Set(filtered.map((item) => item.id));
                renderApp();
            } else if (target.dataset.run) await startJobs(target.dataset.run);
            else if (target.dataset.deleteId) {
                const id = Number(target.dataset.deleteId);
                const item = state.dashboard.databases.find((database) => database.id === id);
                if (confirm(`Hapus ${item.name} dari daftar?`)) {
                    await api("database_delete", { method: "POST", body: { id } });
                    state.selected.delete(id);
                    toast("Database dihapus.");
                    await loadDashboard();
                }
            } else if (target.dataset.jobId) jobDialog(target.dataset.jobId);
            else if (target.dataset.cancelJob) {
                await api("job_cancel", { method: "POST", body: { id: target.dataset.cancelJob } });
                closeModal();
                toast("Permintaan pembatalan dikirim.");
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
            } else if (target.dataset.action === "ssh-setup") {
                await runSshTool("generate_key", true);
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
                await api("database_update", {
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
        if (event.target.id === "database-search") {
            state.query = event.target.value;
            const cursor = event.target.selectionStart;
            renderApp();
            const next = document.querySelector("#database-search");
            next?.focus();
            next?.setSelectionRange(cursor, cursor);
        } else if (event.target.id === "storage-search") {
            state.storageQuery = event.target.value;
            const cursor = event.target.selectionStart;
            renderApp();
            const next = document.querySelector("#storage-search");
            next?.focus();
            next?.setSelectionRange(cursor, cursor);
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
            } else if (kind === "database-add") {
                data.include_sys = form.elements.include_sys.checked;
                await api("database_create", { method: "POST", body: data });
                closeModal();
                toast("Database berhasil ditambahkan.");
                await loadDashboard();
            } else if (kind === "database-import") {
                data.include_sys = form.elements.include_sys.checked;
                const result = await api("database_import", { method: "POST", body: data });
                closeModal();
                toast(`${result.result.inserted.length} database berhasil diimpor.`);
                await loadDashboard();
            } else if (kind === "settings") {
                await api("settings_update", { method: "POST", body: data });
                toast("Pengaturan berhasil disimpan.");
                await loadDashboard();
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
            await loadDashboard();
            if (!state.poller) {
                state.poller = setInterval(() => {
                    if (document.visibilityState === "visible" && state.mode === "ready") {
                        loadDashboard(false).then(() => {
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
    boot();
})();
