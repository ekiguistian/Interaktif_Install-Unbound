#!/usr/bin/env python3

import os
import argparse
import re
import sys
import json
import csv
import shutil
import subprocess
import ipaddress
from datetime import datetime
import urllib.request

# =========================
# CONFIG
# =========================
OUTPUT_FILE = "/etc/unbound/blocklist/blocklist.conf"

TEMP_BLOCKLIST_RAW = "/usr/local/bin/domain-blocklist.txt"
TEMP_BLOCKLIST_CLEAN = "/usr/local/bin/last-temp-final.txt"
TEMP_WHITELIST = "/usr/local/bin/last-verified-whitelist.txt"

LOG_FILE = "/var/log/unbound-localzone.log"

REDIRECT_IP = "103.147.246.189"

SOURCES = [
    "https://raw.githubusercontent.com/alsyundawy/TrustPositif/refs/heads/main/gambling_indonesia_domainonly.txt",
    "https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/gambling-only/hosts",
    "https://raw.githubusercontent.com/StevenBlack/hosts/master/hosts",
    "https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/porn-only/hosts",
]

WHITELIST_URLS = [
    "https://raw.githubusercontent.com/ekiguistian/whitelist-host/refs/heads/main/whitelist-domain.txt"
]
CONFIG_FILE = os.environ.get("UNBOUND_BLOCKLIST_CONFIG", "/etc/unbound/blocklist/sources.json")

# =========================
# LOG
# =========================
def log(msg):
    line = f"{datetime.now().strftime('%Y-%m-%d %H:%M:%S')} - {msg}"
    print(line)
    with open(LOG_FILE, "a") as f:
        f.write(line + "\n")

# =========================
# FETCH
# =========================
def fetch(url):
    try:
        with urllib.request.urlopen(url, timeout=60) as r:
            return r.read().decode(errors="ignore")
    except Exception as e:
        log(f"[ERROR fetch] {url} -> {e}")
        return None

def load_settings(config_file):
    if not os.path.isfile(config_file):
        return SOURCES, WHITELIST_URLS, REDIRECT_IP

    try:
        with open(config_file, "r", encoding="utf-8") as stream:
            config = json.load(stream)
        block_sources = config.get("blocklist_sources", SOURCES)
        whitelist_sources = config.get("whitelist_sources", WHITELIST_URLS)
        redirect_ip = str(config.get("redirect_ip", REDIRECT_IP)).strip()
        if not isinstance(block_sources, list) or not block_sources:
            raise ValueError("blocklist_sources harus berupa list URL dan tidak boleh kosong")
        if not isinstance(whitelist_sources, list) or not whitelist_sources:
            raise ValueError("whitelist_sources harus berupa list URL dan tidak boleh kosong")
        for url in block_sources + whitelist_sources:
            if not isinstance(url, str) or not url.startswith("https://"):
                raise ValueError("semua URL sumber harus menggunakan https://")
        if ipaddress.ip_address(redirect_ip).version != 4:
            raise ValueError("redirect_ip harus berupa alamat IPv4")
        return block_sources, whitelist_sources, redirect_ip
    except (OSError, json.JSONDecodeError, ValueError) as error:
        raise SystemExit(f"Invalid blocklist configuration in {config_file}: {error}")

# =========================
# PARSER (MULTI FORMAT)
# =========================
def parse(raw):
    result = []

    # JSON
    try:
        data = json.loads(raw)
        if isinstance(data, list):
            result.extend([str(x) for x in data])
        elif isinstance(data, dict):
            for k, v in data.items():
                result.append(str(k))
                if isinstance(v, str):
                    result.append(v)
        if result:
            return result
    except:
        pass

    # CSV
    try:
        for row in csv.reader(raw.splitlines()):
            for col in row:
                result.append(col)
        if result:
            return result
    except:
        pass

    # hosts/plain
    for line in raw.splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue

        parts = line.split()

        if len(parts) >= 2 and re.match(r'^\d+\.\d+\.\d+\.\d+$', parts[0]):
            result.append(parts[1])
        else:
            result.append(parts[0])

    return result

# =========================
# NORMALIZE BLOCKLIST ONLY
# =========================
def normalize(domain):
    d = domain.strip().lower()

    if not d or d.startswith("#"):
        return None

    d = re.sub(r'^[a-z]+://', '', d)
    d = re.split(r'[\/,; ]', d)[0]

    if d.startswith("www."):
        d = d[4:]

    d = d.rstrip(".")

    if len(d) > 253 or "." not in d:
        return None
    label_pattern = r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?"
    if not re.fullmatch(rf"{label_pattern}(?:\.{label_pattern})*", d):
        return None

    return d

# =========================
# MAIN
# =========================
def main():

    parser = argparse.ArgumentParser(description="Update Unbound blocklist using configured HTTPS sources")
    parser.add_argument("--config", default=CONFIG_FILE, help="JSON configuration file")
    parser.add_argument("--no-reload", action="store_true", help="write and validate the list without reloading Unbound")
    args = parser.parse_args()
    sources, whitelist_urls, redirect_ip = load_settings(args.config)

    os.makedirs("/etc/unbound/blocklist", exist_ok=True)
    os.makedirs("/usr/local/bin", exist_ok=True)

    # =========================
    # 1. DOWNLOAD BLOCKLIST
    # =========================
    raw_domains = []

    source_success = 0
    for url in sources:
        raw = fetch(url)
        if raw is None:
            continue
        source_success += 1
        parsed = parse(raw)
        raw_domains.extend(parsed)

    if source_success == 0:
        log("[ERROR] no blocklist source could be downloaded; keeping the previous config")
        sys.exit(1)

    with open(TEMP_BLOCKLIST_RAW, "w") as f:
        for d in raw_domains:
            f.write(d.strip() + "\n")

    log("[download blocklist] - done")

    # =========================
    # 2. NORMALIZE BLOCKLIST
    # =========================
    normalized = set()

    for d in raw_domains:
        n = normalize(d)
        if n:
            normalized.add(n)

    if not normalized:
        log("[ERROR] downloaded blocklists contained no valid domains; keeping the previous config")
        sys.exit(1)

    log("[normalisasi blocklist ke file .txt] - done")

    # =========================
    # 3. DOWNLOAD WHITELIST
    # =========================
    whitelist = set()
    whitelist_success = 0
    for url in whitelist_urls:
        wl_raw = fetch(url)
        if wl_raw is None:
            continue
        whitelist_success += 1
        for line in wl_raw.splitlines():
            line = line.strip().lower()
            if not line or line.startswith("#"):
                continue
            parts = line.split()
            if len(parts) >= 2 and re.match(r"^\d+\.\d+\.\d+\.\d+$", parts[0]):
                line = parts[1]
            normalized_whitelist = normalize(line)
            if normalized_whitelist:
                whitelist.add(normalized_whitelist)

    if whitelist_success == 0:
        log("[ERROR] no whitelist source could be downloaded; keeping the previous config")
        sys.exit(1)

    with open(TEMP_WHITELIST, "w") as f:
        for d in sorted(whitelist):
            f.write(d + "\n")

    log("[download whitelist] - done")

    # =========================
    # 4. SAMAKAN FORMAT (implicit)
    # =========================
    # whitelist tidak diubah
    # blocklist sudah normalized

    # =========================
    # 5. MATCH + REMOVE (5x pass)
    # =========================
    final = normalized.copy()

    for i in range(5):
        final = set([d for d in final if d not in whitelist])

    log("[pencocokan blocklist & whitelist / dedup duplicated] - done")

    # =========================
    # 6. SAVE CLEAN TEMP
    # =========================
    with open(TEMP_BLOCKLIST_CLEAN, "w") as f:
        for d in sorted(final):
            f.write(d + "\n")

    log("[clean final blocklist] - done")

    # =========================
    # 7. GENERATE UNBOUND
    # =========================
    with open(OUTPUT_FILE, "w") as f:
        for d in sorted(final):
            f.write(f'local-zone: "{d}" redirect\n')
            f.write(f'local-data: "{d} A {redirect_ip}"\n')

    log("[generate unbound local-zone] - done")

    # =========================
    # 8. VALIDATE + RELOAD
    # =========================
    try:
        subprocess.check_call(["unbound-checkconf"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if args.no_reload:
            log("[unbound reload] - skipped by --no-reload")
        else:
            subprocess.check_call(["unbound-control", "reload"])
            log("[unbound reload] - success")
    except subprocess.CalledProcessError:
        log("[unbound reload] - FAILED")
        sys.exit(1)

    # =========================
    # SUMMARY
    # =========================
    print(f"""
=== FINAL SUMMARY ===
Raw Blocklist     : {len(raw_domains)}
Normalized        : {len(normalized)}
Whitelist         : {len(whitelist)}
Final Clean       : {len(final)}

Raw File          : {TEMP_BLOCKLIST_RAW}
Whitelist File    : {TEMP_WHITELIST}
Clean Temp File   : {TEMP_BLOCKLIST_CLEAN}
Output            : {OUTPUT_FILE}
====================
""")

if __name__ == "__main__":
    main()
