# Catatan proyek Unbound DNS

## Lingkup dan riwayat

- Repository aktif adalah `ekiguistian/Interaktif_Install-Unbound`, branch `main`. Repository `ekiguistian/Configurasi-DNS-Resolver-Unbound` adalah repository lama.
- Proyek bermula dari generator konfigurasi interaktif PowerShell/BAT, lalu beralih ke Bash untuk instalasi Linux. Tool terminal yang tersedia adalah `Install-cli.sh`, `Uninstall.sh`, dan `Tes.sh`; nama `Install-cli.sh` membedakan installer CLI dari `install.sh` web pada filesystem Windows.
- Web Manager menargetkan Ubuntu Server 22.04 LTS Jammy. Aplikasi memakai PHP 8.4 Native, PDO/MySQL, Nginx port 8000, URL tanpa ekstensi, Tailwind CSS, Font Awesome, dan Chart.js. CLI punya matriks dukungan tersendiri di README.
- Percakapan sebelumnya pernah meminta penghapusan AGENTS.md. File ini dibuat ulang atas permintaan terbaru sebagai catatan percakapan dan keputusan proyek.

## Alur CLI

- Periksa OS, CPU, RAM, dan disk sebelum perubahan. Kenali dependensi yang sudah terpasang dan gunakan APT hanya saat ada kebutuhan. Panduan Debian mencakup pemasangan sudo dari akun root. Siapkan Git dan sertifikat CA sebelum clone.
- Form interaktif mencakup tipe host VM atau LXC, spesifikasi, perkiraan perangkat, sasaran cache, IP interface, ACL, PTR, logging, root recursion atau forwarder, blocklist/whitelist, dan Zabbix. Contoh laboratorium awal memakai 4 core dan RAM 4 GB. Angka cache hit adalah sasaran, bukan jaminan.
- Mode root memakai root hints dan validasi DNSSEC. TLD publik mengikuti delegasi DNS. Default forwarder adalah 1.1.1.1 dan 8.8.8.8 jika daftar tidak diisi. Gunakan IP privat dan `yourdomain.com` untuk contoh publik.
- Pengaturan daftar DNS CLI mencakup URL sumber blocklist dan whitelist serta tujuan redirect. Pengaturan Zabbix mencakup hostname dan alamat server. Aturan UFW CLI menangani DNS, HTTPS, dan sumber SSH menurut alur di README.
- Verifikasi resolver Internet sebelum instalasi dan uji query lokal serta DNSSEC sebelum VM diarahkan ke 127.0.0.1. LXC Proxmox mempertahankan resolver container dan menampilkan arahan pengaturan DNS di Proxmox.
- Rollback CLI memakai snapshot awal. Pulihkan konfigurasi, resolver, service, dan firewall yang dikelola installer. Hapus hanya paket yang dipasang pada percobaan itu. Uninstaller menampilkan rencana dan hasil setiap tahap serta meminta konfirmasi sebelum mereset UFW atau menghapus konfigurasi.
- Gunakan contoh generik. Terminal interaktif menampilkan OS, informasi hijau, proses biru, error/peringatan merah, dan indikator jam pasir untuk proses lama. `NO_COLOR=1` mematikan warna. Output konfigurasi dan ringkasan tetap berupa teks biasa.
- `Tes.sh` memakai 100 domain umum dan menguji 127.0.0.1 dengan dnsperf. Durasi maksimal lima menit. CLI menerima jumlah client, thread, dan QPS dari pengguna. README memuat panduan membaca log query/reply, resolusi, benchmark, dan hasil.

## Alur Web Manager

- `install.sh` memvalidasi Jammy, arsitektur dan resource, lalu memasang stack web, dnsperf, Git, UFW, dan dependensi kompilasi. Installer menyiapkan Nginx, MySQL, cron worker per menit, dan sudoers terbatas. Admin mengisi `/etc/unbound-web/.env` secara manual dari template. Unbound dan Zabbix dipasang dari web.
- Wizard admin memeriksa port 53 dan resolver, mengunduh source resmi NLnet Labs, melakukan build, memasang root hints, trust anchor DNSSEC, sertifikat control, konfigurasi, dan unit systemd. Periksa konfigurasi, DNS lokal, dan bit AD sebelum mengalihkan resolver host. Kegagalan wizard memulihkan resolver dan status systemd-resolved sebelumnya.
- Dashboard Live Monitor bersifat publik dan mengambil status serta metrik DNS/server melalui API. Navbar index hanya menampilkan **Install Wizard** sebelum Unbound terpasang atau **Setting** setelah terpasang.
- Navbar melayang Settings berisi **Unbound**, **Zabbix Agent**, **Firewall**, dan **Log Out**. Logout memakai POST dan CSRF.
- **Unbound** mengelompokkan form resolver, tampilan baca saja `unbound.conf`, toggle log query/reply terpisah, blocklist, whitelist, cron, dnsperf, dan domain uji. Validasi perubahan sebelum reload. Worker mengambil statistik Unbound dan server setiap menit dan menyimpan riwayat di MySQL.
- **Zabbix Agent** memuat instalasi, konfigurasi `Server`, `ServerActive`, dan `Hostname`, status, serta Enable, Disable, dan Restart. Enable dan Disable mengatur service saat ini dan saat boot. Agent berjalan melalui systemd.
- **Firewall** memuat status, Enable/Disable di dalam halaman, serta daftar, tambah, ubah, dan hapus aturan Allow/Deny arah IN/OUT. Form memakai port tujuan dan TCP/UDP. IP/CIDR adalah sumber untuk IN dan tujuan untuk OUT. Aturan tersimpan terlihat meski UFW nonaktif. Aturan lanjutan tampil baca saja. Penggantian port, arah, protokol, atau peer dapat mengubah urutan aturan.
- Login membaca kredensial dari `.env`. Pertahankan validasi input, CSRF, PDO prepared statements, dan dispatcher sudo yang hanya menerima aksi eksplisit. Unduhan blocklist memakai HTTPS dengan validasi alamat publik, tanpa mengikuti redirect. Output wizard dan proses panjang memakai SSE.

## Pelajaran implementasi dan verifikasi

- Kasus `unbound-anchor belum tersedia` pernah muncul setelah instalasi paket Debian. Periksa tersedianya binary dan pasang paket terpisah bila distro membutuhkannya. Untuk build source, cek hasil instalasi binary sebelum bootstrap DNSSEC.
- Kasus `cp: 'etc': No such file or directory` terjadi saat backup APT. Buat lokasi tujuan lebih dulu, gunakan path absolut, dan periksa keberhasilan backup sebelum mengubah sistem.
- Simpan state root di lokasi milik root. Unduh root hints ke file sementara, validasi isi dan izin, lalu ganti secara atomik. Pada build gunakan `nproc` dengan fallback `getconf`.
- Nginx meneruskan clean URL hanya ke front controller. Endpoint SSE memerlukan FastCGI buffering nonaktif. Pertahankan site Nginx lain yang sudah ada.
- Dokumentasi, label, dan ringkasan memakai bahasa Indonesia yang singkat. Jangan masukkan kredensial, nama host produksi, atau URL privat ke Git.
- Jalankan lint PHP, pemeriksaan sintaks Bash per file, `node --check public/assets/app.js`, dan `git diff --check`. Bedakan pemeriksaan Windows dari verifikasi runtime pada Ubuntu. Dokumentasikan instalasi, uninstall CLI, dnsperf, log, keamanan, kontribusi, dan alur push di README.
