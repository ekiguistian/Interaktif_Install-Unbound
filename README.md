# Instalasi Unbound DNS Server Interaktif

Installer Bash untuk menyiapkan Unbound DNS Resolver di Ubuntu Server dan Debian. Installer memeriksa spesifikasi host, memasang dependensi, membuat konfigurasi DNS, mengatur UFW, lalu menguji layanan.

## Sistem yang didukung

- Ubuntu Server 24.04 atau lebih baru
- Debian 12 atau lebih baru
- Minimal 1 CPU, RAM 512 MB, dan ruang kosong 1 GB di `/var`
- systemd, APT, dan akses root atau sudo
- LXC Proxmox perlu mendukung systemd dan UFW (`NET_ADMIN`)

Pada 22 November 2026, rilis terbaru yang tercatat adalah Ubuntu 26.10, Ubuntu 26.04 LTS, dan Debian 13.8. Lihat [rilis Ubuntu](https://wiki.ubuntu.com/Releases) dan [rilis Debian](https://www.debian.org/releases/index).

## Menjalankan installer

Login sebagai `root`. Perbarui APT dan pasang Git agar repository dapat di-clone:

```bash
apt-get update
apt-get install -y git ca-certificates curl
```

Clone repository, lalu jalankan installer:

```bash
git clone https://github.com/ekiguistian/Interaktif_Install-Unbound.git
cd Interaktif_Install-Unbound
chmod +x instalasi-unbound-interaktif.sh
./instalasi-unbound-interaktif.sh
```

Jika memakai akun biasa, tambahkan `sudo` pada dua perintah APT saja. Jalankan `git clone` dan installer sebagai akun tersebut dengan hak sudo. Installer akan kembali menjalankan `apt-get update`, lalu memeriksa dan memasang dependensi sistem yang belum tersedia. Jika `sudo` belum terpasang, jalankan installer sebagai `root`.

## Alur installer

1. Memeriksa versi OS, CPU, RAM, dan ruang kosong di `/var`. Host di bawah minimum akan dihentikan sebelum perubahan dilakukan.
2. Menjalankan `apt-get update`.
3. Memeriksa lalu memasang dependensi wajib: Unbound, Python 3, curl, Git, UFW, dnsutils, sudo, Zabbix Agent, dan root hints. Repository resmi Zabbix ditambahkan jika paket Agent tidak tersedia di APT distro.
4. Meminta jenis server, alamat interface, jaringan yang boleh mengakses DNS, resource, PTR, upstream, logging, blocklist/whitelist, Zabbix, dan aturan SSH.
5. Menampilkan ringkasan untuk diperiksa sebelum konfigurasi diterapkan.
6. Membuat konfigurasi, memvalidasi dengan `unbound-checkconf`, memasang Unbound, dan mengatur UFW.
7. Menguji query Internet melalui Unbound, validasi DNSSEC, dan alamat interface setelah firewall aktif.
8. Menyelesaikan pengaturan resolver sesuai jenis server.

Jika validasi konfigurasi atau pemeriksaan DNS gagal, installer melaporkan masalah dan memulihkan konfigurasi Unbound sebelumnya bila tersedia.

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

## Firewall

Installer mereset aturan UFW yang ada, lalu menetapkan deny incoming dan deny outgoing. Port yang dibuka:

- DNS TCP/UDP 53, masuk dan keluar
- HTTPS TCP 443, masuk dan keluar
- SSH TCP 22, masuk dan keluar; sumber IP dapat dibatasi
- Jika Zabbix dipilih: TCP 10050 dari server Zabbix dan TCP 10051 ke server Zabbix

Periksa sumber IP SSH sebelum menyetujui ringkasan. Aturan lama dihapus saat UFW diatur ulang.

## File hasil

- `unbound.conf` di direktori kerja dan `/etc/unbound/unbound.conf`
- `/etc/unbound/blocklist/` jika blocklist/whitelist digunakan
- Konfigurasi Zabbix di `/etc/zabbix/zabbix_agentd.conf` jika monitoring digunakan
- Cadangan konfigurasi sistem dengan akhiran `.bak.<waktu>`

## Catatan akses DNS

Nilai awal IP Allow adalah `0.0.0.0/0`. Nilai ini mengizinkan query DNS dari semua alamat IPv4. Untuk resolver privat, masukkan subnet klien tepercaya, misalnya `10.10.10.0/24`.
