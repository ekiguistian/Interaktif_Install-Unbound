# Keamanan

## Unbound DNS Web Manager

- Web installer menaruh document root di `/opt/unbound-web/public`. File kredensial berada di `/etc/unbound-web/.env`, di luar document root. Atur pemilik `root:www-data` dan mode `0640`.
- Dashboard metrik dapat diakses publik. Halaman Settings dan semua perubahan sistem memerlukan login admin. Username dan password dibaca dari `.env`; tidak ada fungsi untuk mengubahnya dari web.
- Virtual host bawaan menggunakan HTTP pada port `8000`. HTTP tidak mengenkripsi password. Pakai jaringan privat/VPN atau reverse proxy HTTPS sebelum membuka login dari Internet.
- Sudoers hanya mengizinkan `www-data` menjalankan helper root `/usr/local/sbin/unbound-web-manage`. Helper hanya menerima aksi yang tercantum di dalam script; jangan memberi `www-data` hak sudo umum atau hak menulis ke helper.
- URL blocklist harus HTTPS dan hostname-nya harus resolve ke alamat publik. Unduhan dipatok ke IP publik yang sudah divalidasi dan redirect tidak diikuti. Sumber blocklist tetap merupakan input admin; gunakan penyedia yang dipercaya.
- Wizard mengganti resolver host sementara saat memeriksa port 53 dan koneksi DNS. State resolver ditulis ke direktori root-only; saat wizard gagal, file resolver dan status `systemd-resolved` sebelumnya dipulihkan.
- Benchmark dnsperf hanya menerima alamat IPv4 private atau loopback dan durasi sampai 300 detik. Client/thread bisa menggunakan CPU server secara penuh. Jangan menargetkan server yang tidak dikelola.
- UFW tidak diaktifkan otomatis. Jika diaktifkan dari web, pastikan aturan SSH telah dibuat agar sesi administrasi tidak terputus.
- Metrik query dan trafik disimpan di MySQL selama 30 hari. Dashboard tidak menampilkan alamat IP klien atau query individual.

Script CLI instalasi dan uninstalasi mengubah paket, konfigurasi DNS, resolver host, dan aturan UFW. Baca ringkasan yang tampil sebelum melanjutkan. Simpan cadangan konfigurasi penting sebelum menjalankan script pada server yang sudah digunakan.

## Pengaturan resolver

- Batasi **IP Allow** ke subnet LAN yang dipercaya. Nilai awal `192.168.1.0/24` hanya contoh; ganti dengan subnet milik sendiri.
- Jangan membuka recursion DNS ke `0.0.0.0/0` atau `::/0` pada server yang dapat diakses dari Internet.
- Periksa alamat interface, sumber SSH, dan alamat server Zabbix sebelum menerapkan konfigurasi firewall.
- Gunakan URL blocklist dan whitelist HTTPS dari sumber yang dipercaya.
- Uninstaller menghapus konfigurasi dan data Unbound, mereset aturan UFW, lalu menonaktifkan UFW. Pastikan akses jaringan lain sudah diatur sebelum menjalankannya.

## Log dan pengujian

Log query dapat memuat alamat IP klien dan nama domain yang diminta. Batasi akses ke log dan matikan pencatatan query jika tidak dibutuhkan.

Script dnsperf hanya mengirim query ke `127.0.0.1`, tetapi dapat memakai CPU dan mengirim query tanpa batas QPS dari script selama maksimal 5 menit. Jalankan saat beban rendah; layanan DNS lain di host yang sama dapat melambat. Jangan gunakan dnsperf untuk membebani resolver atau layanan milik pihak lain. Untuk pengujian server lain, pastikan ada izin dari pemiliknya.

## Melaporkan masalah

Jangan menaruh detail celah yang belum diperbaiki di issue publik. Gunakan pelaporan kerentanan privat GitHub bila tersedia. Jika tidak tersedia, hubungi pemelihara repository secara langsung.

Saat melaporkan masalah, sertakan versi Ubuntu/Debian, nama script, langkah untuk melihat masalah, dan log yang sudah disamarkan. Jangan sertakan password, token, private key, alamat IP server internal, nama host produksi, atau daftar blocklist/whitelist privat.
