# Ikut ngembangin

Kalau mau bantu, mulai dari satu masalah yang jelas. Contohnya, tulis “grafik RX salah satuan saat trafik kecil” dan sertakan nilai yang kamu lihat. Untuk perubahan UI, screenshot sebelum dan sesudah biasanya paling gampang dipahami.

## Alur kerja

1. Buat branch dari `main`. Sebutkan menu atau perintah yang kena perubahan.
2. Ikuti jalurnya sampai backend. Halaman ada di `public/`, pembaca data dan utilitas di `app/`, worker serta helper sistem di `bin/`.
3. Pakai VM Ubuntu buat ngetes perubahan yang menyentuh service, cron, filter DNS, firewall, atau installer. Pastikan kamu masih punya akses konsol kalau sedang menguji rule jaringan.
4. Di PR, tulis apa yang berubah, langkah mencobanya, hasil yang kamu lihat, dan bagian yang belum sempat dites.

## Cek sebelum kirim

```bash
find app bin public -name '*.php' -exec php -l {} \;
node --check public/assets/app.js
bash -n install.sh
python3 -m py_compile bin/manage.py
```

Coba juga akses dashboard dan API metrik sebelum login. Untuk editor file, coba konfigurasi valid, konfigurasi salah, dan file yang diubah dari terminal saat editor terbuka. Untuk grafik, bandingkan angka dengan perintah Linux atau statistik resolver di waktu yang sama.

Panel ini membaca layanan yang sudah ada. Kalau kontribusimu menyentuh konfigurasi server, pastikan perubahan terjadi setelah admin menekan kontrol yang sesuai. Contoh issue, log, dan screenshot pakai data contoh saja.
