# Keamanan

Laporkan masalah keamanan secara privat lewat **Security → Report a vulnerability** di GitHub. Kalau opsi itu belum tersedia, minta kanal privat ke pengelola proyek.

Isi laporan dengan versi atau commit, fitur yang terdampak, cara mengecek masalah di lingkungan uji, dan dampaknya. Sensor password, cookie, token, private key, alamat internal, dan konfigurasi server.

## Jaga akses panel

- Berikan akun panel hanya ke admin server dan batasi akses HTTPS ke jaringan admin atau VPN.
- Simpan `/etc/unbound-web/.env` dan private key TLS di penyimpanan dengan akses terbatas.
- Kalau password bocor, ganti `ADMIN_PASS` di `/etc/unbound-web/.env` dan akhiri sesi aktif. Periksa juga konfigurasi dan perubahan terakhir pada layanan.
- Kalau private key TLS bocor, ganti key dan sertifikatnya. Perbarui sertifikat yang dipercaya di perangkat admin.

<!-- System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource -->
