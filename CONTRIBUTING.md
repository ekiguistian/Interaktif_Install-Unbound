# Kontribusi

1. Buat branch dari `main`.
2. Buat perubahan yang fokus pada satu masalah atau fitur.
3. Uji alur yang kamu ubah. Pakai VM Ubuntu untuk perubahan yang menyentuh layanan, cron, DNS, UFW, atau installer.
4. Buka pull request (PR). Tulis perubahan, cara menguji, hasil tes, dan bagian yang belum diuji. Tambahkan screenshot kalau tampilan berubah.

## Struktur proyek

- `public/` berisi halaman panel dan API.
- `app/` berisi fungsi yang dipakai bersama.
- `bin/` berisi worker dan helper untuk kontrol server.
- `install.sh` menyiapkan panel web di Ubuntu Server.

## Cek sebelum buka PR

```bash
find app bin public -name '*.php' -exec php -l {} \;
node --check public/assets/app.js
bash -n install.sh
python3 -m py_compile bin/manage.py
python3 -m py_compile bin/monitor.py
php tests/filters.php
```

Untuk perubahan login, cek akses halaman dan API saat belum login. Untuk editor konfigurasi, uji input valid, input salah, dan file yang berubah saat editor masih terbuka. Untuk grafik, bandingkan data panel dengan data dari server pada waktu yang sama.

Gunakan data contoh di issue, log, dan screenshot yang kamu bagikan.

<!-- System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource -->
