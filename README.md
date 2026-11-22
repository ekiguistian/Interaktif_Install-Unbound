# Unbound DNS Web Manager dan Tools CLI

Repository ini menyediakan web manager untuk instalasi Unbound, pengaturan server, blocklist, Zabbix, firewall, dan monitoring. Tools CLI lama tetap tersedia.

## Web Manager

Web Manager memakai PHP Native 8.4, Nginx, MySQL, Tailwind CSS CDN, Font Awesome CDN, Chart.js, dan dnsperf. Dashboard publik memperbarui data tanpa reload. Perubahan server hanya tersedia setelah login admin.

### Sistem yang didukung

- Ubuntu Server 22.04 LTS (Jammy)
- Arsitektur amd64 atau arm64
- systemd, APT, akses root, dan koneksi Internet
- Minimum 1 CPU, RAM 2 GB, ruang kosong 4 GB di `/var`

Web Manager hanya mendukung Ubuntu 22.04. `install.sh` menambahkan PPA `ondrej/php` untuk PHP 8.4 dan repository MySQL Community 8.4 LTS. Paket yang dipasang mencakup `php8.4-fpm`, `php8.4-mysql`, `php8.4-cli`, `php8.4-curl`, `php8.4-mbstring`, `php8.4-xml`, Nginx, MySQL, Git, `dnsperf`, `dnsutils`, `curl`, OpenSSL, `build-essential`, `libssl-dev`, `libexpat1-dev`, `libevent-dev`, `bison`, dan `flex`. PHP Native tidak memerlukan Python. Zabbix Agent baru dipasang dari Settings.

`install.sh` tidak memasang Unbound atau Zabbix Agent. Wizard mengunduh source Unbound terbaru dari NLnet Labs dan mengompilasinya. Agent Zabbix dipasang dari Settings.

### Instalasi Web Manager

Pada server baru, login sebagai `root`. Perbarui daftar paket dan pasang Git serta sertifikat CA sebelum clone:

```bash
apt-get update
apt-get install -y git ca-certificates
git clone https://github.com/ekiguistian/Interaktif_Install-Unbound.git
cd Interaktif_Install-Unbound
bash ./install.sh
```

Jalankan installer sebagai root. Script memverifikasi Ubuntu 22.04, CPU, RAM, dan ruang disk sebelum memasang dependensi. Installer membuat database, tabel, Nginx pada port `8000`, worker cron setiap menit, dan helper root dengan daftar aksi terbatas. Rule UFW untuk port 8000 ditambahkan, tetapi firewall tidak diaktifkan otomatis.

Di akhir instalasi, script menampilkan alamat `DB_PASS` yang dibuat untuk MySQL serta isi `.env.example`. Buat konfigurasi aplikasi dan isi kredensial admin:

```bash
cp /opt/unbound-web/.env.example /etc/unbound-web/.env
nano /etc/unbound-web/.env
chown root:www-data /etc/unbound-web/.env
chmod 0640 /etc/unbound-web/.env
```

Ganti `DB_PASS` dengan nilai yang dicetak installer. Isi `ADMIN_USER`, `ADMIN_PASS`, dan `NET_INTERFACE`. Gunakan password admin yang panjang dan jangan salin file `.env` ke document root. File konfigurasi disimpan di `/etc/unbound-web/.env`.

Buka `http://IP-SERVER:8000`. URL aplikasi tidak memakai ekstensi `.php`; request langsung ke file `.php` diarahkan ke URL bersih dengan status 301. Dashboard publik tersedia tanpa login. Halaman Settings menggunakan kredensial dari `.env`.

### Alur sistem

1. `install.sh` memeriksa OS dan resource, memasang PHP, Nginx, MySQL, Git, dnsperf, serta build dependencies. Unbound dan Zabbix Agent tidak dipasang oleh script ini.
2. Admin menyalin `.env.example` ke `/etc/unbound-web/.env`, lalu mengisi kredensial admin dan interface jaringan.
3. Worker `bin/worker.php` berjalan setiap menit. Worker membaca `unbound-control stats_noreset`, counter Ethernet, dan resource server, lalu menyimpan riwayat selama 30 hari.
4. Wizard menjalankan lima tahap: cek sistem dan port 53, unduh source NLnet Labs, kompilasi source, siapkan root hints/DNSSEC/control certificate dan service systemd, lalu uji DNS serta resolver lokal. Output proses dikirim lewat SSE. Jika tahap gagal, resolver dan status `systemd-resolved` sebelumnya dipulihkan.
5. Unbound mulai sebagai recursive resolver dari root hints. DNSSEC aktif melalui trust anchor. TLD publik seperti `.id` di-resolve lewat hierarki DNS publik. Setelah uji query dan validasi DNSSEC berhasil, resolver host dialihkan ke `127.0.0.1`.
6. Dashboard menampilkan status beserta versi Unbound, Zabbix Agent, jumlah query/reply, cache hit, QPS, tipe query, response code, waktu rekursi, CPU, load, RAM, disk, uptime, dan trafik Ethernet. Grafik menampilkan QPS, cache hit, dan trafik selama satu jam terakhir.
7. Settings mengatur konfigurasi Unbound, blocklist/whitelist, Zabbix Agent, firewall UFW, jadwal cron, domain uji, dan dnsperf.

Worker menulis log di `/var/log/unbound-web/worker.log`; hasil sinkronisasi blocklist dicatat di `/var/log/unbound-web/blocklist.log`. Logrotate menyimpan rotasi harian selama tujuh hari.

### Modul Settings

- **Konfigurasi Unbound**: interface, port, access-control, thread, cache size dan slabs, TTL, prefetch, dan hardening DNSSEC. Log query dan reply dapat diaktifkan secara terpisah. Konfigurasi divalidasi dengan `unbound-checkconf` sebelum service di-reload. File aktif tersedia dalam tampilan baca saja.
- **Blocklist/whitelist**: tambah, ubah, aktifkan, nonaktifkan, dan hapus URL HTTPS atau domain whitelist. Parser mendukung hosts, Adblock `||domain^`, dan domain biasa. Download dipatok ke alamat IP publik yang sudah divalidasi, tanpa mengikuti redirect. Update memfilter whitelist, memvalidasi konfigurasi, lalu reload Unbound.
- **Zabbix Agent**: pasang Agent 2 jika tersedia, atau Agent klasik. Isi `Server`, `ServerActive`, dan `Hostname`. Tombol **Enable** menjalankan agent dan mengaktifkannya saat boot. Tombol **Disable** menghentikan agent dan menonaktifkannya saat boot. Agent berjalan sebagai service systemd.
- **Firewall**: buka **Firewall** dari navbar admin. Halaman ini menampilkan status dan tombol **Enable UFW** serta **Disable UFW**. Siapkan aturan SSH dan port web sebelum mengaktifkan UFW.
- **Jadwal cron**: lihat job aplikasi, pilih interval blocklist, tambah/ubah/hapus jadwal root hints atau sinkronisasi blocklist. Perintah custom dibatasi ke tugas internal.
- **dnsperf**: atur target IPv4 private atau loopback, port, durasi maksimal 300 detik, client, thread, dan QPS. Nilai QPS `0` berarti tanpa batas QPS. Hasil tampil langsung melalui SSE.
- **Domain pengujian**: CRUD domain dan tipe query `A`, `AAAA`, `MX`, `TXT`, `NS`, atau `CNAME`. Installer menambahkan 100 domain umum sebagai awal.

### Mengatur UFW

1. Buka **Firewall** dari navbar admin. Pilih **Allow** atau **Deny**, arah **IN** atau **OUT**, protokol TCP/UDP, dan port tujuan.
2. Isi IP/CIDR untuk membatasi sumber trafik **IN** atau tujuan trafik **OUT**. Alamat dapat berupa IPv4 atau IPv6. Kolom kosong berlaku untuk semua alamat dalam keluarga IP yang aktif di UFW.
3. Gunakan filter **IN** atau **OUT** untuk melihat aturan. Buka aturan untuk mengubah atau menghapusnya. Aturan lanjutan seperti routing dan profil aplikasi tampil dalam daftar **Semua** dan dikelola melalui terminal.
4. Klik **Enable UFW** atau **Disable UFW** di toolbar untuk mengubah status firewall. Aturan tersimpan tetap terlihat ketika UFW nonaktif.

Mengubah aksi saja memakai mekanisme pembaruan UFW. Perubahan port, arah, protokol, atau IP/CIDR menambahkan aturan pengganti di akhir daftar. Periksa urutannya jika ada aturan yang saling tumpang tindih.

Navbar admin berisi **Unbound**, **Zabbix Agent**, **Firewall**, dan **Log Out**. Submenu Unbound memuat konfigurasi, blocklist/whitelist, cron, uji DNS, dan domain uji. Navbar Live Monitor menampilkan **Install Wizard** sebelum Unbound terpasang dan **Setting** setelah terpasang.

### Keamanan Web Manager

Dashboard publik hanya membaca metrik. Login admin memakai username dan password dari `.env`; tidak ada menu untuk mengubah kredensial. Session memakai cookie HttpOnly dan SameSite Strict, formulir menggunakan token CSRF, login dibatasi, database memakai PDO prepared statements, dan nilai yang digunakan oleh shell divalidasi serta di-escape dengan `escapeshellarg()`.

`www-data` tidak diberi sudo umum untuk menjalankan command arbitrer. Sudoers hanya mengizinkan `/usr/local/sbin/unbound-web-manage`; helper root memeriksa daftar aksi dan setiap argumennya sebelum memakai APT, systemd, Unbound, UFW, atau cron. File aplikasi dimiliki root dan tidak dapat ditulis oleh Nginx.

Nginx bawaan memakai HTTP pada port `8000`. Untuk penggunaan di jaringan produksi, batasi akses dengan firewall/VPN atau pasang reverse proxy HTTPS sebelum memasukkan password admin. Tailwind CSS, Font Awesome, dan Chart.js dimuat dari CDN sehingga browser perlu akses Internet untuk tampilan lengkap.

### Push ke GitHub

Repository Git sudah digunakan pada workspace ini. Setelah membuat repository GitHub kosong, push branch `main` memakai script berikut:

```bash
bash scripts/push-github.sh https://github.com/OWNER/NAMA-REPOSITORY.git
```

Script memeriksa URL GitHub, menambahkan perubahan, membuat commit bila ada file berubah, lalu menjalankan `git push -u`. Jangan masukkan token ke URL remote; gunakan SSH agent atau credential manager Git.

## Tools CLI

Contoh IP LAN memakai jaringan private `192.168.1.0/24`. Contoh domain menggunakan `yourdomain.com`; ganti dengan jaringan dan domain milik sendiri.

Contoh IP LAN menggunakan jaringan privat kelas C `192.168.1.0/24`. Nama domain contoh menggunakan `yourdomain.com`; ganti keduanya dengan jaringan dan domain milik sendiri sebelum dipakai.

Repository ini menyediakan tiga script:

| Script | Fungsi |
| --- | --- |
| `Install-cli.sh` | Instalasi interaktif Unbound DNS Server melalui terminal. |
| `Uninstall.sh` | Uninstalasi Interaktif - Clear OS. Menghapus stack Unbound/Zabbix/dnsperf. UFW di-reset dan dinonaktifkan. |
| `Tes.sh` | Pasang dnsperf bila belum tersedia lalu uji resolver lokal dengan 100 domain. |

## Tampilan terminal CLI

Ketiga script membersihkan layar saat berjalan di terminal interaktif, lalu menampilkan nama OS, versi kernel, dan arsitektur server. Informasi berwarna hijau, proses berwarna biru, dan peringatan atau error berwarna merah. Proses yang lama menampilkan indikator jam pasir. Warna dan pembersihan layar tidak dipakai saat script berjalan tanpa terminal. Setel `NO_COLOR=1` untuk menonaktifkan warna tanpa mematikan indikator jam pasir atau pembersihan layar. File konfigurasi Unbound dan ringkasan hasil dnsperf tetap berupa teks biasa.

## Alur penggunaan CLI

1. Jalankan `Install-cli.sh` untuk memeriksa OS dan resource, memasang dependensi yang kurang, lalu mengatur resolver, firewall, blocklist/whitelist, dan Zabbix dari pilihan interaktif. Installer meminta konfirmasi ringkasan sebelum menerapkan konfigurasi dan menjalankan pemeriksaan DNS serta DNSSEC setelah pemasangan.
2. Jalankan `Tes.sh` di server untuk memeriksa DNS lokal, memanaskan cache, lalu mengukur resolver dengan dnsperf. Hasil pengujian dan ringkasan disimpan di `dnsperf-results/`.
3. Jika ingin menghapus stack yang dipasang, jalankan `Uninstall.sh`. Script menampilkan rencana penghapusan, meminta konfirmasi `HAPUS`, menjalankan tahap uninstall, lalu melaporkan hasil sistem.

Lihat [SECURITY.md](SECURITY.md) sebelum memakai server yang bisa diakses dari Internet. Panduan perubahan kode ada di [CONTRIBUTING.md](CONTRIBUTING.md).

## Sistem yang didukung

- Ubuntu Server 24.04 atau lebih baru
- Debian 12 atau lebih baru
- Minimal 1 CPU, RAM 512 MB, dan ruang kosong 1 GB di `/var`
- systemd, APT, dan akses root atau sudo
- LXC Proxmox perlu mendukung systemd dan UFW (`NET_ADMIN`)

Pada 22 November 2026, rilis terbaru yang tercatat adalah Ubuntu 26.10, Ubuntu 26.04 LTS, dan Debian 13.8. Lihat [rilis Ubuntu](https://wiki.ubuntu.com/Releases) dan [rilis Debian](https://www.debian.org/releases/index).

## Menjalankan installer

Pada instalasi Debian yang belum memiliki `sudo`, login sebagai `root`. Perbarui APT dan pasang paket persiapan berikut:

```bash
apt-get update
apt-get install -y sudo git ca-certificates curl
```

Jika ingin melanjutkan sebagai pengguna biasa, tambahkan akun tersebut ke grup `sudo`, lalu logout dan login kembali. Ganti `NAMA_USER` dengan nama akun Linux:

```bash
usermod -aG sudo NAMA_USER
```

Jika tetap bekerja sebagai `root`, langkah ini tidak diperlukan.

Clone repository, lalu jalankan installer:

```bash
git clone https://github.com/ekiguistian/Interaktif_Install-Unbound.git
cd Interaktif_Install-Unbound
chmod +x Install-cli.sh
./Install-cli.sh
```

Jalankan `git clone` dan installer sebagai akun yang memiliki hak sudo, atau lanjutkan sebagai `root`. Installer memeriksa dependensi terlebih dahulu. Jika semuanya sudah tersedia, APT tidak diperbarui dan paket tidak dipasang ulang. Jika ada yang kurang, installer menjalankan `apt-get update` lalu memasang paket yang belum tersedia.

## Alur installer

1. Memeriksa versi OS, CPU, RAM, dan ruang kosong di `/var`. Host di bawah minimum akan dihentikan sebelum perubahan dilakukan.
2. Memeriksa dependensi wajib: Unbound, paket `unbound-anchor`, Python 3, curl, Git, UFW, dnsutils, sudo, Zabbix Agent, dan root hints. Dependensi yang sudah tersedia hanya diperiksa. Jika ada yang kurang, installer memperbarui indeks APT dan memasang yang belum tersedia. Repository resmi Zabbix ditambahkan jika paket Agent tidak tersedia di APT distro.
3. Memeriksa `/etc/unbound/unbound.conf` yang sudah ada dengan `unbound-checkconf` dan melaporkan status service. Konfigurasi yang tidak valid dapat diperbaiki dari menu **Generate / Perbaiki unbound.conf**.
4. Meminta jenis server, alamat interface, jaringan yang boleh mengakses DNS, resource, PTR, upstream, logging, blocklist/whitelist, Zabbix, dan aturan SSH.
5. Menampilkan ringkasan untuk diperiksa sebelum konfigurasi diterapkan.
6. Membuat konfigurasi, memvalidasi dengan `unbound-checkconf`, memasang Unbound, dan mengatur UFW.
7. Menguji query Internet melalui Unbound, validasi DNSSEC, dan alamat interface setelah firewall aktif.
8. Menyelesaikan pengaturan resolver sesuai jenis server.

Jika validasi konfigurasi atau pemeriksaan DNS gagal, installer melaporkan masalah dan memulihkan konfigurasi Unbound sebelumnya bila tersedia.

Jika proses gagal atau menu dibatalkan, installer menghapus paket yang baru dipasang pada percobaan itu dan memulihkan file sistem, resolver, firewall, serta status service seperti sebelum dijalankan. Paket yang sudah ada sebelumnya tidak dihapus. Jika perangkat mati sebelum rollback selesai, data pemulihan disimpan dan dicoba kembali saat installer dijalankan lagi.

## Resolver setelah pemasangan

**Virtual machine:** setelah semua pemeriksaan berhasil, installer mengatur `/etc/resolv.conf` ke `127.0.0.1`, menguji query host, dan menyimpan cadangan resolver sebelumnya.

**LXC Proxmox:** installer tidak mengubah resolver container. Setelah status pemasangan berhasil, gunakan IP interface yang ditampilkan installer sebagai DNS container di Proxmox.

## Fitur DNS

- Root recursion atau forwarder; tersedia mode root dengan forwarder fallback.
- Root hints dan validasi DNSSEC.
- TLD publik, termasuk `.id`, di-resolve otomatis dalam mode root.
- Forwarder bawaan `1.1.1.1` dan `8.8.8.8`; alamat dapat diganti saat setup.
- Cache, prefetch, serve-expired, PTR, dan pilihan logging.
- Blocklist dan whitelist dari URL HTTPS, dengan pembaruan harian.
- Zabbix Agent untuk statistik Unbound jika monitoring dipilih.

Untuk domain internal, tambahkan zona yang sesuai ke `/etc/unbound/unbound.conf`, lalu validasi dan restart Unbound.

## Menghapus instalasi

Jalankan uninstaller dari folder repository sebagai `root` atau gunakan `sudo`:

```bash
sudo ./Uninstall.sh
```

Uninstaller menampilkan urutan tindakan dan meminta konfirmasi dengan mengetik `HAPUS`. Proses menghentikan Unbound, Zabbix Agent, dan timer blocklist; memulihkan resolver dari cadangan bila tersedia; mereset aturan UFW dan menonaktifkan firewall; mem-purge paket Unbound, `unbound-anchor`, Zabbix Agent, root hints, dan dnsperf; lalu menghapus konfigurasi, data, kunci DNSSEC, file integrasi, hasil benchmark, dan hasil konfigurasi lokal. Jika resolver masih menunjuk ke `127.0.0.1` tanpa cadangan, masukkan DNS IPv4 pengganti sebelum uninstall berjalan.

Setelah selesai, script menampilkan status paket, service, file konfigurasi, firewall, resolver, dan tes DNS. Jika ada tahap yang gagal, tahap tersebut dicantumkan di hasil akhir. Paket umum `sudo`, Python 3, curl, Git, dnsutils, dan UFW tetap terpasang agar kebutuhan OS lain tidak ikut terhapus. Aturan UFW akan dihapus dan firewall dinonaktifkan.

## Melihat log query DNS

Aktifkan pilihan **logging** saat instalasi agar Unbound menulis query dan jawaban ke syslog. Untuk melihat log yang terus masuk:

```bash
sudo journalctl -t unbound -f
```

Untuk log satu jam terakhir:

```bash
sudo journalctl -t unbound --since "1 hour ago"
```

Untuk melihat status start, stop, atau error service:

```bash
sudo journalctl -u unbound -n 100 --no-pager
```

Periksa apakah pencatatan query dan jawaban aktif:

```bash
sudo grep -E '^[[:space:]]*(use-syslog|log-queries|log-replies|log-tag-queryreply):' /etc/unbound/unbound.conf
```

`log-queries` mencatat alamat klien, nama domain, tipe, dan kelas query. `log-replies` juga mencatat kode hasil dan waktu resolusi. Catatan ini memuat data aktivitas DNS dan dapat memperlambat resolver; matikan logging setelah pemeriksaan jika tidak diperlukan. Lihat [dokumentasi konfigurasi Unbound](https://nlnetlabs.nl/documentation/unbound/unbound.conf/) dan [manual journalctl](https://www.freedesktop.org/software/systemd/man/latest/journalctl.html).

## Menguji server dengan dnsperf

Jalankan dari server Unbound:

```bash
./Tes.sh
```

Jalankan sebagai pengguna yang memiliki hak sudo agar file hasil tidak menjadi milik root. Jika `dnsperf` atau `dig` belum terpasang, script memperbarui indeks APT lalu memasang paket yang kurang. Pada Ubuntu, script mengaktifkan Universe bila paket dnsperf belum tersedia. Script memastikan service Unbound aktif dan `127.0.0.1` dapat menjawab query, kemudian menyalin daftar 100 domain umum ke folder hasil lokal. Daftar sumbernya ada di `src/dnsperf-domains.txt`.

Script memanaskan cache dengan satu putaran query, lalu menjalankan dnsperf ke `127.0.0.1:53`. Durasi awal adalah 300 detik dan tidak bisa diatur lebih dari 5 menit. Nilai awal client dan pasangan thread mengikuti jumlah core CPU yang terdeteksi; pengguna boleh memasukkan angka lebih besar. Script tidak membatasi jumlah client, thread, atau query per detik. Sebelum mulai, script menampilkan rencana uji dan meminta konfirmasi `MULAI`.

Tes penuh dapat menghabiskan CPU dan membuat query DNS klien lain melambat selama pengujian. Jalankan pada waktu sepi. Script menampilkan jumlah query terkirim, jawaban, query hilang, kecepatan rata-rata, dan latensi. Log lengkap dan daftar query tersimpan di `dnsperf-results/`. Daftar 100 domain ini cocok untuk pemeriksaan singkat, bukan untuk menentukan kapasitas maksimum server. Script tidak mengosongkan cache atau mengubah konfigurasi Unbound. Rujuk [manual dnsperf Debian](https://manpages.debian.org/bookworm/dnsperf/dnsperf.1.en.html) untuk arti client, thread, durasi, dan metrik.

## Firewall

Installer mereset aturan UFW yang ada, lalu menetapkan deny incoming dan deny outgoing. Port yang dibuka:

- DNS TCP/UDP 53, masuk dan keluar
- HTTPS TCP 443, masuk dan keluar
- SSH TCP 22, masuk dan keluar; sumber IP dapat dibatasi
- Jika Zabbix dipilih: TCP 10050 dari server Zabbix dan TCP 10051 ke server Zabbix

Periksa sumber IP SSH sebelum menyetujui ringkasan. Aturan lama dihapus saat UFW diatur ulang. IP Allow awal adalah `192.168.1.0/24`, sehingga hanya klien pada subnet privat tersebut yang dapat memakai resolver. Ubah nilainya bila LAN menggunakan subnet lain.

## File hasil

- `unbound.conf` di direktori kerja dan `/etc/unbound/unbound.conf`
- `/etc/unbound/blocklist/` jika blocklist/whitelist digunakan
- Konfigurasi Zabbix di `/etc/zabbix/zabbix_agentd.conf` jika monitoring digunakan
- Cadangan konfigurasi sistem dengan akhiran `.bak.<waktu>`
