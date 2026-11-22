# Keamanan

Script instalasi dan uninstalasi mengubah paket, konfigurasi DNS, resolver host, dan aturan UFW. Baca ringkasan yang tampil sebelum melanjutkan. Simpan cadangan konfigurasi penting sebelum menjalankan script pada server yang sudah digunakan.

## Pengaturan resolver

- Batasi **IP Allow** ke subnet LAN yang dipercaya. Nilai awal `192.168.1.0/24` hanya contoh; ganti dengan subnet milik sendiri.
- Jangan membuka recursion DNS ke `0.0.0.0/0` atau `::/0` pada server yang dapat diakses dari Internet.
- Periksa alamat interface, sumber SSH, dan alamat server Zabbix sebelum menerapkan konfigurasi firewall.
- Gunakan URL blocklist dan whitelist HTTPS dari sumber yang dipercaya.
- Uninstaller menghapus konfigurasi dan data Unbound, mereset aturan UFW, lalu menonaktifkan UFW. Pastikan akses jaringan lain sudah diatur sebelum menjalankannya.

## Log dan pengujian

Log query dapat memuat alamat IP klien dan nama domain yang diminta. Batasi akses ke log dan matikan pencatatan query jika tidak dibutuhkan.

Script dnsperf hanya mengirim query ke `127.0.0.1`. Jangan gunakan dnsperf untuk membebani resolver atau layanan milik pihak lain. Untuk pengujian server lain, pastikan ada izin dari pemiliknya.

## Melaporkan masalah

Jangan menaruh detail celah yang belum diperbaiki di issue publik. Gunakan pelaporan kerentanan privat GitHub bila tersedia. Jika tidak tersedia, hubungi pemelihara repository secara langsung.

Saat melaporkan masalah, sertakan versi Ubuntu/Debian, nama script, langkah untuk melihat masalah, dan log yang sudah disamarkan. Jangan sertakan password, token, private key, alamat IP server internal, nama host produksi, atau daftar blocklist/whitelist privat.
