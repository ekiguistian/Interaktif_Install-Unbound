# Lapor masalah keamanan

Kalau kamu menemukan masalah yang bisa membuka akses panel, membocorkan kredensial, atau mengubah layanan tanpa izin, hubungi maintainer lewat kanal privat. Cek tab **Security** di GitHub dan pakai **Report a vulnerability** kalau tersedia. Kalau tombolnya belum aktif, minta kanal kontak privat ke maintainer tanpa mengunggah detail sensitif di issue.

Sertakan versi atau commit, menu yang terdampak, kondisi awal, langkah singkat untuk mengecek masalah di mesin uji, dan dampak yang kamu lihat. Sensor password, cookie sesi, token CSRF, private key, alamat internal, serta isi konfigurasi produksi.

## Akses panel

Akun panel punya kendali atas resolver, file agent, dan firewall. Berikan akses hanya ke admin server. Batasi akses HTTPS dari jaringan admin atau VPN. Simpan `/etc/unbound-web/.env` dan key TLS dalam backup yang aksesnya terbatas.

Kalau kredensial login bocor, ubah `ADMIN_PASS` di `/etc/unbound-web/.env`, akhiri sesi aktif milik panel di server, lalu periksa perubahan pada Unbound, Zabbix, cron, dan UFW. Jika private key TLS ikut bocor, ganti key beserta sertifikatnya dan perbarui trust di perangkat admin.
