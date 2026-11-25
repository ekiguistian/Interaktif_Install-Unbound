# Statistik Unbound di Zabbix

1. Buka **Zabbix Agent → Aktifkan statistik Unbound** di panel. Agent 2 memakai `/etc/zabbix/zabbix_agent2.conf` dan file `/etc/zabbix/zabbix_agent2.d/unbound-web-metrics.conf`. Panel memakai `Include` bawaan jika sudah mencakup file tersebut.
2. Di konfigurasi agent, isi `Server=` dengan IP Zabbix Server atau proxy untuk **passive checks**. Jika memakai **active checks**, isi `ServerActive=` dan samakan `Hostname=` dengan nama host di Zabbix. Untuk passive checks, buka TCP 10050 hanya dari server atau proxy yang akan mengambil metrik.
3. Setelah beberapa detik, tes item di server Unbound:

```bash
sudo -u zabbix zabbix_agent2 -c /etc/zabbix/zabbix_agent2.conf -t unbound.web.metrics
```

Hasilnya JSON dengan `sample_available: 1`. Pastikan `dns_stats_available: 1` jika ingin memantau statistik DNS. Untuk agent klasik, gunakan `zabbix_agentd` dan `/etc/zabbix/zabbix_agentd.conf`.

## Buat item di Zabbix Server

Di **Data collection → Hosts → Items → Create item**, buat item utama:

| Pengaturan | Nilai |
|---|---|
| Name | Unbound metrics |
| Type | Zabbix agent atau Zabbix agent (active) |
| Key | `unbound.web.metrics` |
| Type of information | Text |
| Update interval | `30s` |

Buat satu **Dependent item** per metrik. Pilih **Unbound metrics** sebagai master, beri key yang unik, dan di **Preprocessing** pilih **JSONPath** sesuai tabel. Untuk metrik yang kadang tidak tersedia, gunakan **Custom on fail → Discard value** agar nilai yang hilang tidak direkam sebagai nol.

| Nama | Key | JSONPath | Tipe / satuan |
|---|---|---|---|
| Total query | `unbound.web.queries` | `$.total_queries` | Numeric (unsigned) |
| Total reply | `unbound.web.replies` | `$.total_replies` | Numeric (unsigned) |
| QPS | `unbound.web.qps` | `$.qps` | Numeric (float), `qps` |
| Cache hit | `unbound.web.cache_hit` | `$.cache_hit_percent` | Numeric (float), `%` |
| Latensi rekursi | `unbound.web.recursion_ms` | `$.recursion_avg_ms` | Numeric (float), `ms` |
| CPU | `unbound.web.cpu` | `$.cpu_percent` | Numeric (float), `%` |
| RAM terpakai | `unbound.web.memory` | `$.memory_used_mb` | Numeric (unsigned), `!MiB` |
| Disk terpakai | `unbound.web.disk` | `$.disk_used_gb` | Numeric (float), `!GiB` |
| Load 1 menit | `unbound.web.load` | `$.load_1m` | Numeric (float) |
| Uptime Linux | `unbound.web.uptime` | `$.uptime_seconds` | Numeric (unsigned), `uptime` |
| Status Unbound | `unbound.web.up` | `$.unbound_up` | Numeric (unsigned), 0/1 |
| Statistik DNS tersedia | `unbound.web.dns_available` | `$.dns_stats_available` | Numeric (unsigned), 0/1 |
| Sampel tersedia | `unbound.web.sample_available` | `$.sample_available` | Numeric (unsigned), 0/1 |

Jika kolektor berhenti, `sample_available` menjadi `0`. Jika `unbound-control` gagal, statistik DNS tidak dikirim, sementara metrik Linux tetap tersedia. Total query dan reply adalah counter kumulatif yang dapat kembali ke nol setelah restart. QPS sudah dihitung oleh panel.

## Buat grafik

1. Cek nilai item di **Monitoring → Latest data**.
2. Buka **Data collection → Hosts → Graphs → Create graph**.
3. Buat grafik **Unbound QPS** dengan item QPS. Buat grafik **Total query dan reply** dengan kedua counter. Buat grafik CPU, RAM, dan disk terpisah agar skala satuannya jelas.
4. Lihat grafik di **Monitoring → Hosts → Graphs** atau masukkan ke dashboard memakai widget grafik.

Dokumentasi resmi Zabbix mencakup [UserParameter](https://www.zabbix.com/documentation/7.0/en/manual/config/items/userparameters), [dependent items](https://www.zabbix.com/documentation/7.0/en/manual/config/items/itemtypes/dependent_items), dan [grafik host](https://www.zabbix.com/documentation/7.0/en/manual/web_interface/frontend_sections/data_collection/hosts/graphs).

<!-- System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource -->
