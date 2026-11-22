# Unbound Web Manager

Panel web buat kamu yang sudah punya resolver Unbound di server Linux. Buka dashboard buat lihat kondisi DNS dan mesin, lalu masuk ke menu Unbound kalau perlu cek statistik, edit file, atau restart layanan.

## Mulai dari sini

Siapkan Ubuntu Server dengan systemd, akses sudo, dan Unbound yang sudah dikonfigurasi. Cek dulu apakah statistiknya bisa dibaca di mesin itu.

```bash
sudo unbound-checkconf /etc/unbound/unbound.conf
sudo unbound-control -c /etc/unbound/unbound.conf stats_noreset
```

Kalau perintah kedua gagal, cek bagian `remote-control` di konfigurasi resolver. Grafik Linux tetap bisa jalan selagi kamu beresin akses statistik DNS.

Dari folder proyek, jalankan installer panel. Pakai IP server atau nama DNS yang memang mengarah ke servermu.

```bash
sudo WEB_HOST=dns-panel.example.net bash install.sh
```

Tanpa `WEB_HOST`, installer memilih alamat pertama dari `hostname -I`. Installer menyiapkan MySQL, PHP 8.4-FPM, Nginx, cron dan dnsperf. Di Ubuntu yang belum menyediakan PHP 8.4 lewat repositori aktif, installer menambahkan PPA ondrej/php. File aplikasi masuk ke `/opt/www/unbound-web/`, sedangkan kredensial ada di `/etc/unbound-web/.env`.

Setelah migrasi, installer menjalankan `nginx -t` dan mengecek halaman login lewat HTTPS. URL, username, dan password muncul di terminal. Password login baru berisi 8 karakter acak dengan 1 huruf kapital, 1 simbol, dan 6 huruf kecil. Password database dibuat terpisah. Saat installer dijalankan ulang, panel memakai kredensial yang sudah ada.

Sertifikat awal bersifat self-signed. Untuk mengenalinya di browser, cocokkan fingerprint SHA-256 dengan hasil perintah di server sebelum mempercayainya. Sertifikat dan key ada di `/etc/unbound-web/tls/`.

```bash
sudo openssl x509 -in /etc/unbound-web/tls/panel.crt -noout -fingerprint -sha256
```

## Pakai dashboard

Login dulu, lalu buka **Monitor**. Worker mengambil sampel tiap menit. Halaman mengecek data baru tiap 10 detik dan menampilkan riwayat satu jam. Lihat waktu sampel terakhir kalau grafik terasa berhenti.

| Tampilan | Cara bacanya |
|---|---|
| QPS | Selisih jumlah kueri dibagi durasi antarsampel. Perlu dua sampel untuk mulai menghitung. |
| Cache dan latensi | Persentase cache hit dan rata-rata waktu rekursi dalam ms dari statistik Unbound. |
| CPU, memori, disk | CPU dalam persen, memori terpakai/total dalam MiB, disk terpakai/total untuk filesystem root dalam GiB. |
| Jaringan | Pilih interface. RX berarti trafik masuk, TX trafik keluar. Di bawah 1.000 kbps tampil dalam kbps, selebihnya Mbps. |
| Info Linux | Hostname, distro, kernel, uptime, dan load rata-rata 1, 5, serta 15 menit. |

## Kontrol harian

- **Unbound** membuka isi `/etc/unbound/unbound.conf` apa adanya. Kamu bisa lihat statistik, reload, restart, atau simpan perubahan. Kalau orang lain sudah mengedit file sejak halaman dibuka, muat ulang dulu. Panel menjalankan `unbound-checkconf` dan memulihkan file sebelumnya kalau validasi atau penerapan gagal.
- **Blocklist & whitelist** menerima sumber HTTPS dan daftar pengecualian domain. Isi daftar, nyalakan sakelar yang kamu butuhkan, lalu klik **Terapkan & perbarui cron**. Panel memakai `/etc/unbound/unbound.conf.d/90-unbound-web-blocklist.conf`. Pastikan konfigurasi aktif memuat direktori itu lewat `include:` atau `include-toplevel:`. Sinkronisasi otomatis berjalan pukul 03.15 waktu server, atau langsung lewat tombol sinkronisasi. Mematikan kedua sakelar membersihkan aturan filter panel dan melepas cron filter.
- **Cron** menampilkan job milik panel. Interval worker monitoring tetap satu menit, termasuk saat filter DNS mati.
- **Uji DNS** menjalankan dnsperf ke resolver di loopback atau jaringan privat. Pilih domain, port, durasi dan batas QPS. Hasilnya mencantumkan kueri terkirim, jawaban, QPS dan latensi. Gunakan resolver yang memang kamu kelola.
- **Zabbix Agent** membuka file agent yang sudah ada, termasuk Agent 2. Simpan untuk memuat ulang konfigurasi lengkap. Pada unit yang hanya mendukung restart, panel memakai restart.
- **Firewall** membaca aturan UFW tersimpan dan status aktif langsung dari server. Kamu bisa tambah, edit, hapus, enable, disable, atau reload. Pilih semua protokol untuk semua trafik, TCP+UDP untuk satu port bersama, atau protokol tertentu. ICMP IPv4 dikelola melalui entri bertanda di `/etc/ufw/before.rules`. Aturan lanjutan dari server ditampilkan sebagai teks jika panel belum bisa mengeditnya. Pastikan jalur SSH dan HTTPS tersedia sebelum mengaktifkan UFW.

Saat pemasangan panel, file resolver, konfigurasi IPv6, Zabbix, dan rule UFW tetap mengikuti server. Fitur terkait mulai menerapkan perubahan saat kamu memintanya dari panel.

## Kalau ada yang macet

Cek Nginx, PHP, cron, dan worker langsung dari server.

```bash
sudo nginx -t
systemctl status nginx php8.4-fpm cron unbound
sudo -u unbound-web /usr/bin/php8.4 /opt/www/unbound-web/bin/worker.php
sudo /usr/local/sbin/unbound-web-manage cron-view
```

Untuk memperbarui panel, jalankan `install.sh` dari checkout versi yang ingin dipasang. Sebelum upgrade dari versi lama, cadangkan database dan `/etc/unbound-web/`. Cek juga jadwal kustom lama di luar cron milik panel.
