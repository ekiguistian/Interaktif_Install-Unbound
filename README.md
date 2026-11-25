# Unbound Web Manager

Panel untuk monitoring dan kontrol Unbound di Ubuntu Server. Login diperlukan untuk membuka dashboard dan semua menu management.

## Tampilan web app

![Halaman login](docs/screenshots/login.png)

![Monitoring Unbound](docs/screenshots/monitoring-unbound.png)

![Monitoring Linux dan jaringan](docs/screenshots/monitoring-linux.png)

Contoh tampilan panel. Versi sekarang mengambil data dan memperbarui dashboard setiap **1 detik**.

## Persiapan server

Gunakan Ubuntu Server 22.04 LTS dengan systemd dan akses `sudo`. Untuk server baru yang belum punya Unbound:

```bash
sudo apt update &&
sudo apt install -y ca-certificates software-properties-common &&
sudo add-apt-repository -y universe &&
sudo apt update &&
sudo apt upgrade -y &&
sudo apt install -y curl htop git unbound ufw iftop
```

Aktifkan Unbound dan cek konfigurasinya:

```bash
sudo systemctl enable --now unbound
sudo unbound-checkconf /etc/unbound/unbound.conf
sudo unbound-control -c /etc/unbound/unbound.conf stats_noreset
```

Jika statistik belum tersedia, aktifkan `remote-control` lokal di konfigurasi Unbound. Contoh konfigurasi ada di [unbound.conf.example](unbound.conf.example); sesuaikan IP dan kapasitas cache dengan server.

Cek systemd dengan `ps -p 1 -o comm=`. Untuk WSL 2, lihat [panduan mengaktifkan systemd](https://learn.microsoft.com/en-us/windows/wsl/wsl-config#systemd-support).

## Install web app

Jalankan di server Unbound:

```bash
cd ~ &&
git clone --branch main https://github.com/ekiguistian/Unbound_DNS_Web-Manager.git &&
cd Unbound_DNS_Web-Manager &&
sudo bash ./install.sh
```

Installer menyiapkan MySQL, PHP 8.4, Nginx, HTTPS, database, dan layanan monitoring. Aplikasi dipasang ke `/opt/www/unbound-web/`. Konfigurasi utama Unbound yang sudah ada tetap dibaca dari `/etc/unbound/unbound.conf`.

Setelah selesai, terminal menampilkan URL, username, dan password login. Buka URL tersebut lalu masuk ke panel. Sertifikat awal self-signed, jadi browser dapat menampilkan peringatan sertifikat.

Jika ingin memakai IP atau nama DNS tertentu, jalankan dari folder proyek:

```bash
sudo env WEB_HOST=10.10.10.10 bash ./install.sh
```

Ganti alamat tersebut dengan IP atau nama DNS server. Pastikan TCP 443 dapat diakses dari komputer admin.

## Update dari main

Gunakan folder hasil clone. Simpan perubahan lokal terlebih dahulu jika ada.

```bash
cd ~/Unbound_DNS_Web-Manager &&
git switch main &&
git pull --ff-only origin main &&
sudo bash ./install.sh
```

Installer menerapkan source terbaru, migrasi database, dan pengaturan layanan. Akun login dan alamat panel yang sudah ada tetap dipakai. Setelah update, muat ulang halaman browser.

## Blocklist dan whitelist

1. Buka **Unbound → Blocklist & whitelist**. Tambahkan URL HTTPS publik. Ekstensi file bebas selama isinya berupa daftar domain, hosts, URL, Adblock, CSV, atau JSON.
2. Isi **IP redirect** dan URL whitelist jika diperlukan. Tambahkan pengecualian melalui **Whitelist manual**.
3. Jika sebelumnya memakai filter otomatis versi lama, matikan kedua filter terlebih dahulu. Buka editor Unbound, lalu tambahkan sendiri baris untuk setiap filter yang dipakai di dalam bagian `server:` pada `/etc/unbound/unbound.conf`.

```conf
    include: "/etc/unbound/blocklist/blocklist.conf"
    include: "/etc/unbound/blocklist/whitelist.conf"
```

4. Pilih **Simpan & muat ulang**. Kembali ke menu filter, lalu pilih **Terapkan & perbarui cron**. Sinkronisasi harian berjalan pukul **03.15 waktu server**. Pilih **Update & Apply Now** untuk menjalankannya langsung.

Installer menyiapkan file kosong jika belum ada, agar include bisa divalidasi sebelum aktivasi. Anda yang menentukan baris include. Jika `unbound.conf` meng-include `90-unbound-web-blocklist.conf` secara langsung, hapus baris lama itu sebelum mematikan filter. Panel menghapus file penghubung lama saat sinkronisasi pertama berhasil. Saat filter dimatikan, file terkait dikosongkan agar include tetap valid.

`blocklist.conf` memuat aturan redirect setelah domain whitelist dikeluarkan. `whitelist.conf` memuat aturan pengecualian untuk subdomain yang masih berada di bawah domain yang diblokir. Daftar whitelist lengkap tersedia di `/var/lib/unbound-web-filters/last-verified-whitelist.txt`. Sinkronisasi yang gagal mempertahankan filter sebelumnya.

Jika sebelumnya memakai `autoupdate-blocklist.py`, nonaktifkan jadwal script lama agar tidak menulis file yang sama. Hasil pemrosesan tersimpan di `/var/lib/unbound-web-filters/` dan log di `/var/log/unbound-web-filters.log`.

```mermaid
flowchart LR
    A["Unduh sumber blocklist"] --> B["Normalisasi dan hapus duplikat"]
    C["Whitelist URL + manual"] --> D["Kecualikan domain yang diizinkan"]
    B --> D
    D --> E["Buat local-zone redirect"]
    E --> F["Validasi konfigurasi"]
    F -->|Valid| G["Reload Unbound"]
    F -->|Gagal| H["Pulihkan file sebelumnya"]
```

## Konfigurasi dan keamanan DNS

Di menu **Unbound**, gunakan **Cek konfigurasi Unbound** untuk memvalidasi file di server dan **Reload server DNS** untuk memuat ulang layanan. **Simpan & muat ulang** menerapkan perubahan dari editor ke `/etc/unbound/unbound.conf` dan mencatat konfigurasi yang berhasil diterapkan ke database.

- **Root hints**. Unduh atau perbarui daftar resmi dari InterNIC, lalu pilih **Aktifkan root hints**. File tersimpan di `/etc/unbound/unbound-web-root.hints`.
- **DNSSEC**. Jika alatnya belum tersedia, jalankan `sudo apt install unbound-anchor`. Pilih **Siapkan / perbarui trust anchor**, lalu **Aktifkan DNSSEC**. Trust anchor disimpan di `/var/lib/unbound/root.key`.
- **Sertifikat kontrol**. Tombol **Buat sertifikat kontrol** membuat file TLS `unbound-control` di `/etc/unbound`. Gunakan file tersebut melalui bagian `remote-control`.

## Monitoring dan kontrol

| Menu | Fungsi |
|---|---|
| **Monitor** | QPS, cache hit, latensi, CPU, memori, disk, RX/TX, uptime, dan informasi Linux. |
| **Unbound** | Statistik, reload, restart, dan editor konfigurasi dengan validasi. |
| **Blocklist & whitelist** | Kelola sumber, pengecualian domain, redirect, dan autoupdate. |
| **Cron** | Status layanan monitoring dan jadwal filter. |
| **Uji DNS** | Uji resolver dengan dnsperf dan lihat QPS serta latensi. |
| **Zabbix Agent** | Edit konfigurasi dan muat ulang agent yang sudah terpasang. |
| **Firewall** | Tambah, edit, hapus rule UFW; enable, disable, dan reload. |

Monitoring memakai `unbound-web-monitor.service` dengan interval **1 detik**, termasuk saat filter DNS dimatikan. Dashboard menampilkan riwayat satu jam: dua menit terbaru per detik, sisanya per menit. Data lama disimpan per menit hingga 30 hari. Jika server sedang lambat, waktu sampel menunjukkan keterlambatan sebenarnya.

Satuan: QPS untuk kueri/detik, ms untuk latensi, persen untuk CPU/cache hit, MiB untuk memori, dan GiB untuk disk. RX/TX memakai kbps di bawah 1.000 kbps dan Mbps mulai 1.000 kbps.

```mermaid
flowchart LR
    A["Worker setiap 1 detik"] --> B["Statistik Unbound + Linux"]
    B --> C["Simpan metrik"]
    C --> D["API setelah login"]
    D --> E["Dashboard setiap 1 detik"]
    F["Admin login"] --> G["Kontrol layanan, konfigurasi, DNS, dan firewall"]
```

## Cek saat ada masalah

```bash
sudo nginx -t
systemctl status unbound php8.4-fpm nginx unbound-web-monitor
sudo journalctl -u unbound-web-monitor -u php8.4-fpm -n 50 --no-pager
sudo tail -n 30 /var/log/unbound-web-filters.log
```

Untuk error `Read-only file system` atau `is not writable` saat memakai blocklist, editor Unbound, atau UFW, jalankan langkah **Update dari main** sampai `install.sh` selesai. Installer memperbarui helper kontrol dan akses layanan PHP-FPM. Jika APT sedang terkunci oleh update otomatis Ubuntu, tunggu selesai lalu ulangi installer.
