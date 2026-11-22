# Panduan proyek

## Sistem yang dipelihara

- Repository ini berisi Unbound DNS Web Manager untuk Ubuntu Server 22.04 LTS (Jammy) dengan systemd. Alur produk berjalan melalui `install.sh`, `update.sh`, aplikasi PHP, API, dan helper systemd; jangan menghidupkan kembali installer CLI yang sudah dihapus.
- `install.sh` memasang paket Unbound Ubuntu beserta dependensi aplikasi. Pertahankan `/lib/systemd/system/unbound.service` dan binary paket di `/usr/sbin`; aplikasi tidak membuat, menimpa, atau menonaktifkan unit Unbound.
- Gunakan dokumentasi resmi Unbound terbaru sebagai acuan konfigurasi dan jangan menambahkan kembali contoh konfigurasi lama.
- Nginx menyajikan `/opt/unbound-web/public` pada port 8000. PHP 8.4 memakai PDO/MySQL. Perubahan web berjalan melalui `/usr/local/sbin/unbound-web-manage` dengan aksi tetap dan unit systemd sementara untuk helper, bukan pengganti service Unbound.
- Kredensial berada di `/etc/unbound-web/.env`; jangan menaruh rahasia, hostname produksi, atau URL privat di repository.

## Konsistensi dan keamanan

- Perubahan antarmuka harus tetap cocok dengan API, validasi server, dan helper. Alur SSE harus membaca status akhir proses; wizard hanya maju satu tahap setelah tahap tersebut berhasil, mempertahankan tahap gagal agar dapat diulang, dan menampilkan label tombol yang sesuai. Wizard hanya memeriksa paket/config dan menjalankan service bawaan; jika paket belum lengkap, arahkan admin menjalankan `install.sh`. Konfigurasi mode resolver, upstream, DNSSEC, root hints, dan verifikasi berada di Settings.
- Pertahankan validasi input, CSRF, PDO prepared statements, dispatcher sudo allowlist, dan pembatasan mount PHP-FPM. Jangan menjalankan aksi sistem dari input bebas.
- Validasi konfigurasi Unbound sebelum reload. Wizard mempertahankan DNS sistem. Perubahan DNS dari Settings harus memakai mount guard, validasi IP, uji koneksi, dan rollback.
- Jangan menimpa `/etc/unbound/unbound.conf`. Simpan konfigurasi aplikasi di `/etc/unbound/unbound.conf.d/50-unbound-web.conf`, validasi konfigurasi gabungan, lalu reload service paket dengan rollback bila gagal.
- URL web bersih menuju front controller. SSE memerlukan FastCGI buffering nonaktif. Jangan menghapus atau mengganti site Nginx lain.
- Dokumentasi dan label pengguna menggunakan bahasa Indonesia singkat. Gunakan IP contoh dan `yourdomain.com`.

## Pemeriksaan

Jalankan pemeriksaan yang sesuai dengan file yang diubah. Daftar pemeriksaan statis ada di `CONTRIBUTING.md`; verifikasi runtime instalasi hanya dapat dilakukan pada Ubuntu Jammy dengan systemd. Laporkan pemeriksaan yang benar-benar dijalankan dan batas lingkungan.
