# CLAUDE.md

Panduan untuk sesiapa — manusia atau agen — yang bekerja dalam repo ini.

Fail ini memegang **peraturan dan keputusan sahaja**: perkara yang tidak boleh
diterbitkan semula dengan membaca kod.

---

## Apa ini

**undilot** — sistem cabutan lot untuk acara usahawan kraf. Laravel 12, PHP
`^8.4`, MySQL pada dev dan produksi, SQLite dalam-memori untuk ujian.

**undilot2026** ialah pecahan daripada **`undilotlook`** (2026-11-13): hanya
log masuk peserta, log masuk admin (super, HQ dan negeri pada satu `/admin/login`,
peranan menentukan akses), acara dan cabutan lot. Pengumuman, halaman footer,
pautan sosial, skrin audit trail, laporan CSV dan permohonan sertai dibuang —
pejabat memasukkan setiap syarikat ke senarai cabutan sendiri; peserta tidak
pernah memohon, mereka log masuk dan mengesahkan lot. Jejak audit (`Auditable`)
masih direkod; cuma skrinnya tiada. Data sebenar akan dimuatkan dari CSV.

`undilotlook` pula dipisahkan daripada **`daftar`** (`f:\herd\daftar`) pada 2026-10-13. `daftar`
membawa 285 route, 156 migration dan 62 entri backlog, dan backlog itu tertumpu
pada modul yang bukan produk. undilot membawa cabutan lot dan apa yang cabutan
perlukan.

**`daftar` kekal sebagai arkib dan rujukan.** Apa pun yang ditinggalkan masih ada
di sana untuk disalin bila diperlukan. Jangan tulis semula sesuatu yang boleh
disalin.

---

## Persekitaran tempatan

| | |
|---|---|
| Laluan | `f:\herd\undilot2026` |
| URL | `http://undilot2026.test` (Herd) |
| DB | `undilot2026` pada `127.0.0.1` — sendiri |
| PHP CLI | `/c/Users/DELL/.config/herd/bin/php84.bat` |
| MySQL CLI | `/c/Program Files/MySQL/MySQL Server 8.0/bin/mysql.exe` |
| Admin pertama | dari `.env` — `ADMIN_SEED_EMAIL` / `ADMIN_SEED_PASSWORD` |
| Log masuk pantas | `http://undilot2026.test/auto` — sebarang akaun, tanpa kata laluan, plus butang *Work the queue* |
| DB suite pelayar | `undilot2026_e2e`, dicipta dan ditetapkan semula oleh `tests/e2e/serve.js` |
| Git | `https://github.com/aijad89/undilot2026` (awam), cawangan `main` |

`artisan tinker <fail>` **tergantung** pada mesin ini. Guna klien MySQL untuk
menanya pangkalan data, atau skrip PHP yang memuat `bootstrap/app.php`.

Setiap notifikasi berbaris (queue). Pada mesin ini tiada pekerja berjalan, jadi
peringatan undian, `AddedToDraw` (syarikat dimasukkan ke cabutan, dihantar
sekali bila HQ mengesahkan buat pertama kali) dan `RemovedFromDraw` kekal dalam
`jobs` sehingga butang pada `/auto` ditekan.

**Jerangkap shell pada mesin ini:** `php` tiada dalam PATH bash — guna
`php84.bat` di atas (Node dengan `shell: true` menemuinya). Heredoc bash yang
mengandungi apostrof gagal dalam alat Bash; tulis skrip ke fail dahulu.

---

## Peraturan

### Satu baseline migration, dan ia kekal satu

`database/migrations/0001_01_02_000000_create_undilot_schema.php` memegang
keseluruhan skema aplikasi. Selagi belum ada data sebenar, **ubah fail itu dan
jalankan `migrate:fresh`** — jangan tambah migration berperingkat.

Itu berhenti pada hari pejabat memasukkan data sebenar. Selepas itu, migration
baharu seperti biasa.

### Tiada lajur `enum`, selamanya

Perbendaharaan status tinggal dalam const model (`Event::STATUSES`,
`Event::POLLING_STATUSES`), bukan dalam jenis lajur.

Sebabnya diukur: enum adalah serupa pada MySQL dan SQLite sehingga seseorang
guna `ALTER TABLE … MODIFY COLUMN`, yang **SQLite langkau senyap**. Dalam
`daftar` penyimpangan itu tidak disedari selama lapan bulan — sehingga tiada
ujian boleh menetapkan `polling_status = 'closed'`, iaitu keadaan yang modul
cabutan itu sendiri mengenainya.

`tests/Feature/SchemaHoldsTheSameShapeOnBothDriversTest.php` mengunci ini.

### Setiap migration dijalankan pada **dua** pemacu sebelum dipercayai

```
php artisan migrate:fresh --force                 # MySQL, dari .env
vendor/pestphp/pest/bin/pest                      # SQLite dalam-memori
```

### Nav layout ialah senarai, bukan markup

`resources/views/layouts/admin.blade.php` dan `partials/portal-bar.blade.php`
(bar peserta, dikongsi portal dan halaman awam) masing-masing mengisytiharkan
`$nav` / `$apNav`. **Tambah modul =
satu entri array.**

Jangan kembali kepada mengeja `route()` dalam markup. `daftar` ada 22 dalam satu
layout, dan satu route yang dibuang di mana-mana membunuh **setiap** skrin admin
sekali gus, dengan trace yang menyalahkan layout.

### Factory menamakan setiap lajur, termasuk yang nullable

`Model::preventAccessingMissingAttributes()` hidup di luar produksi, dan
`actingAs($user)` menyerahkan view **instance factory itu sendiri**. Lajur yang
factory tidak namakan tidak hadir pada instance itu — jadi view campak
`MissingAttributeException` di bawah `actingAs` sambil berfungsi sempurna
selepas log masuk sebenar. Kegagalan itu kelihatan seperti view yang rosak.

`null` ialah nilai. Namakan lajur itu.

### Tiada kata laluan lalai dalam kod

`AdminSeeder` menolak berjalan tanpa `ADMIN_SEED_PASSWORD` dalam `.env`.
**Jangan beri ia fallback.** Kata laluan ber-fallback dalam fail yang dijejaki
ialah kata laluan dalam sejarah git pada hari ia ditulis.

### Semua `.md` dijejaki

Tiada peraturan `.gitignore` mengecualikannya. Keputusan ialah bahagian yang
paling sukar dipulihkan.

### Data rujukan disemai, data demo tidak

`DatabaseSeeder` memanggil `AdminSeeder` dan `KrafCodeSeeder` (helaian pengekodan
HKK 2025 + lima kategori) — tanpanya pemasangan baharu tidak boleh memberi kod
kepada sesiapa. Kedua-duanya boleh dijalankan semula.

**Setiap seeder data demo/ujian mewarisi `LocalOnlySeeder`**, yang menolak
(campak `RuntimeException`) setiap `APP_ENV` selain `local`, `testing` dan `e2e`
(`App\Support\DevEnvironment::NAMES`). Ia **senarai benar**, bukan
`isProduction()`: pelayan yang `APP_ENV`-nya `staging`, salah eja atau tiada
tetap ditolak. Pengawal berada dalam `__invoke`, jadi `db:seed --class=X` dan
`$this->call(X::class)` kedua-duanya melaluinya. `DatabaseSeeder` bertanya soalan
yang sama sebelum memanggil `DemoSeeder`. Sebabnya: `DemoThreeEventsSeeder`
memadam **setiap** acara sebelum menanam, dan dahulu tiada pengawal sendiri.

Seeder baharu: data demo → `extends LocalOnlySeeder`; data rujukan → tambah
namanya pada `REFERENCE_SEEDERS` dalam
`tests/Feature/DemoSeedersNeverRunOnAServerTest.php`. Ujian itu gagal jika tidak.

**`db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` dan
`migrate:rollback` juga ditolak** di luar senarai yang sama
(`DevEnvironment::lockDestructiveCommands()` dalam `AppServiceProvider`).
`migrate --force` tidak disentuh.

`E2eSeeder` pula hanya berjalan dalam `e2e` (dan `testing`).

### `/auto` tidak pernah wujud di pelayan

Ia log masuk sebagai sesiapa tanpa kata laluan. Didaftar hanya dalam `local`,
`testing` dan `e2e`, dan pengawal yang sama dalam controller. **Jangan** bawa
pintu ber-flag `daftar` (`DEV_AUTO_LOGIN` + senarai IP) — itu keputusan.

### Aplikasi mesti kekal selamat-subpath

Ia mungkin dihidang di `/undilotnew`, bukan akar domain. Setiap pautan dan ubah
hala melalui `route()` / `url()` — **tiada** `redirect('/')` atau `href="/…"`.
`/` ialah senarai acara awam, jadi destinasi selepas log masuk mesti dinamakan
(`dashboard`, `admin.dashboard`).

Diukur: `redirect('/laluan')` **sebenarnya selamat-subpath** — Laravel
menyelesaikannya terhadap akar permintaan (`/undilotnew/laluan`). Bahaya
`redirect('/')` ialah **destinasinya** (senarai awam), bukan ia terlepas. Yang
benar-benar terlepas ialah `href="/…"`, string laluan dalam skrip sebaris, dan
URL relatif-akar daripada cakera. `EveryLinkSurvivesASubpathInstallTest`
melekapkan aplikasi di `/undilotnew` dan menyapu setiap skrin untuk keempat-empat
peranan.

### Setiap peraturan baharu dibuktikan menggigit

Selepas ujian lulus, buang peraturan itu sementara dan pastikan ujian **gagal**,
kemudian pulihkan. Beberapa ujian dalam repo ini lulus atas sebab yang salah
sehingga diperiksa begini (pengawal yang dilangkau oleh ralat lain, mutasi yang
dilindungi laluan lain).

---

## Seni bina dan jerangkap

### Dua guard

`web` (`App\Models\User`) dan `admin` (`App\Models\Admin`). Ini keputusan paling
membentuk dalam aplikasi, dan hampir semua kejanggalan auth berpunca daripadanya:
**admin ialah tetamu pada guard `web`**, dan peserta ialah tetamu pada `admin`.

`bootstrap/app.php` menjawabnya dengan dua closure. Pecahkan salah satu dan
kegagalannya bukan 404 — ia gelung redirect, atau admin yang sudah log masuk
merenung borang log masuk **peserta**.

`tests/Feature/BothGuardsSignInTest.php` menahan kedua-duanya.

### Tiga peranan admin

`admin` (berskop satu negeri), `hq`, `super`. Perbendaharaan dan siapa boleh
memberi apa ada dalam `Admin::ROLES` dan `Admin::assignableRolesFor()`.

`assignableRolesFor()` **diterbitkan daripada pemanggil**, bukan senarai tetap.
Peraturan `Rule::in(array_keys(Admin::ROLES))` tidak peduli siapa bertanya —
tidak berbahaya selagi skrin itu super-sahaja, dan membenarkan hq mencetak akaun
super bagi dirinya sendiri sebaik skrin itu diluaskan.

Skrin akaun admin ialah `Admin\AdminController`, dalam dua senarai di bawah **User** pada sidebar: **HQ Admins** (hq + super,
super sahaja) dan **State Admins** (super dan hq). Gate `manage-admins` membuka
skrin; di dalamnya **dua soalan berasingan**, dan kedua-duanya perlu:
`authorizeTarget()` (hq hanya menyentuh admin negeri — bukan super, bukan rakan
hq) dan `authorizeRole()` (`assignableRolesFor()`). Menyekat super sahaja tidak
cukup: hq masih boleh menurunkan pangkat hq lain.

### Skop negeri melalui `EventVisibility`, bukan middleware

Tiada alias middleware yang kelihatan seperti pengawal negeri. Pengawal pada
setiap route admin **membaca** seperti penguatkuasaan dan tidak menguatkuasakan
apa-apa. `TrackAdminLastSeen` ialah penjejakan kehadiran, dan namanya berkata
begitu.

`EventVisibility` memegang **satu** peraturan: acara draf hanya untuk super/hq.
**Tiada peraturan *own-state*** — `daftar` menyembunyikan acara daripada admin
negeri sehingga syarikat negerinya berada dalam cabutan, yang betul bila peserta
mendaftar sendiri. Di sini pejabat yang mengisi senarai cabutan; peraturan itu
akan menyembunyikan setiap acara kosong daripada orang yang perlu mengisinya.
Baris **di dalam** acara tetap ditapis mengikut zon.

### Tulis digate di route, baca menskop sendiri

Tindakan tulis berada di bawah `can:` dalam `routes/web.php` (`manage-events`,
`manage-settings`, `verify-draw-list`), ditakrif dalam `AppServiceProvider`. Skrin
baca terbuka kepada semua peranan dan menapis sendiri. Pautan nav untuk skrin
bergate disorok dengan `when` yang bertanya soalan yang sama.

Satu pengecualian: route senarai cabutan melayan admin negeri juga (lulus,
tambah, sunting, buang), jadi `EntRegistrationController` bertanya
`verify-draw-list` per permintaan untuk tahu sama ada tulisan itu juga
pengesahan HQ. Hanya `return` membawa gate itu di route.

### `kraf_bidangs` memegang **dua** senarai

| `code` | Apa ia | Diurus di |
|---|---|---|
| `NULL` | kategori kraf (TEKSTIL, HASIL RIMBA…) | Craft Hierarchy |
| berisi | kod kraf (TBPS, RKP…) dari helaian pengekodan | Craft Category Code |

Keduanya **tidak berkait**. Kod kraf datang daripada pensijilan lain, bukan
daripada hierarki; syarikat membawa kodnya sendiri dalam `companies.kraf_code`,
yang mencadangkan kod pada senarai cabutan.

**Setiap query mesti menyatakan yang mana** — `KrafBidang::categories()` atau
`::codes()`. Senarai yang terlupa memaparkan kedua-duanya; padam yang terlupa
membuang baris daripada senarai yang tiada siapa sedang lihat. Ini punca tiga
entri backlog dalam `daftar`.

Peruntukan pelan dan cat plot menerima **kod sahaja**. Plot yang ditanda dengan
kategori tidak boleh dipadankan oleh mana-mana pendaftaran; `daftar` menerimanya.

Padam baris kraf **ditolak** selagi ada apa-apa menunjuk kepadanya (setiap FK
hidup diperiksa), supaya cascade tidak mengambil apa-apa tanpa jejak audit.

Kategori kraf **tetap** — disemai, tidak ditambah atau dipadam di skrin. Di bawah
sub-kategori ada **klasifikasi** dan **produk**, kedua-duanya pilihan, dan
syarikat menandanya per pilihan (`company_kraf_pengelasan`,
`company_kraf_produk`). `kraf_produks` membawa `sub_bidang_id` **dan**
`bidang_id`: `bidang_id` dikekalkan selari kerana kod lama
masih membacanya, dan produk dengan `sub_bidang_id` NULL ialah baris lama yang
"belum diletakkan" — tidak boleh ditanda oleh syarikat.

### Pemilikan ialah `company_id`, bukan `user_id`

`User::registrations()` ialah `hasManyThrough` melalui `Company`. Lajur
`company_id` ialah `NOT NULL` dan ber-foreign-key; `user_id` nullable dan tiada
apa dalam skema menghalang null tiba.

`user_id` **tetap ditulis dan dibaca** — oleh `plots.allocated_to`, `polls`, dan
peruntukan awal. **Dua kunci, dua soalan, dengan sengaja.**

### Dua semakan: baris ialah kelulusan zon, `verified_at` ialah HQ

Tiada lajur `status` pada `event_registrations`. **Admin negeri menyemak
semua** — butiran syarikat, nota bayaran, kod kraf, saiz gerai — dan
meletakkannya ke senarai cabutan. Baris itu menunggu HQ.

**HQ menyemak semula** (gate `verify-draw-list`): sahkan (boleh membetulkan kod
atau saiz dalam langkah yang sama), atau pulangkan kepada zon dengan nota
(`hq_note`). Zon membetulkan, baris menunggu HQ lagi.

**HQ boleh memintas zon, dengan sengaja.** Syarikat yang HQ tambah sendiri ke
senarai cabutan terus disahkan — tiada semakan zon. Itu keputusan, bukan
lubang; jangan "betulkan".

- Baris yang pejabat masukkan **tidak diberitahu** sehingga HQ mengesahkan.
  Bacaan portal menggunakan skop `announced()` (`first_verified_at`), **bukan**
  `verified_at`: syarikat yang sudah diberitahu tidak hilang dari portalnya
  sendiri bila zon menyunting dan HQ menyemak semula. Pengesahan HQ yang
  pertama menghantar `AddedToDraw` — satu-satunya pemberitahuan "anda dalam
  cabutan ini", kerana syarikat tidak pernah memohon untuk tahu bila ditolak
  sebelum itu.
- `PollingGuard` menahan cabutan selagi ada baris belum disahkan.
- Perubahan kod atau saiz oleh admin negeri — dari mana-mana pintu, termasuk
  halaman syarikat melalui `syncBoothSize()` — **mengosongkan** `verified_at`.
  Ini satu tempat: `EventRegistration::booted()`.
- Syarikat yang ditambah pejabat tiada permintaan, jadi tiada bukti bayaran:
  zon menulis `payment_note` (no. resit, tarikh, cara). Mengubahnya juga
  mengosongkan `verified_at`. Tidak dibekukan bila cabutan bermula.
- Kod dan saiz **dibekukan** sebaik `polling_status` bukan `draft`, untuk HQ
  juga — jika tidak, suntingan zon mengosongkan pengesahan dan syarikat hilang
  dari papan pemuka pesertanya sendiri di tengah cabutan.
- Admin negeri hanya membuang baris yang **belum** diberitahu kepada syarikat
  (`first_verified_at` null). Mengeluarkan syarikat daripada cabutan yang ia
  sudah diberitahu ialah keputusan HQ (`zoneMayRemove()`). HQ mesti memberi
  sebab; syarikat menerima `RemovedFromDraw`, dan sebab itu hidup **hanya**
  dalam mesej notifikasi itu sendiri, kekal dalam jadual `notifications` —
  baris pendaftaran yang dibuang tidak menyimpan apa-apa.
- Sebaik cabutan bermula, admin negeri **tidak menambah atau meluluskan**
  sesiapa (`zoneLocked()`); hanya HQ, dan baris HQ disahkan serta-merta. Tiada
  baris menunggu HQ boleh wujud semasa cabutan berjalan. **Undo juga menambah
  baris**, jadi ia ditolak untuk semua orang sebaik cabutan bermula — ia
  memulihkan baris seperti asal (mungkin masih menunggu HQ) dan tanpa
  pemberitahuan kepada syarikat yang sudah menerima `RemovedFromDraw`. Ditemui
  oleh ujian 2026-11-15; sebelum itu undo tiada pengawal ini.
- **Syarikat tidak boleh dipadam** (oleh sesiapa) selagi ia ada baris yang sudah
  diberitahu dalam cabutan `draft`/`polling`/`closed` — padam akan cascade baris
  itu tanpa sebab dan tanpa `RemovedFromDraw`. HQ keluarkan baris dahulu.
  `Company::booted()` (K07).
- `first_verified_at` tidak pernah dikosongkan; ia membezakan semakan semula
  daripada yang pertama, supaya peserta diberitahu sekali sahaja.

### Baris `plot_sizes` ialah pasangan (lokasi, saiz)

Borang tidak menaip pasangan itu terus: satu baris pada borang ialah **lokasi**
dengan kiraan, dan setiap peruntukan kraf yang bersarang di bawahnya menamakan
**saiznya sendiri**. `PollingController::savePlan()` menyelesaikannya kembali
kepada baris, memadan pada (lokasi, saiz) supaya pasangan yang terselamat
mengekalkan id-nya. `total_plots` dikira di sana, tidak pernah diambil daripada
borang.

Saiz gerai sebuah pendaftaran ialah `event_registrations.plot_size` — satu
**string**, lalai `3x3`. Bukan rujukan `plot_sizes`: pejabat menyatakannya
sebelum sebarang pelan wujud, kerana pelan dibina daripada nombor itu.

**Setiap lokasi menamakan saiz plot lebihannya** (`plot_sizes.*.size`, wajib).
Lot hanya ditarik pada saiz gerai pendaftaran itu sendiri, jadi plot tanpa saiz
tidak pernah boleh dimenangi. Borang `daftar` tidak pernah menghantar medan itu,
dan setiap plot lebihan yang dibinanya mati.

`LotAllocator` ialah satu-satunya tempat peraturan lot. Confirm peserta dan larian
pukal admin kedua-duanya memanggilnya; jangan beri mana-mana pintu salinan
peraturannya sendiri. `PollingGuard` begitu juga untuk "boleh dibuka?" — butang
dan scheduler bertanya soalan yang sama.

### `event_registrations.code` ialah string, bukan foreign key

Ia memegang kod kraf (TBPS, RKP) dan dipadankan terhadap `code` pada baris
`kraf_bidangs` yang plot dikategorikan dengannya. Itu ketidakpadanan
string-lawan-FK, dan ia **sengaja dibiarkan**: itulah yang pengalokasi
bandingkan, dan mengubahnya bermakna menulis semula pengalokasi dan bukan
membawanya masuk utuh.

Pengesahannya juga **sengaja longgar** (`'string', 'max:50'`, bukan
`Rule::in` helaian pengekodan): kod berubah dari semasa ke semasa, dan baris
yang membawa kod yang sudah ditarik balik atau dinamakan semula mesti masih
boleh disimpan. Dropdown hanya menawarkan kod aktif; pelayan tidak menolak yang
lain. Keputusan pemilik (2026-11-15) — jangan ketatkan.

**Menamakan semula kod membawa barisnya**, seperti `AdminZones::rename()`.
Kod dipegang dua cara: plot dan preset melalui `bidang_id` (ikut sendiri), dan
`event_registrations.code`, `companies.kraf_code`, `polls.code`,
`preset_allocations.code` sebagai teks. Sebelum ini rename hanya menggerakkan
separuh kunci — diukur: TBPS → TBPX meninggalkan setiap syarikat TBPS tanpa plot
yang boleh dimenangi, tanpa ralat. `SettingController::updateKrafCode()` kini
mengemas kini keempat-empat lajur teks dalam satu transaksi, menulis baris audit
`kraf_code_renamed`, dan menolak rename selagi cabutan yang menggunakannya
sedang dibuka. `plots.plot_code` (label gerai bercetak) tidak disentuh. Lajur
teks kod baharu **mesti** ditambah ke senarai itu.

### `event_registrations.plot_id` tiada klausa `ON DELETE`

Jadi pangkalan data menyekat. Plot tidak boleh dipadam selagi satu entri cabutan
masih menunjuk kepadanya — keputusan cabutan **tidak boleh** hilang kerana
seseorang menyunting pelan.

Pelan **dibekukan** sebaik satu lot dipegang, preset termasuk
(`PollingController::planIsFrozen()`): sunting, kosongkan dan cat semuanya
ditolak. **Sebelum itu, jika cabutan sudah bermula** (`polling`/`closed`), setiap
tulis semula atau cat semula memanggil `PollingGuard::refuseIfStartedAndBroken()`
dalam transaksi yang sama: jika cabutan tidak lagi boleh selesai, perubahan
dibatalkan (K05, 2026-11-16).

**Tarikh tamat ada pintu sendiri** (`PollingController::updateDeadline()`), kerana
borang pelan terkunci pada lot pertama. *Reopen voting* ditolak bila tarikh
tamat sudah lepas — `PollingGuard` hanya bertanya tarikh itu wujud (K06).

*Take back* (`unassignPlot()`) ditolak pada cabutan `completed` — tiada jalan
balik untuk memberi lot semula (K04). Jadi tiada penulisan semula yang perlu mengosongkan `plot_id` — cabang
"pulihkan preset" `daftar` tidak pernah boleh berjalan, dan tidak dibawa.

### Audit cabutan sebahagiannya ditulis dengan tangan

Trait `Auditable` menggunakan Eloquent model event, dan **mass delete tidak
mencetuskan satu pun**. `PollingController` memasukkan barisnya sendiri
(`plan_rewrite`, `plan_cleared`, `plots_generated`) untuk menampungnya, dan
`AuditTrail::getChangedFieldsAttribute()` ada cabang khas untuknya.

Begitu juga `ON DELETE CASCADE`: baris yang hilang melaluinya **tidak
meninggalkan satu pun baris audit**. Model yang boleh menyatakan jangkauannya
menutupnya dengan `auditDeletionSummary()`, mengira **sebelum** DELETE.

**Jangan ukur jangkauan cascade daripada fail migration.** Tanya pangkalan data
hidup:

```sql
select rc.REFERENCED_TABLE_NAME, kcu.TABLE_NAME, kcu.COLUMN_NAME, rc.DELETE_RULE
from information_schema.REFERENTIAL_CONSTRAINTS rc
join information_schema.KEY_COLUMN_USAGE kcu
  on kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
 and kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
where rc.CONSTRAINT_SCHEMA = 'undilot2026' and rc.DELETE_RULE = 'CASCADE';
```

### Tiada langkah bina

Tiada Vite, tiada npm build, tiada Alpine. Setiap stylesheet ialah inline dalam
partial Blade; setiap pustaka datang dari CDN dengan versi dipin. `npm` hanya
untuk Playwright. **Node tidak diperlukan pada produksi.**

### Dua design system, dua prefix

`.mac-` (admin, amber) dan `.ap-` (portal peserta, biru). Tiada halaman sah
menggunakan kedua-duanya. View auth membawa rupa tersendiri — jangan kenakan
kelas `mac`/`ap` padanya.

### Zon waktu ialah `Asia/Kuala_Lumpur`, dalam `config/app.php`

Admin menaip tetingkap cabutan dalam waktu tempatan dan ia disimpan seperti
ditaip. `now()` mesti pada jam yang sama, atau scheduler membuka cabutan 09:00
pada 17:00 — rangka Laravel datang dengan UTC, dan undilot berjalan begitu
sehingga larian pelayar pertama menunjukkan "Confirmed On" lapan jam awal.

### `SESSION_DRIVER` mesti `database`

`NewPasswordController::logOutOtherDevices()` mengusir dengan memadam baris
daripada jadual `sessions`. Pada pemacu lain ia memadan **tiada apa**: reset
melaporkan berjaya dan peranti lain kekal log masuk. Tiada ralat, tiada ujian
gagal — hanya satu `Log::warning`.

### Satu-satunya muat naik ialah sepanduk acara, ke cakera `public`

`EventController::storeBanner()` ialah satu-satunya laluan muat naik dalam
aplikasi ini — tiada apa lagi menulis ke cakera `local`. Pengesahan
(`'mimes:jpeg,png,jpg,webp'`) **sengaja** tidak menyenaraikan `svg`: pada
origin ini SVG boleh membawa skrip, dan sepanduk dihidang terus dari cakera
`public`.

**`php artisan storage:link` ialah langkah deploy, dan ia senyap bila
terlupa.** `public/storage` ialah symlink dan diabaikan git, jadi ia tidak
datang bersama repo. Tanpanya `/storage/…` jatuh ke Laravel dan bukan ke
pelayan web — dan sepanduk **hilang hanya di pelayan**, kerana nginx Herd
menghidang URI itu sebagai fail statik pada mesin pembangunan. Diukur: header
`ETag`/`Accept-Ranges` tanpa header PHP untuk fail yang ada, 403 untuk yang
tiada.

**Sebut cakera `public` pada nama bila menjana URL sepanduk.** `Storage::url()`
tanpa nama menanya cakera **lalai**, iaitu `local` (`.env`), direktori lain yang
tiada sepanduk. Ia nampak betul kerana kedua-dua cakera menjawab di `/storage`
— tetapi cakera `local` memulangkan `/storage/…` **relatif akar**, yang
menunjuk ke akar domain dan memusnahkan pemasangan subpath. Cakera `public`
membina atas `APP_URL`.

**`'serve'` pada cakera `local` ialah `false`, dan Laravel menghantarnya
`true`.** Dihidupkan, ia mendaftar dua route di `/storage/{path}` — GET membaca
`storage/app/private` dan PUT **menulis** ke sana. Kedua-duanya menuntut URL
bertandatangan dengan `APP_KEY`, jadi bukan lubang; tetapi aplikasi ini tidak
pernah menyentuh cakera `local` langsung, dan GET itu menaungi URI sepanduk.

### Laman awam: `Event::PUBLIC_STATUSES`

Hanya `open` (Published) dan `completed` (Ended) dipaparkan kepada awam dan
peserta. `closed` (Unpublished) disorok dari senarai untuk semua; halamannya
404 kecuali kepada admin yang log masuk, dengan sepanduk (K10, keputusan
pemilik 2026-11-16). Papan pemuka, halaman lot dan slip peserta **tidak**
menyemak status terbit — peserta dalam cabutan tidak boleh terkunci daripadanya.

**Awas:** penjadual menukar `open` → `closed` bila `registration_close_at` lepas
jika `auto_close` hidup — acara itu kini hilang dari laman awam pada saat itu.

### IC pada slip ialah suis, `SLIP_SHOWS_IC`

Lalai **on** — IC dicetak (keputusan pemilik 2026-11-16). Suis
(`config('app.slip_shows_ic')`) dikekalkan kerana menyorokkannya pernah
dipertimbangkan pada hari yang sama: slip dikongsi dari telefon, dan QR sahaja
sudah mengenal pasti pemegang. `SLIP_SHOWS_IC=false` menyorokkannya.

### `$unreadNotificationCount` disediakan oleh senarai view, bukan `*`

View composer dalam `AppServiceProvider` menamakan setiap view yang membacanya.
View baharu yang membacanya **mesti** ditambah pada senarai itu, atau ia 500.

### Jadual `legacy_*` ialah pelantar pendaratan, bukan data aplikasi

`legacy_upengawai`, `legacy_usahawan` dan `legacy_ukhemah` memegang dump pejabat
**sebijik-sebijik**. Tiada FK, tiada unique, dan **setiap lajur data ialah string
nullable**.

String itu bukan kemalasan. Dump itu MySQL 4: tiada satu lajur pun nullable, jadi
"tiada nilai" ialah `''`; dan `lastlogin`, `votetime`, `votetimepay` diisytihar
`datetime NOT NULL` tanpa default, jadi "belum pernah" ialah
`0000-00-00 00:00:00`. MySQL 8 di sini berjalan dengan
`NO_ZERO_DATE,STRICT_TRANS_TABLES` dan **menolak kedua-duanya** — diukur:
ralat 1292 untuk zero-date pada lajur `datetime`, ralat 1366 untuk `''` pada
lajur `int`. Menaip lajur staging bermakna import mati pada baris pertama.

Nama lajur ialah **nama lama, dihurufkecilkan, tiada apa lagi** — itu sebabnya
`userright`, `loginname`, `sessionid` dan `protokol` kelihatan ganjil. Satu
peraturan bermakna pemuatan tidak perlu pemetaan per-lajur dan audit ialah diff.

`legacy_id` menyimpan `id` asal, kerana tanpanya pautan antara jadual lama tidak
boleh dibina semula (`ukhemah.usahawanid` → `usahawan.id`,
`usahawan.byuser` → `upengawai.id`). `source_table` menamakan jadual asal, dan
dump itu ada **lebih** daripada satu keluarga salinan: jadual `ukhemah*` (hidup,
snapshot setiap jam seperti `ukhemah_day1_2pm`, jadual per-acara bernama seperti
`ukhemah_fkj2024`, dan `ukhemah10` yang berhenti selepas enam lajur), **dan**
`usahawan*` yang ada salinan dalam gaya sama (`usahawan_19 feb 6pm`).
`event` **bukan** milik jadual hidup sahaja — `ukhemah_fkj2024` membawanya juga
(`'FKJ'`).

### `ukhemah` ialah pelan, bukan pendaftaran

Satu baris `ukhemah` ialah **satu gerai**: `boothid` (`KH01`, `TBA02`) berdiri di
lokasi yang `khemah` namakan (`Khemah A`, `Basement`, `PADANG AKASIA , ANGSANA
JOHOR BAHRU MALL`), dikategorikan ikut kraf, dengan `usahawanid` **kosong**
sehingga ada yang memegangnya. Itu jadual `plots` undilot dengan peruntukannya
sebaris. Transform membina **plot**, bukan entri cabutan, daripada sini.
`prio` ialah keutamaan gerai dan `protokol` (`'Ya'`) menandakan gerai simpanan.

### Tiga lajur kraf lama tidak bermaksud apa namanya kata

Ini sambungan langsung kepada amaran dua-senarai `kraf_bidangs`, dibaca
daripada baris sebenar:

| Lajur lama | Sebenarnya | Contoh |
|---|---|---|
| `bidang` | **kategori** kraf | `Tekstil`, `Hasil Rimba`, `Hasil Tanah`, `Aneka Kraf` |
| `crafttype` | **kod** kraf + saiz plot | `Batik-TBA-3x3`, `Buluh/Rotan-RBH-3x3` |
| `category` | **bukan kraf** — kategori usahawan | `Pentahapan 2 (Pengilang/Pedagang,Pengeluar,Agensi)` |

Jadi lajur bernama `category` pergi ke `companies.entrepreneur_category`, bukan
ke mana-mana senarai kraf. `bidang` ke kategori (`code` NULL), `crafttype` ke kod
(`code` berisi).

**`crafttype` ada dua era.** Data lama membawa nama kosong tanpa kod dan tanpa
saiz (`Songket`, `Tenun`, `Kayu(Hiasan Ukiran & Kegunaan)`); data baharu
melekatkan kod dan saiz (`Batik-TBA-3x3`). Pecah dari **kanan** — nama kraf
sendiri boleh mengandungi tanda baca. `boothid` mengulang kod itu sebagai prefiks
dalam data baharu (`TBA02` ← TBA), yang boleh digunakan untuk menyemak bacaan
pertama; `boothid` lama (`KH01`, `PS05`) tidak mengikut senarai kod.

`salu` ialah **kata panggilan** (`Puan`), bukan status.

### Hanya lajur yang dipakai, diukur bukan diteka

Dump penuh (`kraf_lama.sql`, 8.2MB, **64 jadual, 21,032 baris** — diabaikan git
kerana ia mengandungi nama, IC dan alamat, dan repo ini awam) diukur isian
setiap lajur. **21 lajur ditinggalkan** (2 + 13 + 6): ada yang 0% dalam
kesemua 21,032 baris, ada yang 100% tetapi satu nilai berulang, dan ada yang
pejabat berhenti merekod sedekad lalu. Fail dump itu sendiri ialah arkib, jadi
menyempitkan jadual staging tidak menghilangkan apa-apa.

### Empat fakta dump yang menentukan skop import

1. **`upengawai.UserRight` ada SATU nilai** — `Pengawai`, merentas 137 baris
   semua versi. Sistem lama **tiada peranan**, jadi `admin`/`hq`/`super`
   mesti **ditetapkan**, bukan dimigrasi.
2. **Tiada jadual acara.** `syatemconfiguration` cuma konfigurasi aplikasi lama
   (Clipsoft 2007). Identiti acara hanya ada pada **nama jadual**, plus tiga kod
   dalam lajur `event`: `AACB` (jadual hidup), `FKJ`, `HKK`. Tiada tajuk, tiada
   tarikh — semua yang `events` perlukan.
3. **21% peruntukan rosak.** 6,842 peruntukan gerai; **1,464** menunjuk kepada
   `usahawanid` yang tiada dalam mana-mana jadual `usahawan*`. `usahawan` ialah
   jadual kerja, bukan arkib: yang hidup ada id 204–381, baris 1–203 sudah
   dipadam. HKK 2022/2023/2024 dan FKJ 2023 kehilangan **semua** pesertanya.
4. **`crafttype` berkod hanya 11%** — 2,201 baris ada `-KOD-SAIZ`, 18,218 nama
   kosong tanpa kod dan tanpa saiz.

Jadi **import `AACB` sahaja**: 175 peserta, 174 peruntukan, pasangan hidup
lengkap, dan satu-satunya data yang membawa kod kraf serta saiz. Sejarah
2011–2024 kekal dalam fail dump, bukan dipaksa menjadi `events`.

Satu amaran tentang pautan: semakan "id ini ada dalam **mana-mana** jadual
`usahawan*`" kuat untuk membuktikan ketiadaan, **lemah** untuk membuktikan
kehadiran — id ialah integer kecil yang diguna semula merentas jadual. Hanya
pasangan bernama sama (`ukhemah_day1_2pm` ↔ `usahawan_day1_2pm`) boleh
dipercayai.

### Dua arahan: `import:legacy` dan `legacy:accounts`

`import:legacy <fail.sql>` memuatkan dump ke tiga jadual staging dan **tidak
memutuskan apa-apa** — ia menyalin teks dan melaporkan apa yang dilihatnya.
`--dry-run`, `--append`, `--only=<prefiks>`. Pada dump penuh: 21,031 baris dalam
5.7 detik, sifar kegagalan.

`App\Support\LegacyDump` **memburu statement `insert`**, bukan memecah fail pada
`;`. Memecah pada `;` nampak betul dan tidak: export ini memotong setiap
`CREATE TABLE` di tengah definisi **tanpa koma bertitik**, jadi pembaca
`;` melekatkan CREATE yang rosak pada INSERT selepasnya dan baris itu hilang
senyap. Komen SQL antara statement buat perkara sama. Itu satu larian ujian
penuh untuk disedari.

`legacy:accounts` (local/testing/e2e sahaja) membina akaun log masuk daripada
staging: pegawai pertama jadi super, kedua jadi hq, dan setiap zon dalam dump
dapat satu admin negeri — **peranan ditetapkan, bukan dimigrasi**, kerana
`UserRight` hanya ada satu nilai. Peserta AACB jadi `users` + `companies`, dengan
`kraf_code` dan `plot_size` dihurai dari `crafttype` (pecah dari **kanan**).

Dua perkara yang menggigit dan kini dikunci ujian:

- **Domainnya `legacy.test`, bukan `demo.test`.** `--fresh` memadam ikut domain,
  dan `DemoSeeder` menanam akaunnya pada `demo.test`. Berkongsi domain bermakna
  `--fresh` memadam data demo juga — yang memang berlaku sebelum ini.
- **Dua pegawai berkongsi satu `loginname`** (id 5 dan 25, kedua-duanya
  `haida`). `updateOrCreate` pada email bertembung **tidak gagal** — ia mengemas
  kini baris pegawai pertama, jadi yang kedua merampas peranan yang sudah
  diberi. Pada dump sebenar: 17 admin dibina, 16 baris wujud, dan admin hq
  **hilang tanpa sebarang amaran**. Slug pertama menang; yang kemudian membawa
  `legacy_id`.

### `legacy:emails` — satu-satunya pintu kepada penggunaan sebenar

Dump **tiada lajur email dan tiada lajur telefon** — bukan kosong, tetapi tiada.
Pesertanya tidak pernah log masuk; hanya pegawai, dengan `loginname`. Jadi
175 peserta import terkunci sepenuhnya, dengan sengaja: kata laluan rawak dan
alamat pada `legacy.test` yang tidak boleh terima mel.

`legacy:emails <fail.csv>` memberi mereka alamat sebenar, dan **sebaik alamat
itu benar, aliran Lupa Kata Laluan biasa mula berfungsi**. Arahan ini **tidak
menetapkan dan tidak menghantar kata laluan** — peserta membuktikan alamat itu
miliknya dengan menggunakannya.

**Dipadan pada IC**, diukur: merentas 175 peserta, IC unik 175 cara, tidak
pernah kosong, dan **kekal unik selepas dinormalkan kepada digit** — yang wajib,
kerana dump menulis kedua-dua `890508-12-0000` dan `800601100000`.
`community_id` **tidak boleh** jadi kunci sendiri: ia unik 173 cara sahaja dan
satu baris tiada.

**Selamat di pelayan**, tidak seperti `legacy:accounts`: ia tidak menanam akaun
dan tidak tahu sebarang kata laluan; ia memindahkan alamat ke baris yang sudah
ada. Ia enggan menyentuh peserta yang sudah ada alamat sebenar melainkan
`--replace`.

Dua perkara yang dikunci ujian:

- **Seluruh fail dirancang sebelum satu baris ditulis.** Fail yang memberi satu
  alamat kepada dua orang menggugurkan **kedua-dua** baris, bukan menerima yang
  pertama — tiada apa dalam fail itu menyatakan yang mana pejabat maksudkan,
  jadi menerima yang pertama bermakna menulis lambungan syiling ke dalam
  identiti seseorang.
- **Disimpan melalui model, bukan query builder**, supaya `Auditable` merekod
  siapa menukar alamat siapa dan dari apa. Kata laluan dikecualikan daripada
  jejak itu dan mesti kekal dikecualikan.

### Zon lama **mesti** diterjemah, tidak boleh disimpan mentah

`App\Support\LegacyZones` memetakan nama zon lama kepada zon undilot. Ia bukan
kemasan — ia pembetulan pepijat yang diukur pada baris sebenar.

Dump membawa **18 nama zon dan 7 bukan zon undilot** (`P.Penang`,
`N.Sembilan`, `Selangor`, `Kuala Lumpur`, `Sabah`, `Kedah(Langkawi)`,
`P.Langkawi (Kedah)`). Pejabat sendiri tidak konsisten: dump ada **kedua-dua**
`N.Sembilan` dan `Negeri Sembilan`, **kedua-dua** `P.Penang` dan `Pulau Pinang`.

Disimpan mentah, skop **nampak** berfungsi — baris import salah dengan cara yang
sama, jadi admin lama padan syarikat lama. Yang pecah ialah **borang**: setiap
dropdown zon dibina daripada `AdminZones`, jadi syarikat pada `'P.Penang'`
membuka borang suntingannya dengan **tiada option `selected`**, dan simpanan
seterusnya memindahkannya ke zon pertama dalam senarai — tanpa ralat, tanpa
pengesahan. Diukur pada baris sebenar, bukan dibayangkan. Itu persis kegagalan
yang docblock `AdminZones` sendiri beri amaran.

Dua zon lama bergabung jadi satu (`Selangor` + `Kuala Lumpur` →
`Selangor, Kuala Lumpur dan Putrajaya`), dan satu zon tetap satu admin.
Sasaran diselesaikan terhadap **senarai hidup**, bukan salinan — zon ialah
`ManagedList` yang boleh dinamakan semula. Nama tanpa pemetaan **melontar**,
tidak disimpan mentah.

`AdminZones::rename()` **menolak nama zon lain**, tidak peka huruf besar (MySQL
membandingkan lajur ini begitu juga) — jika tidak, dua zon bergabung senyap
(K08). Menukar huruf besar/kecil nama sendiri dibenarkan.

### `legacy:transform-aacb` membina satu acara, bukan sejarah

Ia membina acara AACB penuh daripada staging: pelan (4 pasangan lokasi/saiz),
175 plot, 174 pendaftaran, 174 preset dan 174 undi. Silang-semak terhadap dump
lulus kesemuanya — `plot_code` dari dump, plot padan `usahawan.lot`, kod
pendaftaran = kod plot, tiada plot ditanda kategori, tiada baris belum disahkan.

Keputusan yang dibuat di sini dan sebabnya:

- **`plot_code` ialah `boothid` pejabat**, bukan `Plot::codeFor()`. Pejabat
  tulis `TBP01`; penjana akan tulis `BASEMENT-TBP1`. `usahawan.lot` padan dengan
  `boothid` pada **kesemua 174** peruntukan, dan id itulah yang dicetak pada
  gerai dan slip.
- **`use_preset` dan `allocation_type = 'preset'`**, kerana `lot` = `preset`
  pada kesemua 175 baris: pejabat menetapkan setiap gerai, peserta mengesahkan.
  Membina semula seolah-olah algoritma yang menjalankannya ialah acara lain.
- **Setiap pendaftaran ditulis `verified_at`**, kerana cabutan itu **selesai**.
  `PollingGuard` menahan cabutan selagi ada baris belum disahkan, jadi acara
  yang sampai ke penghujung sudah melalui kedua-dua semakan.
- **Tetingkap cabutan dibaca dari `votetime`** (2025-10-06 10:02 → 13:54), bukan
  direka. **Tajuk, tempat dan tarikh acara pula tiada dalam dump** — pilihan
  arahan membekalkannya dan laporan menyatakan yang mana tekaan.
- **Pemangkasan ruang berlaku di sini**, bukan di staging. `'RKP01 '` jadi
  `'RKP01'`; staging menyimpannya utuh kerana membersih ialah keputusan.
- Arahan **berhenti** jika ada kod kraf tiada pada helaian pengekodan — plot
  dengan kod tak dikenali tidak boleh dipadankan oleh mana-mana pendaftaran.

`--fresh` membuang pendaftaran **dahulu**, kemudian acara: `plot_id` tiada
klausa `ON DELETE`, jadi pangkalan data menyekat pembuangan plot yang masih
ditunjuk.

### Pembersihan yang data sebenar sudah tunjukkan perlu

- **Ruang hujung**: `boothid` `'TBA06 '`, `comid` `'10-1-62  '`, `'14-1-14   '`.
- **Zero-date benar-benar ada**: `votetimepay` = `'0000-00-00 00:00:00'` dalam
  setiap baris sampel.
- **IC dua format**: `'890508-12-0000'` berbanding `'800601100000'`.
- **Huruf besar/kecil tidak sepadan antara jadual**: `usahawan.location` =
  `'KHEMAH A'` sedangkan `ukhemah.khemah` = `'Khemah A'`. Join mesti normalisasi.
- **Bendera bercampur bahasa**: `undi` = `'Yes'`, `bayarstatus` / `sstatus` =
  `'Ya'` / `'Tidak'`.

Transform dari staging ke jadual aplikasi yang membuat keputusan. **Jangan**
tambah lajur status (`sstatus`, `bayarstatus`, `Protokol`) kepada
`event_registrations` untuk menampung dump — model dua-semakan sudah
menggantikannya, dan `PollingGuard` serta `EventRegistration::booted()`
bergantung padanya.

---

## Bila menyalin daripada `daftar`

Empat perkara menggigit setiap kali:

1. **`LotAllocator` dan `PlotAllocationAlgorithm` ada dalam `app/Services/`**,
   bukan `app/Support/`, dan kedua-duanya dipanggil FQCN *inline* tanpa `use`.
   Grep untuk `use App\Support\LotAllocator` tidak menjumpai apa-apa.
2. **`app/Exceptions/LotUnavailable.php`** ialah mod kegagalan kongsi kedua-dua
   pengalokasi, dan tiada dalam mana-mana senarai `Support/`.
3. **Komen membawa rujukan mati** — "backlog entry 17", "office question 15b",
   dan beberapa ayat yang berakhir dengan "See" tanpa apa-apa selepasnya. Jadikan
   ia berdiri sendiri; rujukan yang tidak membawa ke mana-mana lebih buruk
   daripada tiada rujukan.
4. **Kod mati untuk data lama.** `daftar` membawa cabang untuk rekod yang
   mendahului sesuatu migration. Pangkalan data kosong tidak boleh mengandungi
   satu pun. Periksa sebelum menyalin.

---

## Ujian

```
vendor/pestphp/pest/bin/pest              # SQLite dalam-memori
DB_CONNECTION=mysql DB_DATABASE=undilot2026_test vendor/pestphp/pest/bin/pest   # MySQL, pangkalan data sendiri
npx playwright test                        # MySQL undilot2026_e2e, melalui tests/e2e/serve.js
```

### Suite Pest pada MySQL, dan apa yang ia jumpa

Pemboleh ubah persekitaran menang atas `phpunit.xml`, jadi baris kedua di atas
menghalakan suite yang sama ke `undilot2026_test` (cipta sekali,
`utf8mb4_unicode_ci`). **Jangan** halakan ia ke `undilot2026`:
`RefreshDatabase` mengosongkan apa sahaja yang ditunjuk.

Larian pertama (2026-11-15) gagal empat ujian yang lulus pada SQLite, dan tiada
satu pun pepijat aplikasi:

- **Lajur `json` MySQL menyusun semula kunci** (ikut panjang, kemudian abjad).
  `toBe` pada tatasusunan bersekutu yang dibaca dari `audit_trails.old_values`
  / `new_values` gagal atas susunan sahaja. Guna **`toEqual`** untuk nilai dari
  lajur `json`.
- Ujian "refuses to delete a plot" hanya membaca `pragma foreign_keys` — tidak
  pernah cuba memadam, dan ralat sintaks pada MySQL. Kini ia benar-benar cuba,
  pada kedua-dua pemacu, dan disahkan gagal bila `plot_id` diberi
  `nullOnDelete()`.

Satu ujian dilangkau pada MySQL dengan sengaja (membaca `sqlite_master`).

### Suite pelayar memiliki persekitarannya sendiri

`tests/e2e/serve.js` menulis `.env.e2e` (dari `.env` + `tests/e2e/env.js`),
menetapkan semula `undilot2026_e2e`, dan menghidupkan pelayan **serta** pekerja queue.
Jangan jalankan suite terhadap pelayan yang dihidupkan dengan tangan.

`.env.e2e` itu **fail**, bukan pemboleh ubah proses, dengan sebab yang diukur:
`artisan serve` hanya meneruskan senarai pemboleh ubah tertentu kepada anaknya.
`APP_ENV` lepas, `DB_DATABASE` tidak — larian pertama menghidang pangkalan data
**dev**. `fixtures/run.php` menolak sebarang pangkalan data yang bukan `*_e2e`.

### Port berputar setiap larian, dan itu pembetulan bukan hiasan

Pada port tetap suite ini **berhenti bermula langsung** pada Windows, dan
ralatnya menyalahkan benda yang salah: `Timed out waiting 180000ms from
config.webServer`, sedangkan pelayan di atasnya sudah mencetak *Server running*.

Diukur: **1,442 soket TIME_WAIT pada port 8124 sahaja**. Setiap poll kesediaan
membuka sambungan baharu, Windows menahan setiap satu beberapa minit, dan
larian menimbun pada port yang sama. Sebaik 4-tuple satu poll berlanggar dengan
yang berlegar, SYN itu **digugurkan** dan bukan ditolak — jadi permintaan
tergantung dan bukan gagal, dan semakan kesediaan Playwright tiada tamat masa
per-permintaan, jadi satu poll tergantung memakan kesemua 180 saat.

**Apa yang TIDAK terbukti:** kiraan TIME_WAIT seluruh mesin pernah mencecah
16,735 berbanding julat ephemeral 16,384, dan pada ketika itu sambungan keluar
masih berjaya (3/3 HTTP 200 di bawah 150ms). Jadi ini perlanggaran
**per-port**, bukan kolam yang habis. Jangan tulis cerita kehabisan kolam di
sini tanpa mengukurnya semula.

Diukur semula 2026-11-15 dengan `tests/e2e/probe.ps1`, pada komit yang pernah
gagal 8–9 spek berturut-turut: enam larian (tiga berjarak, tiga berturut),
**kesemuanya 21/21, sifar flaky**. Satu larian menambah ~350 TIME_WAIT; puncak
terburuk memegang **560 port ephemeral daripada 16,384**. Suite ini tidak boleh
menghasilkan 18,643 TIME_WAIT yang pernah dilihat — sumbernya **tidak
diketahui**, kerana tiada siapa merekod pecahannya ketika itu. Jadi
`netsh int ipv4 set dynamicport` tiada asas. Bila kegoyahan kembali, jalankan
suite **melalui** `probe.ps1`, supaya bukti diambil semasa kegagalan, bukan
selepasnya.

Tandanya ada dalam `DEBUG=pw:webserver`: poll awal menjawab `ECONNREFUSED`
dalam milisaat, kemudian satu baris `HTTP GET` duduk sendirian selama tiga
minit. Itu SYN digugurkan, bukan pelayan perlahan.

`playwright.config.js` kini memilih port dalam julat 8200–8799 dan
**menulisnya semula ke `process.env`**. Itu bahagian yang menggigit: config
dinilai semula dalam setiap proses pekerja, dan pekerja ialah anak dengan pid
sendiri — menerbitkan port daripada pid sahaja memberi pekerja port **berbeza**
daripada pelayan, dan setiap spek gagal dengan `net::ERR_ABORTED` terhadap port
yang tiada sesiapa mendengar. `PLAYWRIGHT_PORT` masih menang bila ditetapkan.

### Yatim daripada larian terganggu ialah enjin sebenar

`serve.js` **menyapu yatim sebelum ia bermula**, dan itu bukan kebersihan — ia
pembetulan. Bila larian terganggu keras (tamat masa pembungkus, Playwright
terhempas, terminal ditutup) tiada isyarat tiba, dan pada Windows membunuh node
tidak pernah mencapai cucu yang `shell: true` cipta. `artisan serve` dan
`queue:work` terus hidup.

Pekerja yang dilupakan **bukan melahu**: `--sleep=1` bermakna ia menyoal MySQL
sekali **sesaat**, setiap satu sambungan baharu. Diukur: 4,328 TIME_WAIT ke
`127.0.0.1:3306` daripada yatim, selain 1,442 pada port tetap itu sendiri.

Dua perkara yang menggigit semasa membinanya:

- **`child.pid` ialah pid SHELL, bukan PHP.** Merekod pid ke fail tidak
  berguna: shell mati, PHP hidup dengan pid yang tiada siapa catat. Sapuan
  memadan **baris arahan**, bukan pid.
- **Laluan Windows tidak peka huruf besar; padanan string peka.** Node
  menyelesaikan `F:\herd\undilot2026` sedangkan senarai proses mengeja
  `F:\Herd\undilot2026`, jadi `includes()` memadan tiada apa dan sapuan
  mengambil satu proses daripada tiga. Dibandingkan huruf kecil sekarang.
- **`artisan serve` ialah penyelia.** Bunuh hanya router di bawahnya dan ia
  memulakan yang baharu. Induk dicapai melalui anak, bukan dipadan terus —
  argumen penyelia tidak menamakan projek, dan pembangun mungkin menjalankan
  satu sendiri.

### `--no-reload` + `PHP_CLI_SERVER_WORKERS`, bersama atau tiada langsung

Tanpa `--no-reload`, artisan mengabaikan `PHP_CLI_SERVER_WORKERS` dan melayan
**satu permintaan pada satu masa** — ia mencetak amaran dan teruskan. Satu
permintaan pada satu masa salah untuk suite ini: spec mengklik sambil pekerja
queue bercakap dengan MySQL, dan permintaan beratur di belakang satu sama lain
sehingga `page.check` dan `page.selectOption` mencecah had 30s Playwright tanpa
apa-apa sebenarnya rosak.

Diukur sebelum dan selepas, lima larian penuh setiap satu:

| | sebelum | selepas |
|---|---|---|
| larian hijau penuh | 3 / 5 | **4 / 5** |
| masa larian berturut | merosot 40s → 1.8m | **tetap ~38s** |

Memuat semula tiada nilai di sini: pelayan dihidupkan segar setiap larian dan
kod tidak boleh berubah di bawahnya.

**Tetap baca kiraan `flaky`.** Satu larian hijau masih bukan bukti.

Log masuk dalam spec melalui `/auto` (berdaftar dalam `local`, `testing`, `e2e`
sahaja) — tiada kata laluan ditaip. `@playwright/test` dipin pada versi yang
sepadan dengan Chromium dalam cache; menaikkannya bermakna memuat turun pelayar.

Hanya MySQL boleh menguji dua transaksi bertindih. `03-concurrent-confirm`
membuktikan kunci pada `LotAllocator`; ia disahkan gagal bila
`lockForUpdate()` dibuang.

`RefreshDatabase` dikenakan pada `Feature` tetapi **bukan** `Regression`. Ujian
yang perlu menanam keadaan yang skema larang — `plot_id` tergantung, dengan
menogol `PRAGMA foreign_keys` — tidak boleh berbuat demikian di dalam transaksi.

**Satu larian hijau bukan bukti pada suite pelayar.** Ini dipelajari dengan mahal
dalam `daftar`: empat larian penuh pada kod yang sama memberi empat set kegagalan
berbeza. `playwright.config.js` membawa `retries: 1` dan
`trace: 'on-first-retry'` — **baca kiraan `flaky`**, bukan kiraan `passed`, dan
jalankan lebih daripada sekali sebelum mempercayai keputusan.

Jangan tulis `page.once('dialog', d => d.accept())`. Guna `acceptDialog()` dalam
`tests/e2e/helpers.js`, yang merekod jenis dan teks dialog; `strayAlerts()`
digagalkan dalam `afterEach`. `alert` yang diterima secara buta ialah tindakan
yang tidak berlaku, dan ujian gagal empat baris kemudian atas sesuatu yang tidak
berkaitan.

Dalam ujian Pest, `actingAs()` tanpa guard menggunakan guard **lalai**, yang
permintaan admin terdahulu dalam ujian yang sama mungkin telah tukar kepada
`admin`. Namakan guard (`actingAs($user, 'web')`) dalam ujian yang mencampur
kedua-duanya, dan `auth()->forgetGuards()` sebelum menguji akses orang lain.

### Jangan beri mesej kepada `toContain` — ia needle, bukan mesej

`toContain` ialah **variadic**. `expect($x)->not->toContain('check (', 'mesej')`
membaca "mesej" sebagai needle **kedua**, dan `not->toContain` lulus sebaik
**mana-mana** needle tidak hadir. Mesej penerangan tidak pernah hadir dalam
nilai, jadi assertion itu lulus tanpa pernah menimbang needle pertama.

Ini bukan teori. Pengawal enum dalam
`tests/Feature/SchemaHoldsTheSameShapeOnBothDriversTest.php` ditulis begitu dan
**mati sepanjang hayat fail** — diuji dengan menyelit lajur `enum` betul ke dalam
skema: bentuk lama lulus, bentuk satu-needle gagal. Ujian yang CLAUDE.md sendiri
namakan sebagai pengunci peraturan tiada-enum tidak mengunci apa-apa.

`toBe`, `toBeTrue`, `toBeNull` dan `toThrow` **memang** terima mesej sebagai
argumen kedua. Hanya `toContain` tidak. Bila mesej diperlukan bersama
pemeriksaan "tidak mengandungi", tulis perbandingan eksplisit dan letak mesej
pada `toBeTrue`, dan simpan penerangan dalam komen.

`toContain` positif dengan beberapa needle (`toContain('a', 'b', 'c')`) adalah
sah dan bermakna — semua needle mesti hadir. Yang berbahaya ialah bentuk
**bernegatif**.
