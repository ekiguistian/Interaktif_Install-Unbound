# Catatan proyek Unbound DNS

## Lingkup dan riwayat

- Repository aktif adalah `ekiguistian/Interaktif_Install-Unbound`, branch `main`. Repository `ekiguistian/Configurasi-DNS-Resolver-Unbound` adalah repository lama.
- Proyek bermula dari generator konfigurasi interaktif. Tool terminal lama sudah dihapus. Gunakan `install.sh` untuk pemasangan Web Manager dan `update.sh` untuk pembaruan.
- Web Manager hanya mendukung Ubuntu Server 22.04 LTS Jammy dengan systemd aktif. Aplikasi memakai PHP 8.4 Native, PDO/MySQL, Nginx port 8000, URL tanpa ekstensi, Tailwind CSS, Font Awesome, dan Chart.js.
- Percakapan sebelumnya pernah meminta penghapusan AGENTS.md. File ini dibuat ulang atas permintaan terbaru sebagai catatan percakapan dan keputusan proyek.

## Alur Web Manager

- `install.sh` memvalidasi Jammy dan resource, lalu memasang PHP 8.4, Nginx, MySQL, dnsperf, Git, UFW, dan dependensi kompilasi. Installer mengaktifkan unit yang dibutuhkan, menyiapkan database dan cron worker, mendeteksi IP serta interface, dan membuat `/etc/unbound-web/.env` dengan izin `0640`. Password database berasal dari 10 byte acak. Kredensial dipakai kembali saat installer dijalankan ulang. Setelah web dan koneksi database lolos pemeriksaan, output terakhir adalah URL, Username, dan Password admin. Unbound dan Zabbix dipasang dari web.
- Wizard mempertahankan DNS sistem pada VM dan LXC, termasuk saat gagal. Setelah Unbound aktif, admin dapat mengubah atau menambah hingga tiga IP DNS sistem lewat Settings. Gunakan mount guard, validasi IP, pemeriksaan koneksi, dan rollback untuk perubahan yang diminta dari Settings.
- Jalankan perubahan sistem dari web sebagai unit systemd sementara melalui helper dengan daftar aksi tetap. Pertahankan pembatasan mount PHP-FPM.
- **Check for Update** di wizard dan Settings memanggil `update.sh` melalui aksi tetap. Gunakan checkout Git branch main milik root, pasang semua file terlacak dan helper, lalu cocokkan commit yang disajikan Nginx. `install.sh` menyiapkan Git saat pemasangan awal. Jaga kredensial di luar checkout dan web root.
- Dashboard Live Monitor bersifat publik dan mengambil status serta metrik DNS/server melalui API. Navbar index hanya menampilkan **Install Wizard** sebelum Unbound terpasang atau **Setting** setelah terpasang.
- Navbar melayang Settings berisi **Unbound**, **Zabbix Agent**, **Firewall**, dan **Log Out**. Logout memakai POST dan CSRF.
- **Unbound** mengelompokkan form resolver, tampilan baca saja `unbound.conf`, toggle log query/reply terpisah, blocklist, whitelist, cron, dnsperf, dan domain uji. Validasi perubahan sebelum reload. Worker mengambil statistik Unbound dan server setiap menit dan menyimpan riwayat di MySQL.
- **Zabbix Agent** memuat instalasi, konfigurasi `Server`, `ServerActive`, dan `Hostname`, status, serta Enable, Disable, dan Restart. Enable dan Disable mengatur service saat ini dan saat boot. Agent berjalan melalui systemd.
- **Firewall** memuat status, Enable/Disable di dalam halaman, serta daftar, tambah, ubah, dan hapus aturan Allow/Deny arah IN/OUT. Form memakai port tujuan dan TCP/UDP. IP/CIDR adalah sumber untuk IN dan tujuan untuk OUT. Aturan tersimpan terlihat meski UFW nonaktif. Aturan lanjutan tampil baca saja. Penggantian port, arah, protokol, atau peer dapat mengubah urutan aturan.
- Login membaca kredensial dari `.env`. Pertahankan validasi input, CSRF, PDO prepared statements, dan dispatcher sudo yang hanya menerima aksi eksplisit. Unduhan blocklist memakai HTTPS dengan validasi alamat publik, tanpa mengikuti redirect. Output wizard dan proses panjang memakai SSE.

## Pelajaran implementasi dan verifikasi

- Pastikan binary `unbound-anchor` tersedia dari paket Ubuntu atau hasil build source sebelum bootstrap DNSSEC.
- Installer lama bisa meninggalkan repository MySQL Oracle dan keyring yang kedaluwarsa. Tangani pemulihan di `install.sh` sebelum APT. Cocokkan entri lama, cadangkan bila paket Community belum ada, atau perbarui keyring secara atomik setelah fingerprint dan tanda tangan Oracle lolos verifikasi.
- Kasus `cp: 'etc': No such file or directory` terjadi saat backup APT. Buat lokasi tujuan lebih dulu, gunakan path absolut, dan periksa keberhasilan backup sebelum mengubah sistem.
- Simpan state root di lokasi milik root. Unduh root hints ke file sementara, terima record NS root dengan kelas IN eksplisit atau implisit, validasi izin, lalu ganti secara atomik. Jalankan `configure` dan `make` dari direktori source. `unbound-anchor` dapat mengembalikan status 1 saat trust anchor berhasil dipasang, sehingga periksa file hasil dan uji DNSSEC sebelum menyatakan wizard berhasil.
- Nginx meneruskan clean URL hanya ke front controller. Endpoint SSE memerlukan FastCGI buffering nonaktif. Pertahankan site Nginx lain yang sudah ada.
- Dokumentasi, label, dan ringkasan memakai bahasa Indonesia yang singkat. Jangan masukkan kredensial, nama host produksi, atau URL privat ke Git.
- Jalankan lint PHP, pemeriksaan sintaks Bash per file, `node --check public/assets/app.js`, dan `git diff --check`. Bedakan pemeriksaan Windows dari verifikasi runtime pada Ubuntu. Dokumentasikan instalasi, pembaruan, pengaturan DNS, dan pemeriksaan Web Manager di README.
