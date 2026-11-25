# Unbound Web Manager

Panel HTTPS untuk monitor dan kontrol Unbound di Ubuntu Server 22.04 LTS. Login diperlukan untuk membuka panel.

## Dependensi

- **Server**: Ubuntu 22.04 LTS, systemd, akses `sudo`, Unbound, dan akses ke APT serta GitHub.
- **Dipasang oleh installer**: Git, Nginx, MySQL 8, PHP 8.4 CLI/FPM dengan PDO MySQL, OpenSSL, dan Argon2id, serta Python 3, curl, rsync, cron, iproute2, dan dnsperf. Installer menambahkan PPA Ondřej jika paket PHP 8.4 belum tersedia.
- **Opsional**: `unbound-anchor` untuk mengelola trust anchor DNSSEC, UFW untuk menu Firewall, dan Zabbix Agent atau Agent 2 untuk ekspor metrik.

## Instalasi awal

Di server baru, siapkan Unbound terlebih dahulu:

```bash
sudo apt update &&
sudo apt install -y git unbound &&
sudo systemctl enable --now unbound &&
sudo unbound-checkconf /etc/unbound/unbound.conf
```

Gunakan [unbound.conf.example](unbound.conf.example) sebagai acuan. Sesuaikan IP, ACL, dan ukuran cache dengan server. Periksa file root hints, trust anchor, dan sertifikat kontrol yang dirujuk contoh sebelum menggunakannya. Aktifkan `remote-control` lokal agar panel bisa membaca statistik DNS.

```bash
cd ~ &&
git clone --branch main https://github.com/ekiguistian/Unbound_DNS_Web-Manager.git &&
cd Unbound_DNS_Web-Manager &&
sudo bash ./install.sh
```

Masukkan email pemulihan saat diminta, lalu buka URL HTTPS dan login dengan akun awal yang ditampilkan. Untuk instalasi noninteraktif, isi `RECOVERY_EMAIL` melalui environment. Panel memakai TCP 443 dan sertifikat HTTPS self-signed. Gunakan `WEB_HOST` jika perlu menentukan alamat panel sendiri.

### Jika unduhan paket PHP gagal dengan HTTP 503

Jika PPA Ondřej mengembalikan `503 Service Unavailable` saat mengunduh paket PHP 8.4 dan installer berhenti dengan `exit 100`, jalankan kembali dari folder repo:

```bash
sudo apt-get update && sudo bash ./install.sh
```

Jika pesan `503` masih muncul, tunggu beberapa menit lalu ulangi perintah yang sama.

## Update dari main

Panel menghitung versi dari riwayat utama `main` mulai **1.0.30**. Setiap commit baru di jalur itu menambah angka terakhir versi satu kali. MySQL menyimpan versi, hash lengkap, serta waktu pengecekan dan pemasangan.

Buka **Pembaruan → Cek pembaruan**, periksa versi dan hash, lalu pilih **Pasang pembaruan**. Panel mengambil commit dari GitHub ke `/opt/www/unbound-web/.updates/` dan menjalankan `update.sh` melalui systemd. Jika gagal, cek `sudo journalctl -u unbound-web-update -n 50`.

Untuk memperbarui lewat terminal, jalankan dari folder repo dengan riwayat Git lengkap:

```bash
cd ~/Unbound_DNS_Web-Manager &&
git switch main &&
git pull --ff-only origin main &&
sudo bash ./update.sh
```

Pembaruan berjalan tanpa meminta email. Setelah migrasi pertama, masuk lagi dengan username dan password lama, lalu isi email pemulihan dari menu **Akun** jika belum ada.

## Akun dan pemulihan

Di menu **Akun**, ubah nama pengguna, password, dan email pemulihan. Password baru harus 4–12 karakter. Isi server, port, email pengirim, dan mode SMTP, lalu aktifkan SMTP. Gunakan **STARTTLS** atau **SSL/TLS** untuk SMTP dengan autentikasi. Mode **None** mendukung relay tanpa autentikasi.

Setelah menyimpan konfigurasi, klik **Tes SMTP** untuk mengirim email tes ke alamat pemulihan.

Gunakan **Lupa password** di halaman login dan masukkan email pemulihan yang tersimpan di akun. Masukkan OTP dari email pada browser yang sama dalam 10 menit.

## Fitur

| Menu | Fungsi |
|---|---|
| **Monitor** | QPS, cache, latensi, CPU, RAM, disk, dan RX/TX. Dashboard diperbarui per detik dengan riwayat 10 menit. |
| **Unbound** | Cek, simpan, reload, start/stop, root hints, DNSSEC, dan sertifikat TLS remote-control. Perubahan konfigurasi yang berhasil disimpan ke file dan database. |
| **Interface & Reverse DNS** | Baca interface, zona reverse, dan record PTR yang ditulis di bagian `server:` pada `/etc/unbound/unbound.conf`. Loopback hanya bisa dibaca. IP lain bisa diedit per interface. Perubahan IP me-restart Unbound, sementara perubahan zona dan PTR memakai reload saat layanan aktif. Data tersimpan ke database. |
| **Log langsung** | Baca 40 entri journal terakhir dan ikuti log baru. Pencatatan kueri dan jawaban diatur terpisah. Gunakan **Hentikan log** untuk menutup sesi. |
| **Jadwal / Uji DNS** | Lihat status pemantauan dan jadwal filter. Jalankan satu tes dnsperf dalam satu waktu ke IPv4 privat atau loopback, maksimal 16 klien, 4 thread, dan 1.000 QPS. |
| **Zabbix Agent** | Edit konfigurasi agen atau aktifkan item `unbound.web.metrics` untuk total query, total reply, QPS, dan metrik Linux. Lihat [panduan item dan grafik Zabbix](docs/zabbix.md). |
| **Firewall** | Kelola rule, status, dan reload UFW. |

Tema **Sistem** mengikuti OS. Pilih **Terang** atau **Gelap** untuk menyimpan pilihan di browser.

Untuk produksi, batasi akses panel ke jaringan admin atau VPN dan izinkan kueri DNS hanya dari subnet klien. Lihat [panduan keamanan](SECURITY.md).

## Filter DNS

1. Tambahkan URL HTTPS publik di menu **Blocklist & Whitelist**. Isi file dapat berupa daftar domain, hosts, URL, Adblock, CSV, atau JSON. Tambahkan domain pengecualian secara manual atau isi URL whitelist.
2. Pilih **Aktifkan Blocklist** atau **Aktifkan Whitelist**. Tombol memasang include di dalam `server:` pada `/etc/unbound/unbound.conf`:

```conf
    include: "/etc/unbound/blocklist/blocklist.conf"
    include: "/etc/unbound/blocklist/whitelist.conf"
```

3. Setelah include aktif, tombol berubah untuk menghapus include dan menonaktifkan filter terkait. Konfigurasi dan pengaturan dicatat ke database. Sinkronisasi berjalan pukul 03.15 waktu server. Gunakan **Sinkronkan sekarang** untuk sinkronisasi langsung.

Salin URL sumber yang ingin dipakai ke kolom filter terkait.

**Whitelist** · [Buka daftar domain Eki Guistian](https://raw.githubusercontent.com/ekiguistian/whitelist-host/refs/heads/main/whitelist-domain.txt)

```text
https://raw.githubusercontent.com/ekiguistian/whitelist-host/refs/heads/main/whitelist-domain.txt
```

**Blocklist** · [Buka daftar hosts StevenBlack](https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/fakenews-gambling-porn-social-only/hosts)

```text
https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/fakenews-gambling-porn-social-only/hosts
```

Hasil konversi ada di `/var/lib/unbound-web-filters/`. Log ada di `/var/log/unbound-web-filters.log`. Untuk daftar jutaan domain, sediakan RAM tambahan saat validasi dan reload.

```mermaid
flowchart LR
    A["Unbound + Linux"] --> B["Worker 1 detik"] --> C["Dashboard"]
    D["Sumber blocklist + whitelist"] --> E["Pisahkan file filter"] --> F["Validasi dan reload Unbound"]
```

## Screenshot

### Monitor

![Statistik DNS dengan tema gelap](docs/screenshots/monitoring-dns.png)

![Statistik DNS dengan tema terang](docs/screenshots/monitoring-dns-terang.png)

### Kontrol dan pembaruan

![Kontrol resolver, root hints, DNSSEC, dan sertifikat](docs/screenshots/kontrol-unbound.png)

![Ekspor statistik Unbound ke Zabbix Agent](docs/screenshots/zabbix-agent.png)

![Status dan pengelolaan aturan UFW](docs/screenshots/firewall-ufw.png)

![Panel pembaruan dengan versi dan hash commit](docs/screenshots/pembaruan.png)

## Atribusi

System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memodifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource. Ketentuan lisensi repositori tercantum di [LICENSE](LICENSE), dan lisensi komponen eksternal tercantum di [THIRD-PARTY.txt](public/assets/THIRD-PARTY.txt).
