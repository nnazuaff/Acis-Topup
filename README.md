# Topup (PHP Native) - Serpul H2H Testing

Struktur ini disiapkan untuk Hostinger shared hosting dengan root subdomain di `/public_html/topup`.

## 1) Setup Database

1. Buat database + user di hPanel.
2. Jalankan SQL di `app/schema.sql`.
3. Isi kredensial DB di `app/config/database.php`.

## 2) Isi Config Serpul

Edit `app/config/serpul.php`:

- `member_id`, `pin`, `password` (atau isi `api_key` bila dashboard Anda menyebutnya API Key)
  - `member_id` boleh format `SP2` atau `2` (otomatis dinormalisasi jadi angka)
- `base_url` (domain H2H Anda, contoh: `https://acispay.serpul.co.id`)
- `endpoints.trx` (default `/without-sign/trx`)
- `ip_resolve`:
  - Jika whitelist Serpul menggunakan IPv4 dan hosting Anda punya IPv6, set `v4` (default) agar request tidak dianggap berasal dari IPv6.

Catatan: berdasarkan GitBook Serpul H2H, koneksi customer memakai template seperti OTOMAX dengan Path Center `/without-sign/trx`.

## 3) Setup Pusher

Edit `app/config/pusher.php`:

- `app_id`, `key`, `secret`, `cluster`

Channel realtime:

- Channel: `transaction-{trx_id}`
- Event: `status-update`

## 4) URL Penting

- Order page: `/index.php`
- Status realtime: `/status.php?trx_id=TRX...`
- Callback Serpul (public HTTPS): `/callback` (recommended)
- Alternate (tetap jalan): `/callback.php`

Catatan:
- `GET /callback` akan balas JSON 200 untuk kebutuhan tombol "Check URL" di dashboard Serpul.
- Callback real tetap `POST` (diproses oleh `app/api/callback_handler.php`).

## 5) Flow Testing Cepat

1. Buat order dari `/index.php`.
2. Buka status realtime dari link yang diberikan.
3. Simulasikan callback ke `/callback` atau `/callback.php` (POST JSON/form) dengan `apikey` + `pin` yang benar.

## 6) Catatan Serpul H2H

- Request transaksi memakai path center: `/without-sign/trx` (GET).
- Response dari Serpul sering berupa teks (mis. "Sedang diproses", "SUKSES", "GAGAL"). Sistem akan parsing teks itu untuk update status DB + Pusher jika jelas sukses/gagal.

## 7) Logs

- Callback log: `app/logs/callback.log`
- Serpul request/response (masked): `app/logs/serpul.log`

Contoh payload callback (sesuaikan field Serpul):

```json
{
  "apikey": "SERPUL_API_KEY",
  "pin": "SERPUL_PIN",
  "trx_id": "TRX...",
  "status": "SUCCESS",
  "message": "Done"
}
```
