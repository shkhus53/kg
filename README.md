<p align="center">
  <img src="img/kg_icon.png" alt="KG Attendance logo" width="120">
</p>

<h1 align="center">KG Attendance</h1>

<p align="center">Duty-roster import &amp; live attendance tracking for Khidmatguzars — Laravel backend, mobile-first PWA, native Android wrapper.</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-%5E8.3-777BB4?logo=php&logoColor=white" alt="PHP ^8.3">
  <img src="https://img.shields.io/badge/Laravel-%5E13.17-FF2D20?logo=laravel&logoColor=white" alt="Laravel ^13.17">
  <img src="https://img.shields.io/badge/license-MIT-blue" alt="License: MIT">
</p>

## What it is

KG Attendance runs the full lifecycle of a duty session: import a duty-roster Excel/CSV, mark people present or absent live as a session runs, and generate PDF/Excel reports afterward. It's a classic server-rendered Laravel + Blade app (no SPA) wrapped as an installable PWA, plus a thin native Android WebView shell for the same deployed site.

## Features

- **Session lifecycle** — draft → active → closing → closed, with row-locked transactional attendance mutations
- **Duty-list import** — Excel/CSV upload with preview, duplicate detection (fingerprint-based, cross-batch aware), and one-click commit
- **Live attendance marking** — search by ITS number or name, mark present/absent, bulk actions, absent→present correction
- **Extra Present** — record unscheduled attendees separately from the scheduled roster, never mixed into scheduled counts
- **Reports** — session, department, and per-person reports as PDF or Excel, always matching what the preview screen shows
- **Analytics & directory** — attendance-rate trends, department breakdowns, searchable Khidmatguzar directory with lifetime stats
- **ITS-based auth** — login by ITS number, no self-registration, role-gated (admin/operator/viewer)
- **Installable PWA** — add-to-homescreen, works like an app, no offline-attendance claims
- **Android app** — native WebView wrapper (`android/`) pointed at the deployed site, download/file-picker support built in

## Tech stack

- **Backend**: PHP 8.3, Laravel 13, `maatwebsite/excel`, `barryvdh/laravel-dompdf`
- **Frontend**: Blade, Tailwind CSS, Alpine.js, Vite
- **Android**: native WebView shell (Java, no Cordova/Capacitor)

## Screenshots

<!-- Drop PNGs into img/screenshots/ with these filenames and they'll render here. -->

| Login | Dashboard |
|---|---|
| ![Login](img/screenshots/login.png) | ![Dashboard](img/screenshots/dashboard.png) |

| Live Attendance | Reports |
|---|---|
| ![Live Attendance](img/screenshots/live-attendance.png) | ![Reports](img/screenshots/reports.png) |

## Setup

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

php artisan migrate
php artisan app:create-admin

npm run build
php artisan serve
```

`php artisan app:create-admin` prompts interactively for an ITS number, name, and password, and always creates an `admin` role account — there's no self-registration.

## Documentation

For a deep technical breakdown (data model, services, business rules, architecture) see [`docs/SOFTWARE_ANALYSIS.md`](docs/SOFTWARE_ANALYSIS.md).

## License

MIT.
