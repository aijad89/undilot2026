# undilot2026

Sistem cabutan lot untuk acara kraf Kraftangan Malaysia. Ini pecahan ringkas
daripada [`undilotlook`](https://github.com/aijad89/undilotlook) yang hanya
mengandungi lima modul.

| Modul | Route utama |
|---|---|
| Log masuk peserta | `/login`, `/forgot-password` |
| Log masuk admin (super / HQ / negeri, satu halaman) | `/admin/login` |
| Akses admin negeri (ditapis ikut zon) | peranan `state` pada akaun admin |
| Acara + senarai cabutan | `/admin/events`, `/admin/events/{event}/registrations`, `/events/{event}` |
| Cabutan lot | `/admin/polling`, `/events/{event}/lot` |

Yang dibuang: pengumuman, halaman footer, pautan sosial, skrin audit trail,
laporan CSV dan permohonan sertai — pejabat memasukkan setiap syarikat ke
senarai cabutan sendiri. Data sebenar akan dimuat dari CSV (belum ada).

## Pasang

```bash
composer install
cp .env.example .env
php artisan key:generate
# tetapkan DB_* dan ADMIN_SEED_EMAIL / ADMIN_SEED_PASSWORD dalam .env
php artisan migrate:fresh --seed
```

Ujian: `php artisan test` (Pest, SQLite dalam-memori).

Peraturan kerja dan keputusan reka bentuk ada dalam `CLAUDE.md`.

**Pembangun baharu:** mula dengan [`docs/serahan-pembangun.md`](docs/serahan-pembangun.md),
kemudian [`docs/aliran-sistem.md`](docs/aliran-sistem.md).
