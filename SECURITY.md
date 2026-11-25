# Keamanan

Laporkan masalah keamanan secara privat lewat **Security → Report a vulnerability** di GitHub. Kalau opsi itu belum tersedia, minta kanal privat ke pengelola proyek.

Isi laporan dengan versi atau commit, fitur yang terdampak, cara mengecek masalah di lingkungan uji, dan dampaknya. Sensor password, cookie, token, private key, alamat internal, dan konfigurasi server.

## Jaga akses panel

- Berikan akun panel hanya ke admin server dan batasi akses HTTPS ke jaringan admin atau VPN.
- Simpan `/etc/unbound-web/.env` dan private key TLS di penyimpanan dengan akses terbatas.
- Kalau password bocor, ganti `ADMIN_PASS` di `/etc/unbound-web/.env` dan akhiri sesi aktif. Periksa juga konfigurasi dan perubahan terakhir pada layanan.
- Kalau private key TLS bocor, ganti key dan sertifikatnya. Perbarui sertifikat yang dipercaya di perangkat admin.

## Penggunaan produksi

- Batasi rekursi Unbound dan akses TCP/UDP 53 ke jaringan klien yang dilayani. Contoh konfigurasi menolak rekursi dari IPv4 di luar ACL.
- Editor web menerima include absolut milik root di direktori layanan. Atur modul eksternal dan opsi yang menjalankan perintah melalui terminal server.
- Pembaruan panel menjalankan installer dari commit GitHub `main` yang dipilih. Hash memastikan checkout cocok dengan pilihan di panel. Lindungi branch dan akun GitHub yang bisa menulis ke repo.
- Jalankan dnsperf saat beban rendah. Batas panel adalah 16 client, 4 thread, 1.000 QPS, dan satu pengujian aktif.

<!-- System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource -->
