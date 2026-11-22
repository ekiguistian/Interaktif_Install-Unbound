# Kontribusi

## Lapor bug atau usulkan fitur

Buka issue dan jelaskan masalah atau usulannya. Untuk laporan bug, sertakan hal berikut.

- Versi Ubuntu 22.04 yang digunakan.
- Nama halaman atau script dan tahap saat masalah muncul.
- Langkah untuk mengulang masalah.
- Pesan error atau log yang sudah disamarkan.

Ganti IP dan domain dengan contoh seperti `192.168.1.10` dan `yourdomain.com`. Hapus password, token, private key, hostname produksi, dan URL privat dari log. Untuk celah keamanan, ikuti [SECURITY.md](SECURITY.md).

## Kirim perubahan

- Ajukan perubahan melalui pull request.
- Web Manager menargetkan Ubuntu Server 22.04 LTS.
- Gunakan nilai contoh yang umum. Simpan kredensial dan konfigurasi produksi di luar repo.
- Perbarui panduan terkait jika langkah instalasi, pilihan, atau dependensi berubah.

Jalankan pemeriksaan yang sesuai dengan file yang diubah.

```bash
bash -n install.sh
bash -n update.sh
bash -n scripts/manage.sh
bash -n scripts/web-deploy.bash
bash -n scripts/web-migrate.bash
find app public bin -name '*.php' -print0 | xargs -0 -n1 php -l
node --check public/assets/app.js
git diff --check
```

Sebutkan hasil pemeriksaan dan OS yang dipakai di pull request.
