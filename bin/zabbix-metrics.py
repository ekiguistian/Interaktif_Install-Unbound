#!/usr/bin/python3
"""Return the latest worker sample without database or root access."""
import json
import os
import stat
import time

SAMPLE = "/var/lib/unbound-web-zabbix/latest.json"
METRICS = (
    "unbound_up", "dns_stats_available", "total_queries", "total_replies", "qps", "cache_hit_percent",
    "recursion_avg_ms", "cpu_percent", "memory_used_mb", "memory_total_mb", "disk_used_gb",
    "disk_total_gb", "load_1m", "load_5m", "load_15m", "uptime_seconds",
)


def read_sample():
    result = {"sample_available": 0}
    try:
        fd = os.open(SAMPLE, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(fd, "rb") as source:
            if not stat.S_ISREG(os.fstat(source.fileno()).st_mode):
                return result
            sample = json.loads(source.read(8193))
        if not isinstance(sample, dict) or not isinstance(sample.get("sample_time"), int):
            return result
        if not 0 <= time.time() - sample["sample_time"] <= 15:
            return result
        result.update(sample_available=1, sample_time=sample["sample_time"])
        for key in METRICS:
            value = sample.get(key)
            if type(value) in (int, float):
                result[key] = value
    except (OSError, ValueError):
        pass
    return result


if __name__ == "__main__":
    print(json.dumps(read_sample(), separators=(",", ":")))

# System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
