# Kontribusi

Laporan bug dan perbaikan sangat membantu. Gunakan issue untuk menjelaskan masalah, lalu ajukan perubahan melalui pull request.

## Laporan masalah

Sertakan:

- versi Ubuntu atau Debian;
- nama script dan tahap saat masalah muncul;
- pesan error lengkap yang sudah disamarkan;
- langkah untuk mengulang masalah.

Ganti alamat server dengan `192.168.1.10`, domain dengan `yourdomain.com`, dan hapus password, token, private key, hostname produksi, serta URL daftar privat sebelum mengirim log.

## Perubahan kode

- Pertahankan dukungan Ubuntu/Debian, APT, Bash, dan systemd.
- Jangan menambahkan IP, domain, credential, URL whitelist pribadi, atau nilai konfigurasi milik satu organisasi sebagai default.
- Untuk contoh konfigurasi, gunakan IP privat kelas C seperti `192.168.1.10` dan domain `yourdomain.com`.
- Sertakan perubahan README bila alur, pilihan, atau kebutuhan paket berubah.
- Untuk perubahan script Bash, jalankan pemeriksaan sintaks dan whitespace sebelum mengirim pull request:

```bash
bash -n Install.sh
bash -n Uninstall.sh
bash -n Tes.sh
git diff --check
```

Jelaskan hasil pemeriksaan yang dilakukan dan OS tempat pemeriksaan dijalankan.
