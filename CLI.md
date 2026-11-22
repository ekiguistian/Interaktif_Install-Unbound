# Panduan CLI Unbound DNS

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

- Ubuntu Server 22.04 LTS (Jammy)
- Minimal 1 CPU, RAM 512 MB, dan ruang kosong 1 GB di `/var`
- systemd, APT, dan akses root atau sudo
- LXC Proxmox perlu mendukung systemd dan UFW (`NET_ADMIN`)

## Menjalankan installer

Pasang Ubuntu Server 22.04 LTS dan siapkan jaringan seperti pada [panduan awal](README.md#1-pasang-ubuntu-server-2204-lts). Masuk sebagai pengguna dengan hak sudo. Jalankan tanpa `sudo` jika masuk sebagai root.

```bash
sudo apt update
sudo apt upgrade -y
sudo apt install -y git ca-certificates
```

Reboot dan masuk kembali jika Ubuntu memintanya setelah pembaruan.

Clone repository, lalu jalankan installer:

```bash
git clone https://github.com/ekiguistian/Interaktif_Install-Unbound.git
cd Interaktif_Install-Unbound
bash ./Install-cli.sh
```

Jalankan `git clone` dan installer sebagai akun yang memiliki hak sudo, atau lanjutkan sebagai `root`. Installer memeriksa dependensi terlebih dahulu. Jika semuanya sudah tersedia, APT tidak diperbarui dan paket tidak dipasang ulang. Jika ada yang kurang, installer menjalankan `apt-get update` lalu memasang paket yang belum tersedia.

## Alur installer

1. Memeriksa versi OS, CPU, RAM, dan ruang kosong di `/var`. Host di bawah minimum akan dihentikan sebelum perubahan dilakukan.
2. Memeriksa dependensi wajib: Unbound, `unbound-anchor`, Python 3, curl, Git, UFW, dnsutils, sudo, Zabbix Agent, dan root hints. Jika ada paket yang kurang, installer memperbarui APT lalu memasangnya. Installer menambahkan repository resmi Zabbix untuk Jammy jika APT Ubuntu belum menyediakan agent.
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

Tes penuh dapat memakai banyak CPU dan memperlambat DNS klien selama pengujian. Jalankan saat beban rendah. Script menampilkan jumlah query terkirim, jawaban, query hilang, kecepatan rata-rata, dan latensi. Log dan daftar 100 domain untuk pemeriksaan singkat tersimpan di `dnsperf-results/`. [Manual dnsperf Ubuntu 22.04](https://manpages.ubuntu.com/manpages/jammy/man1/dnsperf.1.html)

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
