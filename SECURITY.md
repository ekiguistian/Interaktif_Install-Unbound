# Keamanan

## Akses web

- Dashboard bisa dilihat oleh siapa pun yang dapat membuka port `8000`. Perubahan sistem memerlukan login admin.
- Batasi akses HTTP ke jaringan privat atau VPN. Gunakan reverse proxy HTTPS jika login diakses dari Internet.
- Ubah username dan password admin di `/etc/unbound-web/.env`. Simpan file ini di luar direktori publik dengan pemilik `root:www-data` dan izin `0640`.
- Batasi sudo untuk `www-data` ke `/usr/local/sbin/unbound-web-manage`. Pastikan file tersebut hanya bisa diubah oleh root.

## DNS dan firewall

- Isi **IP Allow** dengan subnet klien yang dipercaya. Sesuaikan contoh `192.168.1.0/24` dengan jaringan sendiri.
- Jangan membuka recursion DNS ke `0.0.0.0/0` atau `::/0` pada server yang dapat diakses dari Internet.
- Periksa alamat interface, sumber SSH, port web, dan alamat Zabbix sebelum mengaktifkan UFW. Installer membiarkan filter UFW mengikuti status yang sudah tersimpan.
- Pakai sumber blocklist dan whitelist HTTPS yang dipercaya. Web Manager memeriksa IP publik sumber blocklist dan mengunduhnya tanpa mengikuti pengalihan.
- Wizard mempertahankan DNS sistem selama instalasi. Setelah uji DNSSEC, VM dapat beralih ke Unbound. Resolver LXC atau file yang dikelola host tetap dipertahankan. Jika perubahan DNS VM gagal, wizard memulihkan pengaturan sebelumnya.
- Pembaruan dari Settings memerlukan login admin dan memakai checkout milik root dari branch `main` repo resmi.
- Cadangkan konfigurasi sebelum menjalankan script CLI. Uninstaller menghapus data dan konfigurasi Unbound, mereset aturan UFW, lalu menonaktifkan firewall.

## Log dan pengujian

- Dashboard menampilkan metrik gabungan yang disimpan selama 30 hari. Log query bisa memuat alamat klien dan nama domain. Batasi akses ke log dan matikan pencatatan saat tidak diperlukan.
- Benchmark bisa memperlambat DNS. Jalankan saat beban rendah dan uji hanya server yang dikelola atau diizinkan pemiliknya. Web Manager menerima IPv4 privat atau loopback. CLI menguji `127.0.0.1`. Durasi maksimal lima menit.

## Laporan keamanan

Laporkan celah secara privat melalui GitHub jika tersedia, atau hubungi pengelola repo. Sertakan versi OS, nama script atau halaman, langkah untuk mengulang masalah, dan log yang sudah disamarkan. Hapus password, token, private key, IP internal, hostname produksi, dan URL daftar privat.
