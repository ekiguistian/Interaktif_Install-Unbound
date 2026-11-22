#!/usr/bin/python3 -I
"""Fixed administrative operations for the Unbound web panel."""
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

APP = Path("/opt/www/unbound-web")
UNBOUND = Path("/etc/unbound/unbound.conf")
FILTER_CRON = Path("/etc/cron.d/unbound-web-filters")
WORKER_CRON = Path("/etc/cron.d/unbound-web")
HELPER = "/usr/local/sbin/unbound-web-manage"
AGENTS = {
    "zabbix-agent2": Path("/etc/zabbix/zabbix_agent2.conf"),
    "zabbix-agent": Path("/etc/zabbix/zabbix_agentd.conf"),
}


def command(*args):
    subprocess.run(args, check=True)


def active(unit):
    result = subprocess.run(["systemctl", "is-active", unit], capture_output=True, text=True)
    return result.stdout.strip() or "unknown"


def service_config(name):
    if name == "unbound":
        return name, UNBOUND
    if name != "zabbix":
        raise ValueError("Layanan tidak dikenal.")
    for unit, path in AGENTS.items():
        if active(unit) == "active":
            # Zabbix exposes its effective configuration path in its service arguments.
            pid = subprocess.check_output(["systemctl", "show", "-p", "MainPID", "--value", unit], text=True).strip()
            args = Path("/proc", pid, "cmdline").read_bytes().decode().split("\0")
            for option in ("-c", "--config"):
                if option in args and args.index(option) + 1 < len(args):
                    candidate = Path(args[args.index(option) + 1]).resolve()
                    if Path("/etc/zabbix") not in candidate.parents:
                        raise ValueError("Lokasi konfigurasi agent di luar /etc/zabbix.")
                    path = candidate
            return unit, path
    for unit, path in AGENTS.items():
        if path.is_file():
            return unit, path
    raise ValueError("File konfigurasi Zabbix Agent belum tersedia.")


def atomic(path, content, mode=0o644, owner=None):
    fd, name = tempfile.mkstemp(dir=path.parent, prefix=".unbound-web-")
    try:
        with os.fdopen(fd, "wb") as dest:
            dest.write(content)
            if owner:
                os.fchown(dest.fileno(), *owner)
            os.fchmod(dest.fileno(), mode)
            dest.flush()
            os.fsync(dest.fileno())
        os.replace(name, path)
    finally:
        Path(name).unlink(missing_ok=True)


@contextlib.contextmanager
def lock():
    with open("/run/unbound-web-control.lock", "a") as handle:
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield


def editor(operation, name, revision=None):
    unit, path = service_config(name)
    path = path.resolve(strict=True)
    old = path.read_bytes()
    digest = hashlib.sha256(old).hexdigest()
    if operation == "config-read":
        print(json.dumps(dict(path=str(path), revision=digest, content=old.decode())))
        return
    if revision != digest:
        raise ValueError("File berubah sejak dibuka. Muat ulang sebelum menyimpan.")
    data = sys.stdin.buffer.read(1048577)
    if not data.strip() or len(data) > 1048576 or b"\x00" in data:
        raise ValueError("Isi file kosong atau terlalu besar.")
    stat = path.stat()
    attrs = (stat.st_mode & 0o777, (stat.st_uid, stat.st_gid))
    fd, candidate = tempfile.mkstemp(dir=path.parent, prefix=".panel-check-")
    try:
        with os.fdopen(fd, "wb") as staged:
            staged.write(data)
            os.fchown(staged.fileno(), *attrs[1])
            os.fchmod(staged.fileno(), attrs[0])
        if name == "unbound":
            command("unbound-checkconf", candidate)
        else:
            binary = "zabbix_agent2" if unit == "zabbix-agent2" else "zabbix_agentd"
            help_text = subprocess.check_output([binary, "--help"], text=True)
            if "--test-config" in help_text:
                command("runuser", "-u", "zabbix", "--", binary, "-T", "-c", candidate)
        if path.read_bytes() != old:
            raise ValueError("File berubah saat validasi. Muat ulang sebelum menyimpan.")
        os.replace(candidate, path)
    finally:
        Path(candidate).unlink(missing_ok=True)
    try:
        # Agent units without ExecReload require restart for full config changes.
        command("systemctl", "reload" if name == "unbound" else "reload-or-restart", unit)
        command("systemctl", "is-active", "--quiet", unit)
    except (OSError, subprocess.SubprocessError):
        atomic(path, old, *attrs)
        command("systemctl", "reload-or-restart", unit)
        raise
    print("File tersimpan dan layanan sudah dimuat ulang.")


def flags():
    if not FILTER_CRON.exists():
        return ["0", "0"]
    match = re.match(r"# filters ([01]) ([01])\n", FILTER_CRON.read_text())
    if not match:
        raise ValueError("Format cron filter tidak dikenali.")
    return list(match.groups())


def sync(bits):
    command("/usr/bin/php8.4", str(APP / "bin/blocklist-sync.php"), *bits)


def main(args):
    op, *params = args
    if op.startswith("firewall-"):
        command("/usr/bin/php8.4", str(APP / "bin/firewall.php"), op.removeprefix("firewall-"), *params)
    elif op == "service-status" and not params:
        print("unbound=" + active("unbound"))
        zabbix = next((unit for unit in AGENTS if active(unit) == "active"), None)
        print("zabbix=" + ("active" if zabbix else "inactive"))
        if UNBOUND.exists():
            version = subprocess.run(["unbound", "-V"], capture_output=True, text=True)
            match = re.search(r"Version\s+([\d.]+)", version.stdout)
            if match:
                print("unbound_version=" + match[1])
    elif op == "stats" and not params:
        command("unbound-control", "-c", str(UNBOUND), "stats_noreset")
    elif op == "config-read" and len(params) == 1:
        editor(op, *params)
    elif op == "filters-state" and not params:
        block, white = flags()
        print(json.dumps(dict(block=block == "1", white=white == "1")))
    elif op == "cron-view" and not params:
        for path in [WORKER_CRON, FILTER_CRON]:
            if path.is_file():
                print(path.read_text(), end="")
    else:
        with lock():
            if op == "config-save" and len(params) == 2:
                editor(op, *params)
            elif op in ("unbound-restart", "unbound-reload") and not params:
                command("unbound-checkconf", str(UNBOUND))
                command("systemctl", op.split("-")[1], "unbound")
            elif op == "update-blocklists" and not params:
                bits = flags()
                if "1" not in bits:
                    raise ValueError("Aktifkan filter terlebih dahulu.")
                sync(bits)
            elif op == "filters-save" and len(params) == 2 and set(params) <= {"0", "1"}:
                old = flags()
                if params == old == ["0", "0"] and not Path(
                    "/etc/unbound/unbound.conf.d/90-unbound-web-blocklist.conf"
                ).exists():
                    print("Filter nonaktif.")
                    return
                sync(params)
                try:
                    if "1" in params:
                        body = f"# filters {' '.join(params)}\n15 3 * * * root {HELPER} update-blocklists\n"
                        atomic(FILTER_CRON, body.encode())
                    else:
                        FILTER_CRON.unlink(missing_ok=True)
                except OSError:
                    sync(old)
                    raise
                print("Jadwal sinkronisasi diperbarui.")
            else:
                raise ValueError("Operasi tidak dikenal.")


if __name__ == "__main__":
    os.environ["PATH"] = "/usr/sbin:/usr/bin:/sbin:/bin"
    try:
        main(sys.argv[1:])
    except BlockingIOError:
        sys.exit("Operasi lain masih berjalan.")
    except (ValueError, OSError, subprocess.SubprocessError) as error:
        sys.exit(str(error))
