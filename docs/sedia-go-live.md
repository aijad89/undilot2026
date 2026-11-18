# Kesediaan Go-Live — undilot2026

*Penilaian 2026-11-16 pada commit `a152c14`. Kemas kini fail ini setiap kali
satu perkara selesai: tanda `[x]`, tulis tarikh dan commit.*

## Keputusan

**Belum sedia untuk go-live.** Ciri teras sudah dibina dan diuji (log masuk,
senarai cabutan dengan semakan zon → HQ, pelan lot, cabutan, pendedahan, QR,
slip PDF). Yang menghalang ialah data sebenar, persediaan pelayan, ujian manual
yang belum siap, dan 5 pepijat sederhana yang masih terbuka.

| Kategori | Jumlah | Status |
|---|---|---|
| 🔴 Penghalang go-live | 6 | 0 selesai |
| 🐞 Pepijat sederhana (K04–K10) | 7 | **7 selesai** (K04–K08 PR #1, K09 PR #2, K10 belum push) |
| 🟡 Patut dibuat | 5 | 0 selesai |
| 🟢 Selepas go-live | 18 pepijat rendah | — |

---

## 1. 🔴 Penghalang go-live

### B1. Data peserta sebenar belum dimuat
- [ ] Dapatkan CSV senarai peserta daripada pejabat
- [ ] Pastikan setiap baris ada `no_komuniti` **dan** `ic` (lihat B2) — tiada
      emel dalam projek ini
- [ ] `php artisan import:participants <fail.csv>` — semak laporan, tiada baris gagal

Lajur CSV: wajib `nama`, `ic`; pilihan `telefon`, `syarikat`, `zon`,
`kod_kraf`, `saiz_gerai`, `kategori_usahawan` (`Pengeluar atau Pedagang` / `Pemborong`),
`no_komuniti`, `alamat`. Seluruh fail
disemak dahulu — jika satu baris salah, tiada apa disimpan.

### B2. Peserta perlukan ID Komuniti + IC; tiada emel
**Dikemas kini 2026-11-17:** peserta kini log masuk dengan **ID Komuniti + IC**,
tanpa kata laluan dan tanpa emel (keputusan pemilik). Emel tidak lagi
diperlukan untuk log masuk.

- [ ] Setiap peserta dalam CSV ada `no_komuniti` **dan** `ic` — tanpa ID
      Komuniti, peserta **tidak boleh log masuk** (import memberi amaran).
- [ ] Pasukan menyemak semula keselamatan log masuk ID + IC (IC dicetak pada
      slip, bukan rahsia) — ditangguhkan atas arahan pemilik.

**Aplikasi tidak menghantar emel langsung** (keputusan pemilik 2026-11-17):
notifikasi dalam aplikasi sahaja, aliran lupa/reset kata laluan dibuang. Tiada
pelayan mel, ujian hantar atau rekod PTR diperlukan; biarkan `MAIL_MAILER=log`.

- [ ] Putuskan: bagaimana peserta tahu akaun mereka sudah wujud dan bila perlu
      log masuk? (SMS / taklimat / pengumuman pejabat — sistem tidak
      menghantar apa-apa sendiri; notifikasi hanya kelihatan selepas log masuk)

**Pegawai (admin) log masuk dengan nama pengguna + kata laluan; tiada emel
langsung dalam projek** (keputusan pemilik 2026-11-17, **sudah dibina**).
Akaun pegawai dicipta **baharu** dalam aplikasi (HQ Admins / State Admins) —
kata laluan aplikasi web lama tidak dibawa.

- [ ] Cipta akaun setiap pegawai negeri dan HQ di pelayan, serahkan nama
      pengguna + kata laluan kepada mereka sendiri
- [ ] `.env` pelayan: `ADMIN_SEED_USERNAME` (bukan lagi `ADMIN_SEED_EMAIL`)

### B3. Persediaan pelayan dan deploy
Senarai semak penuh: [`serahan-pembangun.md` seksyen 13](serahan-pembangun.md#13-deploy-ke-produksi).

- [ ] Maklumat pelayan: hos, laluan, pengguna, URL, subpath (`/undilotnew`?)
- [ ] HTTPS
- [ ] `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_DRIVER=database`,
      `QUEUE_CONNECTION=database`, `APP_URL` penuh termasuk subpath
- [ ] `php artisan storage:link` (tanpanya sepanduk acara hilang tanpa ralat)
- [ ] Penjadual dari `/etc/cron.d/undilot` (bukan crontab `www-data`)
- [ ] Pekerja baris gilir (systemd `queue:work database`) — tanpanya **tiada
      notifikasi dihantar**
- [ ] Sandaran pangkalan data automatik (harian) dan uji pulih sekali
- [ ] Tulis skrip deploy dan simpan prosedur dalam `serahan-pembangun.md`

### B4. Ujian manual baru 59% siap
Sumber: `docs/testing/undilot-test-manual.xlsx`.

| Modul | Kes | Lulus | Gagal | Belum jalan |
|---|---|---|---|---|
| Portal peserta | 9 | 0 | 0 | **9** |
| Senarai cabutan | 25 | 13 | 0 | 12 |
| Cabutan lot | 37 | 18 | 2 | 17 |
| Syarikat | 20 | 11 | 0 | 9 |
| Log masuk & kata laluan | 19 | 13 | 1 | 5 |
| **Keutamaan tinggi** | **82** | **51** | **1** | **30** |

- [ ] Kemas kini helaian untuk undilot2026: buang kes modul yang sudah dibuang
      (Join request, Reports, Announcements, Site content, Audit trail screen) —
      helaian ini ditulis untuk `undilotlook`
- [ ] Jalankan semua 30 kes keutamaan tinggi yang belum dijalankan
- [ ] Jalankan 9 kes portal peserta **pada telefon sebenar** (pengguna utama
      ialah usahawan berusia, di telefon)
- [ ] Uji semula kes yang gagal selepas K04–K10 dibaiki

### B5. Pepijat sederhana terbuka
Lihat seksyen 2. Semua K04–K08 mesti dibaiki sebelum go-live.

- [x] K04–K08 dibaiki 2026-11-16 (PR #1)
- [x] K09 dibaiki 2026-11-16 (belum merge)
- [x] K10 dibaiki 2026-11-16 (belum push)

### B6. Tamatkan peraturan "satu baseline migration" pada hari data sebenar dimasukkan
Sekarang perubahan skema dibuat dengan mengedit
`database/migrations/0001_01_02_000000_create_undilot_schema.php` dan
`migrate:fresh`. Selepas data sebenar wujud, cara itu **memadam data**.

- [ ] Pada hari go-live: kemas kini peraturan dalam `CLAUDE.md` — setiap
      perubahan skema selepas ini ialah migration baharu
- [ ] Beritahu semua pembangun

---

## 2. 🐞 Pepijat sederhana (disahkan masih wujud dalam kod, 2026-11-16)

Setiap pembaikan mesti ada ujian yang **dibuktikan menggigit** (buang
pembaikan sementara → ujian gagal → pulihkan), mengikut peraturan `CLAUDE.md`.

### K04 — *Take back* berfungsi pada cabutan yang sudah tamat
- [x] **Dibaiki** 2026-11-16 (PR #1) — `unassignPlot()` menolak bila `polling_status = completed`. Ujian: `TheLotPlanBuildsWhatItSaysTest` (refuses to take back… completed).
- **Apa berlaku:** Dari tab lama, *Take back* pada cabutan `completed` mengosongkan
  lot syarikat. Tiada apa boleh memberinya lot semula (`completed` tiada jalan
  balik) dan mesej salah berkata ia boleh mengundi semula.
- **Kod:** `PollingController::unassignPlot()` — tiada semakan `polling_status`.
- **Cadangan:** tolak bila `polling_status === 'completed'` (dan `cancelled`).
- **Kes ujian:** TC-136

### K05 — Pelan boleh disunting / dicat semula selepas cabutan dibuka
- [x] **Dibaiki** 2026-11-16 (PR #1) — Reka bentuk asal dikekalkan (pelan boleh disunting sehingga lot pertama dipegang), tetapi selepas cabutan bermula setiap tulis semula / cat semula menjalankan `PollingGuard::refuseIfStartedAndBroken()` dalam transaksi yang sama — jika cabutan tidak boleh selesai, semua dibatalkan. Ujian: `ARunningDrawsPlanCannotBreakItTest`.
- **Apa berlaku:** Jika belum ada lot dipegang, *Edit plan* dan *Paint codes*
  masih menyimpan semasa cabutan Open, tanpa menjalankan semula semakan
  `PollingGuard` — cabutan boleh jadi tidak boleh selesai.
- **Kod:** `PollingController::update()`, `PlotPainterController::update()`;
  `planIsFrozen()` hanya menyemak lot dipegang.
- **Cadangan:** bekukan pelan sebaik `polling_status` bukan `draft`, atau
  jalankan `PollingGuard::blocker()` selepas simpan dan tolak jika gagal.
- **Kes ujian:** TC-124

### K06 — *Reopen voting* dengan tarikh tamat yang sudah lepas
- [x] **Dibaiki** 2026-11-16 (PR #1) — `changeStatus()` menolak buka ke `polling` bila tarikh tamat sudah lepas. Pintu baharu `PUT admin/polling/{event}/deadline` (`updateDeadline()`) + borang "Change deadline" di halaman cabutan — berfungsi walaupun lot sudah dipegang. Ujian: `AReopenedDrawHasTimeLeftTest`.
- **Apa berlaku:** HQ nampak "Open", tetapi setiap peserta mendapat "The lot
  draw is not open." Tarikh tamat tidak boleh diubah sebaik ada lot dipegang.
- **Kod:** `PollingController::changeStatus()` — `PollingGuard::noWindow()` hanya
  menyemak tarikh tamat **wujud**, bukan masa depan.
- **Cadangan:** tolak peralihan ke `polling` bila `polling_deadline` sudah
  lepas, dengan mesej yang menyuruh HQ melanjutkan tarikh dahulu; benarkan
  tarikh tamat dilanjutkan walaupun lot sudah dipegang.
- **Kes ujian:** TC-143

### K07 — Admin zon boleh memadam syarikat yang HQ sudah sahkan
- [x] **Dibaiki** 2026-11-16 (PR #1) — `Company::booted()` menolak padam (untuk semua peranan) selagi syarikat ada baris yang sudah diberitahu dalam cabutan `draft`/`polling`/`closed`. HQ keluarkan baris dahulu (dengan sebab, `RemovedFromDraw` dihantar), kemudian padam. Ujian: `ACompanyToldItIsInADrawIsNotDeletedSilentlyTest`.
- **Apa berlaku:** Memadam syarikat (skrin Companies) turut membuang baris
  senarai cabutannya — memintas peraturan bahawa hanya HQ boleh mengeluarkan
  syarikat yang sudah diberitahu, dan tiada `RemovedFromDraw` dihantar.
- **Kod:** `CompanyController::destroy()`; `Company::booted()` hanya menyekat
  bila ada lot dipegang (`plot_id`).
- **Cadangan:** admin zon ditolak jika syarikat ada mana-mana baris dengan
  `first_verified_at` diisi; HQ boleh, tetapi mesti memberi sebab dan
  `RemovedFromDraw` dihantar.
- **Kes ujian:** TC-040

### K08 — Menamakan semula zon kepada nama sedia ada menggabungkan zon
- [x] **Dibaiki** 2026-11-16 (PR #1) — `AdminZones::rename()` menolak nama zon lain (tidak peka huruf besar); tukar huruf besar/kecil nama sendiri dibenarkan. Ujian: `ZonesCarryTheirRowsWhenRenamedTest`.
- **Apa berlaku:** Namakan "Perlis" kepada "Kedah": admin kedua-dua zon nampak
  syarikat satu sama lain, tanpa amaran. Nama yang hanya berbeza huruf besar
  menyebabkan admin zon mendapat 403 pada syarikatnya sendiri.
- **Kod:** `AdminZones::rename()`; `SettingController::renameZone()` — nama
  baharu tidak disemak terhadap senarai sedia ada.
- **Cadangan:** tolak nama baharu yang sudah wujud (tidak peka huruf besar);
  benarkan tukar huruf besar/kecil zon yang sama sahaja dengan sengaja.
- **Kes ujian:** TC-053

### K09 — Admin boleh mendarat di borang log masuk peserta selepas log masuk
- [x] **Dibaiki** 2026-11-16 (belum merge) — `App\Support\IntendedUrl`: halaman intended dari sisi lain diabaikan dan dibuang, untuk kedua-dua log masuk (admin dan peserta). Ujian: `BothGuardsSignInTest` (+4).
- **Apa berlaku:** Buka halaman peserta dahulu (dilantun ke `/login`), kemudian
  log masuk di `/admin/login` — admin dihantar ke borang log masuk peserta
  (URL "intended" yang basi).
- **Kod:** `Auth/Admin/LoginController` (`redirect()->intended()`).
- **Cadangan:** abaikan URL intended yang bukan di bawah `/admin`.
- **Kes ujian:** TC-007 · *Keutamaan: rendah dalam kesan, tetapi mengelirukan*

### K10 — Acara *Unpublished* masih di laman awam
- [x] **Dibaiki** 2026-11-16 (belum push) — keputusan pemilik: Unpublished tidak boleh dibuka oleh pengguna, hanya admin. `Event::PUBLIC_STATUSES` = open, completed; senarai awam menyorok Unpublished untuk semua; halaman acara 404 kecuali admin yang log masuk (dengan sepanduk). Papan pemuka dan halaman lot peserta sendiri tidak terjejas. Ujian: `AnUnpublishedEventIsHiddenFromThePublicTest`.
- **Apa berlaku:** Hanya acara Draf disembunyikan dari `/` dan `/events/{id}`;
  acara Unpublished (`status = closed`) masih disenaraikan dan boleh dibuka.
- **Kod:** `HomeController::index()`, `show()`.
- **Cadangan:** sahkan dengan pejabat dahulu — adakah *Unpublished* bermaksud
  "sembunyi dari awam"? Jika ya, tapis `status` kepada `open` (dan `completed`
  untuk acara lepas).
- **Kes ujian:** TC-071, TC-072

*K03 (Undo selepas cabutan bermula) sudah dibaiki 2026-11-15.*

---

## 3. 🟡 Patut dibuat sebelum go-live

- [ ] **Senarai keputusan untuk pejabat.** Laporan CSV dibuang. Sahkan dengan
      pejabat bagaimana mereka mendapat senarai akhir "syarikat mana dapat lot
      mana" untuk hari acara (cetak / papan). Sekarang hanya slip PDF per
      peserta.
- [ ] **21 ujian gagal** — `tests/Fixtures/legacy-dump-sample.sql` tiada dalam
      repo. Dapatkan daripada mesin pembangun asal, semak tiada data peribadi
      sebenar, tambah `!tests/Fixtures/*.sql` pada `.gitignore`, commit.
- [ ] **Jalankan suite Pest pada MySQL** (`DB_CONNECTION=mysql DB_DATABASE=undilot2026_test`)
      — produksi MySQL, mesin ini baru diuji pada SQLite.
- [ ] **Jalankan Playwright** (`npx playwright test`, perlukan MySQL) lebih
      daripada sekali; baca kiraan `flaky`.
- [ ] **Commit kerja 2026-11-16** — pengawal seeder (`LocalOnlySeeder`,
      `DevEnvironment`), dokumen aliran, panduan serahan, fail ini.

---

## 4. 🟢 Selepas go-live (pepijat rendah)

Butiran dalam helaian *Known Issues* manual ujian. Antaranya:

| ID | Ringkas |
|---|---|
| K12 | *Set aside* boleh menindih lot yang peserta baru dapat (tiada kunci) |
| K13 | Auto-buka mengabaikan tarikh tamat |
| K14 | Lokasi berbeza huruf besar sahaja memberi nombor lot berganda |
| K15 | Peringatan tutup tidak dihantar semula untuk tetingkap baharu |
| K16 | Simpan kad kod kraf mengosongkan kod / saiz yang sudah ditarik balik |
| K17 | Kategori usahawan yang dibuang dari senarai dikosongkan bila simpan |
| K22 | Dua super menurunkan pangkat satu sama lain serentak boleh tinggalkan tiada super |
| K24 | Kalendar hanya guna penapis pertama |
| K26 | "Maximum participants" disimpan tetapi tidak digunakan |
| K27 | Papan pemuka dan halaman acara tidak sepadan untuk syarikat yang ditambah semula |
| K28 | Notifikasi pembuangan ada label butang salah |
| K29 | Halaman acara awam memaparkan zon admin |

Sesetengah item dalam helaian (K11, K20, K21, K18 sebahagian, K25) merujuk modul
yang sudah dibuang (laporan, pengumuman, permohonan sertai) — sahkan dan tutup.

---

## 5. Turutan dicadangkan

| Langkah | Perkara | Bergantung pada |
|---|---|---|
| 1 | Minta CSV peserta (dengan emel) daripada pejabat — **mulakan sekarang**, ia paling lama | Pejabat |
| 2 | Baiki K04–K08 (+ K09, K10) dengan ujian | Pembangun |
| 3 | Kemas kini helaian ujian; jalankan kes keutamaan tinggi + portal peserta di telefon | Pembangun + HQ |
| 4 | Sediakan pelayan, sahkan emel boleh dihantar | Pentadbir pelayan |
| 5 | Import data sebenar; tamatkan peraturan baseline migration | 1, 4 |
| 6 | Cabutan perintis di pelayan sebenar bersama HQ + beberapa admin zon | 2–5 |
| 7 | **Go-live** | 6 |

---

## Log kemajuan

| Tarikh | Perkara | Commit | Oleh |
|---|---|---|---|
| 2026-11-16 | Penilaian awal; pengawal seeder demo + `migrate:fresh` di pelayan (belum commit) | — | — |
| 2026-11-16 | K04–K08 dibaiki, 22 ujian baharu, setiap satu dibuktikan menggigit. Suite: 479 lulus, 21 gagal (fixture lama sahaja) | PR #1 | — |
| 2026-11-16 | K09 dibaiki; label kod kraf pada slip dibetulkan; `.env.example` dibersihkan | PR #2 | — |
| 2026-11-16 | K10 dibaiki; IC pada slip di belakang suis `SLIP_SHOWS_IC` (lalai **on** — IC dicetak, keputusan pemilik) | belum push | — |
| 2026-11-17 | Medan data pejabat: `alamat` dalam import CSV; kategori usahawan = Pengeluar atau Pedagang / Pemborong; status bayaran (Paid / Not paid) di senarai cabutan; nombor lot `TBPS02`. Suite: 504 lulus, 21 gagal (fixture lama sahaja) | belum push | — |
| 2026-11-17 | Log masuk peserta ditukar ke ID Komuniti + IC (tanpa kata laluan/emel); borang syarikat mewajibkan ID + IC, emel pilihan. Suite: 516 lulus, 21 gagal (fixture lama sahaja) | belum push | — |
| 2026-11-17 | Borang pelan lot: bahagian "Booth sizes here" dibuang; jumlah lokasi dikira daripada peruntukan kod + baris plot simpanan pilihan. Suite: 516 lulus, 21 gagal (fixture lama sahaja) | belum push | — |
| 2026-11-17 | Aplikasi tidak menghantar emel langsung: notifikasi dalam aplikasi sahaja, aliran lupa/reset kata laluan dibuang; kad dan route tukar kata laluan dibuang dari profil, profil memaparkan ID Komuniti; B1 dibetulkan (emel tidak wajib) | belum push | — |
| 2026-11-17 | Tiada emel langsung: lajur `email` dibuang daripada `users` dan `admins` (dan `password_reset_tokens`); pegawai log masuk dengan nama pengguna + kata laluan baharu; import CSV tidak membaca `emel`; `legacy:emails` dibuang; legacy/demo dikenal pasti dengan `legacy_id` / ID Komuniti. Suite: 503 lulus, 22 gagal (fixture lama sahaja) | belum push | — |
