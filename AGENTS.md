# AGENTS.md — Panduan Kerja Unbound DNS Web Manager

## Tujuan dan platform

Proyek ini menyediakan installer, pengelola, dan monitoring Unbound DNS melalui web untuk **Ubuntu Server 22.04 LTS (Jammy)**.

- Backend: PHP 8.4 Native, tanpa framework atau paket Composer. Setiap file PHP diawali `declare(strict_types=1);`.
- Web server: Nginx pada port 8000. Document root hanya `/opt/unbound-web/public`.
- Database: MySQL dengan PDO, prepared statements, `ERRMODE_EXCEPTION`, dan prepared statement native.
- Tampilan: HTML, Tailwind CSS CDN, FontAwesome 6 CDN, Chart.js CDN, dan JavaScript native.
- Semua label antarmuka dan panduan pengguna memakai bahasa Indonesia yang ringkas.

## Struktur repository saat ini

Pertahankan pemisahan tugas dan nama berikut. Tambahkan file hanya jika ada kebutuhan fitur yang nyata.

- `install.sh` — pemasang fondasi web pada Ubuntu 22.04. Memasang PHP 8.4, Nginx, MySQL, dnsperf, Git, paket build Unbound, membuat database, Nginx, cron worker, dan sudoers. Script ini **tidak** memasang Unbound ataupun Zabbix Agent.
- `app/bootstrap.php` — baca `.env`, sesi, dan koneksi PDO.
- `app/functions.php` — validasi input, autentikasi, CSRF, status sistem, dan helper umum.
- `app/CommandRunner.php` — eksekusi proses dengan argumen yang di-escape dan dukungan stream.
- `app/layout.php` — layout dan komponen tampilan.
- `public/index.php` — front controller untuk seluruh URL aplikasi.
- `public/pages/` — dashboard publik.
- `public/admin/` — halaman Settings.
- `public/api/` — API status, aksi admin, dan output SSE.
- `public/assets/` — CSS dan JavaScript browser.
- `bin/worker.php` — kumpulkan metrik per menit.
- `bin/blocklist-sync.php` — unduh, parse, filter, validasi, dan terapkan blocklist.
- `bin/dnsperf-run.php` — jalankan benchmark DNS.
- `scripts/manage.sh` — dispatcher root dengan aksi dan parameter allowlist.
- `scripts/push-github.sh` — commit perubahan dan push branch `main` ke URL GitHub yang diberikan.
- `database/schema.sql` — tabel metrik, blocklist, whitelist, domain uji, pengaturan, dan percobaan login.

## Alur pemasangan

1. Sebelum clone pada server kosong, jalankan `apt-get update` dan pasang `git ca-certificates`.
2. Jalankan `bash ./install.sh` sebagai root. Pemeriksaan OS dan resource harus selesai sebelum perubahan paket dilakukan.
3. Installer hanya menerima Ubuntu 22.04 Jammy. Minimum host: 1 CPU, RAM 2 GB, dan ruang bebas 4 GB di `/var`. Arsitektur yang didukung amd64 dan arm64.
4. Installer memasang PHP 8.4 FPM beserta mysql, cli, curl, mbstring, xml; Nginx; MySQL; dnsperf; Git; `build-essential`, OpenSSL, Expat, libevent, bison, flex, wget, curl, UFW, cron, dan sudo.
5. Installer membuat database dan tabel, server Nginx port 8000, worker cron per menit, serta akses sudo `www-data` hanya ke dispatcher root internal. UFW menerima port web 8000 tanpa mengaktifkan firewall.
6. Admin menyalin `.env.example` ke `/etc/unbound-web/.env`, mengisi `DB_PASS`, `ADMIN_USER`, `ADMIN_PASS`, dan `NET_INTERFACE`, lalu mengatur permission `root:www-data` mode `0640`.

## URL bersih

- URL publik memakai `/`, `/wizard`, `/login`, `/settings`, `/api/metrics`, `/api/action`, dan `/api/stream` tanpa `.php`.
- Nginx harus mengarahkan semua route aplikasi ke `public/index.php` dan tetap melayani file asset statis.
- Request langsung ke URL `.php` diarahkan dengan HTTP 301 ke path tanpa ekstensi. Front controller memetakan path bersih ke file PHP internal.
- `/api/stats` boleh menjadi alias kompatibilitas untuk `/api/metrics`.

## Wizard Unbound dari source resmi

Wizard hanya berjalan setelah login admin dan memiliki lima tahap dengan output langsung:

1. Verifikasi Jammy, dependensi, CPU/RAM/disk, port 53, dan resolver internet. Simpan isi serta target symlink `/etc/resolv.conf` dan status `systemd-resolved` di direktori state root yang tidak bisa ditulis `www-data`.
2. Unduh `https://nlnetlabs.nl/downloads/unbound/unbound-latest.tar.gz`, periksa nama path arsip, lalu ekstrak ke `/usr/local/src/unbound`.
3. Jalankan konfigurasi dan build: `./configure --with-libevent --with-pthreads --with-ssl --sysconfdir=/etc/unbound --prefix=/usr/local`, `make -j"$(nproc)"`, `make install`, dan `ldconfig`.
4. Buat user sistem `unbound`, unduh root hints Internic ke `/etc/unbound/root.hints`, buat DNSSEC trust anchor di `/var/lib/unbound/root.key`, sertifikat `unbound-control`, konfigurasi, dan systemd unit.
5. Jalankan `unbound-checkconf`, mulai service, uji query lokal dan bit AD DNSSEC. Setelah seluruh pemeriksaan lolos, ubah resolver host ke `127.0.0.1`.

Jika satu tahap gagal, stream memanggil `wizard-rollback` untuk mengembalikan resolver dan status `systemd-resolved` seperti sebelum wizard. Jangan menyimpan file state root di direktori yang dapat ditulis `www-data`. Recursive mode memakai root hints; jangan menambahkan forwarder secara diam-diam.

Sumber utama: [NLnet Labs installation guide](https://unbound.docs.nlnetlabs.nl/en/latest/getting-started/installation.html), [setup guide](https://www.nlnetlabs.nl/documentation/unbound/howto-setup/), dan [manual unbound-control](https://unbound.docs.nlnetlabs.nl/en/latest/manpages/unbound-control.html).

## Dashboard dan metrik

- Dashboard `/` dapat dibuka tanpa login dan mengambil data dari `/api/metrics` tanpa reload.
- Worker mencatat `stats_noreset`: QPS, jumlah query/reply, cache hit ratio, query type (`A`, `AAAA`, `PTR`, dan tipe lain yang tercatat), response code, serta waktu rekursi.
- Worker mencatat RX/TX interface, CPU, load 1/5/15 menit, RAM, disk, dan uptime. Simpan sejarah selama 30 hari.
- Dashboard menampilkan status service Unbound dan Zabbix Agent, versi Unbound, grafik QPS/cache hit/traffic satu jam, dan detail statistik DNS.
- Gunakan nama counter sebagaimana dokumentasi NLnet Labs. Aktifkan `extended-statistics: yes` dan `statistics-cumulative: yes` pada konfigurasi yang dibuat aplikasi.

## Settings wajib

- Login membaca `ADMIN_USER` dan `ADMIN_PASS` dari `.env`. Jangan sediakan UI/API untuk mengganti kredensial. Gunakan `hash_equals`, session cookie HttpOnly/SameSite, token CSRF, dan pembatasan percobaan login.
- Konfigurasi Unbound meliputi interface, port, access-control, threads, cache size/slabs, TTL, prefetch, serta DNSSEC hardening. Validasi setiap nilai dan jalankan `unbound-checkconf` sebelum reload. Tampilkan konfigurasi aktif secara read-only.
- Blocklist mendukung format Hosts, Adblock `||domain^`, dan plain domain; whitelist mengeluarkan domain beserta subdomainnya. Validasi semua URL sebagai HTTPS publik, pin download ke IP publik hasil validasi, jangan ikuti redirect, validasi konfigurasi hasil compile, lalu reload.
- Jadwal blocklist tersedia harian, mingguan, 6 jam, 12 jam, atau nonaktif. Pengelolaan cron custom hanya boleh menjalankan task aplikasi yang di-allowlist, seperti update blocklist dan root hints.
- Zabbix Agent dipasang melalui UI (Agent 2 jika tersedia, fallback Agent klasik), dikonfigurasi dengan Server/ServerActive/Hostname, dan mendukung lihat status, start, stop, restart.
- UFW Settings hanya mengelola rule incoming: port/protokol dan sumber IP/CIDR divalidasi. UFW tidak diaktifkan otomatis.
- dnsperf menerima target IPv4 loopback/private, port, durasi 1–300 detik, clients, threads, dan QPS. Validasi angka, batasi target, dan stream output.
- Domain uji mendukung CRUD domain dengan tipe DNS yang di-allowlist.

## Stream dan keamanan

- Wizard, Zabbix setup, sinkronisasi blocklist, dan dnsperf mengirim output berformat SSE dengan `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`, serta buffer PHP/Nginx dinonaktifkan.
- Jangan jalankan shell command dengan input form yang digabung ke string. Gunakan array argv, `escapeshellarg()` di `CommandRunner`, dan validasi allowlist sebelum memanggil dispatcher.
- Semua query dinamis memakai PDO prepared statements.
- Jangan beri `www-data` sudo umum ke `apt-get`, `make`, `systemctl`, `crontab`, atau shell. Batasi hanya ke `/usr/local/sbin/unbound-web-manage`; dispatcher root memeriksa aksi serta seluruh argumennya.
- Source aplikasi dimiliki root dan tidak dapat ditulis user web. File `.env`, database dump, log, hasil sementara, dan metadata lokal tidak boleh masuk Git.

## Perintah kerja dan verifikasi

Pada mesin server, perbarui paket dan pasang Git sebelum clone:

```bash
apt-get update
apt-get install -y git ca-certificates
git clone https://github.com/ekiguistian/Interaktif_Install-Unbound.git
cd Interaktif_Install-Unbound
bash ./install.sh
```

Sebelum perubahan PHP/Bash diserahkan, jalankan:

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;
bash -n install.sh scripts/*.sh
git diff --check
```

Pemeriksaan JavaScript yang dicantumkan panduan kontribusi:

```bash
node --check public/assets/app.js
```

Lakukan pemeriksaan syntax dan static review di workspace pengembangan. Jangan mengklaim instalasi/kompilasi Unbound di Ubuntu telah lolos bila hanya diperiksa dari Windows.

## Catatan dari pengalaman implementasi

- Jangan menganggap `unbound-anchor` selalu tersedia sebagai paket terpisah. Untuk build source, pastikan hasil instalasi menyediakan `/usr/local/sbin/unbound-anchor` sebelum membuat trust anchor. Untuk instalasi paket distro, verifikasi binary setelah pemasangan paket.
- Unduh `root.hints` ke file sementara di filesystem yang sama, pastikan file tidak kosong dan berisi record `IN NS`, atur owner/mode, lalu pasang secara atomik. Jangan mengganti file aktif dengan unduhan yang belum divalidasi.
- Saat membuat cadangan sistem, siapkan direktori tujuan lebih dulu dan gunakan path sumber serta tujuan absolut yang eksplisit. Pastikan salinan berhasil sebelum mengubah file asli; ini mencegah kegagalan seperti `cp: 'etc': No such file or directory` dan memudahkan pemulihan.
- Installer yang dijalankan ulang harus mengenali dependensi yang sudah ada. Jangan menghapus paket atau konfigurasi yang telah tersedia sebelum percobaan. Simpan keadaan awal dan hanya pulihkan/perbarui bagian yang dikelola aplikasi.
- Gunakan `nproc` untuk jumlah core build, dengan fallback ke `getconf _NPROCESSORS_ONLN`; validasi hasilnya sebelum dipakai oleh `make -j`.
- Untuk Nginx, jalankan aplikasi hanya lewat `public/index.php`; blokir eksekusi file PHP lain. Semua route bersih harus punya fallback front controller. Endpoint SSE perlu meneruskan route itu, menonaktifkan buffering FastCGI, dan mengirim `X-Accel-Buffering: no`.
- Pertahankan konfigurasi Nginx lain yang tidak dikelola aplikasi. Tambahkan virtual host port 8000 tanpa menghapus site bawaan atau site milik admin.
- Gunakan nilai contoh umum: alamat privat kelas C seperti `192.168.1.10` dan domain `yourdomain.com`. Jangan simpan alamat, hostname, atau domain produksi dalam contoh publik.
- Pisahkan hasil static check dari validasi di server Linux. Pemeriksaan `php -l`, `bash -n`, dan `node --check` dari Windows tidak membuktikan paket APT, Nginx, systemd, kompilasi source, maupun resolusi DNS pada Ubuntu berjalan berhasil.

## Persyaratan CLI yang tetap berlaku

Repository juga memuat installer terminal lama. Jangan mengubah perilakunya saat mengerjakan Web Manager tanpa kebutuhan yang jelas.

- Pertahankan `Install-cli.sh`, `Uninstall.sh`, dan `Tes.sh`. Nama `Install-cli.sh` membedakan installer terminal dari `install.sh`; pada Windows nama `Install.sh` dan `install.sh` bentrok karena filesystem biasanya tidak membedakan huruf besar-kecil.
- Ketiga script menampilkan nama dan versi OS saat mulai, membersihkan terminal interaktif, serta memakai hijau untuk informasi, biru untuk proses, merah untuk peringatan/error, dan indikator jam pasir sederhana untuk pekerjaan lama. Jangan mencampur kode warna ke konfigurasi DNS atau ringkasan hasil benchmark. `NO_COLOR=1` harus mematikan warna.
- `Tes.sh` menguji resolver lokal `127.0.0.1`, memakai 100 domain umum, dan membatasi waktu uji maksimal lima menit. Jangan menetapkan batas maksimum tambahan untuk client, thread, atau QPS; tampilkan ringkasan hasil dalam bahasa Indonesia.
- Uninstaller CLI harus menampilkan rencana, meminta konfirmasi eksplisit, menjalankan penghapusan berurutan, lalu melaporkan status akhir. Installer CLI memeriksa dependensi yang sudah tersedia dan saat rollback hanya menghapus paket yang dipasang oleh percobaan itu.
- README dan pesan terminal memakai bahasa Indonesia singkat dan teknis. Berikan contoh generik seperti `192.168.1.10` dan `yourdomain.com`, tanpa ungkapan meta seperti “sesuai permintaan” atau contoh milik server produksi.

Perintah clone pada server baru yang pernah dipakai untuk proyek ini:

```bash
apt-get update
apt-get install -y git ca-certificates
git clone https://github.com/ekiguistian/Interaktif_Install-Unbound.git
cd Interaktif_Install-Unbound
bash ./install.sh
```

## GitHub

Branch utama bernama `main`. Remote workspace untuk proyek ini adalah `origin` dengan URL `https://github.com/ekiguistian/Interaktif_Install-Unbound.git`. Setelah perubahan dan README siap, push menggunakan `bash scripts/push-github.sh`; script memakai credential manager/SSH agent yang tersedia dan tidak menaruh token di remote URL.
