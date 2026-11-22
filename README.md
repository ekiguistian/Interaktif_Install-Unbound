# Instalasi Unbound DNS Server Interaktif

Installer Bash interaktif yang menyiapkan resolver Unbound dari host Ubuntu Server atau Debian yang baru: memasang dependensi, membuat dan memasang konfigurasi, menyiapkan root hints dan DNSSEC, mengaktifkan service, serta mengatur UFW. Blocklist/whitelist dan Zabbix agent tersedia sebagai opsi.

## Dukungan OS dan minimum requirement

Versi rilis berubah. Pada **22 November 2026**, rilis terbaru yang tercatat adalah Ubuntu 26.10, Ubuntu 26.04 LTS, dan Debian 13.8 (Trixie). Minimum OS yang ditargetkan installer adalah Ubuntu Server 24.04 LTS atau lebih baru dan Debian 12 (Bookworm) atau lebih baru.

| Kebutuhan | Minimum praktis | Rekomendasi produksi |
| --- | --- | --- |
| CPU | 1 vCPU | 2+ vCPU |
| RAM | 512 MB | 2 GB+ |
| Ruang kosong | 1 GB | 2 GB+ |
| OS | Ubuntu Server 24.04 LTS+ atau Debian 12+ | Ubuntu Server 26.04 LTS atau Debian 13 |
| Akses | root atau sudo | Akun operator dengan sudo |
| Jaringan | Akses APT mirror dan DNS | APT, DNS port 53, HTTPS port 443, dan Zabbix jika digunakan |
| Init/firewall | systemd dan UFW | systemd, UFW, hak `NET_ADMIN` pada LXC |

Resource minimum di atas adalah perkiraan praktis untuk installer dan layanan dasar, bukan angka resmi dari vendor Unbound. Beban DNS, cache, blocklist, dan jumlah klien dapat memerlukan resource lebih besar.

Rujukan versi: [Ubuntu releases](https://wiki.ubuntu.com/Releases), [Ubuntu release cycle](https://ubuntu.com/about/release-cycle), [Debian releases](https://www.debian.org/releases/index), dan [pengumuman Debian 13.8](https://www.debian.org/News/2026/20261121).

## Dependensi

Pada awal aplikasi, dependensi wajib diperiksa dan paket yang belum ada dipasang melalui APT:

- `bash`
- `unbound` termasuk `unbound-checkconf`, `unbound-anchor`, dan `unbound-control-setup`
- `python3` (hanya memakai standard library; tidak memerlukan pip)
- `ufw`
- `dnsutils` untuk perintah `dig` pada pemeriksaan DNS/DNSSEC
- `dns-root-data` untuk file root hints
- `sudo` agar perintah sistem dapat dijalankan dengan hak root
- `systemd` untuk mengaktifkan dan memeriksa service/timer

Jika Zabbix dipilih, aplikasi memasang `zabbix-agent` bila belum ada. Host harus dapat mengakses repository APT untuk memasang paket. Jalankan sebagai root atau pengguna dengan sudo. LXC Proxmox perlu mengizinkan systemd dan kapabilitas jaringan yang diperlukan UFW.

## Instalasi

Clone repository lalu jalankan dari direktori tempat file lokal ingin disimpan:

```bash
git clone https://github.com/ekiguistian/Interaktif_Install-Unbound.git
cd Interaktif_Install-Unbound
chmod +x instalasi-unbound-interaktif.sh
./instalasi-unbound-interaktif.sh
```

Jalankan sebagai pengguna dengan hak sudo agar output lokal tetap dimiliki pengguna tersebut. Jika sudo belum tersedia, jalankan sebagai root. Installer menyimpan salinan konfigurasi lokal di direktori kerja dan memasang konfigurasi aktif ke sistem. File sistem yang sebelumnya ada dicadangkan sebelum diganti.

## Alur aplikasi

1. Memeriksa dan memasang Unbound, Python 3, UFW, DNS utilities, sudo, dan root hints.
2. Menampilkan menu Generate atau Keluar.
3. Meminta tipe server, interface IPv4/IPv6, jaringan akses, CPU, RAM MB, disk MB, perkiraan perangkat, target cache, dan IP IN PTR.
4. Meminta mode upstream, logging, blocklist/whitelist, monitoring Zabbix, serta sumber IP untuk SSH.
5. Menampilkan ringkasan seluruh input dan port firewall. Pilih lanjutkan atau cek ulang.
6. Menyiapkan runtime files, membuat konfigurasi, memvalidasi, memasang, lalu mengaktifkan layanan.
7. Menguji query DNS dan DNSSEC, mengaktifkan UFW, lalu menguji DNS lagi.

Jika validasi, pembaruan awal daftar, restart Unbound, atau pemeriksaan DNS gagal, installer melaporkan tahap yang gagal dan memulihkan konfigurasi Unbound sebelumnya bila ada.

## Upstream, root server, TLD, dan DNSSEC

Pilih salah satu mode:

1. **Root saja**: Unbound melakukan resolusi rekursif dari root hints. TLD publik, termasuk `.id`, ditemukan otomatis; tidak perlu memasukkan daftar TLD.
2. **Forwarder saja**: query diteruskan ke alamat yang dimasukkan. Jumlah default dua dengan IP `1.1.1.1` dan `8.8.8.8`. Tekan Enter pada input IP untuk memakai nilai default. Upstream IPv4 atau IPv6 dapat dimasukkan.
3. **Root + forwarder fallback**: forwarder dicoba terlebih dahulu; root recursion digunakan jika forwarder gagal.

Installer menyiapkan `/var/lib/unbound/root.hints`, root trust anchor `/var/lib/unbound/root.key`, dan `auto-trust-anchor-file`. DNSSEC validation diaktifkan lewat `module-config: "validator iterator"` dan hardening DNSSEC. Setelah restart, installer menjalankan query DNS dan query DNSSEC untuk memeriksa hasilnya.

TLD privat atau zona khusus perlu delegasi sendiri. Tambahkan `forward-zone` ke `/etc/unbound/unbound.conf`, misalnya:

```conf
forward-zone:
    name: "corp."
    forward-addr: 10.10.10.53
```

Rujukan: [Unbound configuration guide](https://unbound.docs.nlnetlabs.nl/en/latest/getting-started/configuration.html), [trust anchor/DNSSEC](https://unbound.docs.nlnetlabs.nl/en/latest/manpages/unbound-anchor.html).

## Blocklist dan whitelist

Jika dipilih, masukkan jumlah URL blocklist, URL whitelist, dan IPv4 tujuan redirect domain terblokir. Sumber harus memakai HTTPS. Installer memasang helper Python ke `/usr/local/bin/autoupdate-blocklist.py`, menyimpan daftar sumber ke `/etc/unbound/blocklist/sources.json`, lalu mengunduh daftar pertama kali sebelum Unbound dimulai.

Konfigurasi Unbound meng-include `/etc/unbound/blocklist/blocklist.conf`. Installer juga membuat systemd timer untuk pembaruan harian dan 15 menit setelah boot. Log updater disimpan di `/var/log/unbound-localzone.log`.

Untuk menjalankan pembaruan manual:

```bash
sudo python3 /usr/local/bin/autoupdate-blocklist.py
```

Jika semua URL sumber tidak dapat diakses atau tidak menghasilkan domain valid, proses instalasi berhenti sebelum mengganti konfigurasi service yang sedang berjalan.

## Monitoring Zabbix

Jika dipilih, installer memasang `zabbix-agent` (mengaktifkan komponen Ubuntu Universe jika paket belum tersedia), meminta hostname agent dan IPv4 server Zabbix, lalu memasang konfigurasi ke `/etc/zabbix/zabbix_agentd.conf`. Agent dikonfigurasi untuk passive dan active checks, lalu diaktifkan sebagai service.

Installer juga menambahkan `UserParameter=unbound.stat[*]` agar Zabbix dapat membaca statistik Unbound melalui `unbound-control`. Akses sudo dibatasi pada satu perintah `unbound-control stats_noreset`, diperiksa dengan `visudo`, lalu konfigurasi agent diperiksa sebelum service dimulai. Gunakan key seperti `unbound.stat[total.num.queries]` pada item Zabbix.

Rujukan: [Zabbix Agent configuration](https://www.zabbix.com/documentation/current/en/manual/appendix/config/zabbix_agentd).

## Firewall UFW

Setelah ringkasan dikonfirmasi, installer mereset aturan UFW lama, menetapkan **deny incoming** dan **deny outgoing**, lalu mengizinkan:

- DNS TCP/UDP 53 masuk dan keluar untuk IPv4/IPv6.
- HTTPS TCP 443 masuk dan keluar untuk IPv4/IPv6.
- SSH TCP 22 masuk dan keluar untuk IPv4/IPv6. Masukkan sumber IP/CIDR untuk membatasi koneksi masuk. Input kosong membuka SSH dari mana saja.
- Jika Zabbix dipilih, TCP 10050 masuk dari IP Zabbix dan TCP 10051 keluar menuju IP tersebut.

Port lainnya diblokir. Periksa IP sumber SSH sebelum melanjutkan: aturan lama akan dihapus dan sumber yang salah dapat memutus akses administrasi jarak jauh.

## File yang dihasilkan dan dipasang

Salinan lokal di direktori kerja:

- `unbound.conf`
- `blocklist-sources.json` bila blocklist/whitelist diaktifkan
- `zabbix_agentd.conf` bila Zabbix dipilih

File sistem:

- `/etc/unbound/unbound.conf`
- `/var/lib/unbound/root.hints` dan `/var/lib/unbound/root.key`
- `/etc/unbound` remote-control certificates
- File blocklist dan updater bila opsi tersebut dipilih
- `/etc/zabbix/zabbix_agentd.conf` serta sudoers rule terbatas bila Zabbix dipilih
- UFW rules dan systemd timer blocklist bila blocklist dipilih

File lama dicadangkan dengan suffix `.bak.<timestamp>`. Layanan Unbound di-enable dan direstart oleh installer; Zabbix agent juga diaktifkan bila dipilih.

## Catatan akses

- Default `IP Allow akses` adalah `0.0.0.0/0` sesuai permintaan. Ini membuka recursive resolver untuk semua IPv4. Untuk DNS privat, ubah ke subnet internal, misalnya `10.10.10.0/24`. CIDR IPv6 juga dapat ditambahkan, misalnya `2001:db8::/64`.
- IP interface harus sudah terpasang pada host Linux. `127.0.0.1` ditambahkan otomatis untuk pemeriksaan lokal.
- Target cache adalah profil sizing, bukan jaminan cache hit rate 99.9%.
- Hasil akhir bergantung pada akses APT, DNS upstream, HTTPS source lists, systemd, izin UFW di VM/LXC, dan sumber IP SSH yang dimasukkan.
