#!/usr/bin/python3 -I
"""Fixed administrative operations for the Unbound web panel."""
import contextlib
import fcntl
import glob
import grp
import hashlib
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import sys
import tempfile

APP = Path("/opt/www/unbound-web")
UNBOUND = Path("/etc/unbound/unbound.conf")
FILTER_CRON = Path("/etc/cron.d/unbound-web-filters")
WORKER_CRON = Path("/etc/cron.d/unbound-web")
HELPER = "/usr/local/sbin/unbound-web-manage"
CONTROL_CACHE = Path("/var/lib/unbound-web-filters/control-client.conf")
CONTROL_META = CONTROL_CACHE.with_suffix(".json")
ROOT_HINTS = Path("/etc/unbound/unbound-web-root.hints")
ROOT_KEY = Path("/var/lib/unbound/root.key")
AGENTS = {
    "zabbix-agent2": Path("/etc/zabbix/zabbix_agent2.conf"),
    "zabbix-agent": Path("/etc/zabbix/zabbix_agentd.conf"),
}


def command(*args):
    subprocess.run(args, check=True)


def host_command(*args):
    # Long-running root jobs and validation get their own unit. An OOM during
    # validation must return a failure, rather than stopping the FPM service.
    return ["/usr/bin/systemd-run", "--quiet", "--wait", "--pipe", "--collect",
            "--service-type=exec", "--property=OOMPolicy=continue",
            "--property=OOMScoreAdjust=500", "--", *args]


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


def control_config():
    """Read connection options without reparsing managed DNS zones every second."""
    def stamp(path):
        info = Path(path).stat()
        return [info.st_mtime_ns, info.st_size, info.st_ino]

    with open("/run/unbound-web-client.lock", "a") as handle:
        fcntl.flock(handle, fcntl.LOCK_EX)
        try:
            metadata = json.loads(CONTROL_META.read_text())
            if (CONTROL_CACHE.is_file() and metadata.get("files")
                    and all(stamp(path) == value for path, value in metadata["files"].items())):
                return CONTROL_CACHE
        except (OSError, ValueError, TypeError, KeyError):
            pass
        watched, server, remote = {}, [], []
        managed = {Path("/etc/unbound/blocklist/blocklist.conf"),
                   Path("/etc/unbound/blocklist/whitelist.conf"),
                   Path("/etc/unbound/unbound.conf.d/90-unbound-web-blocklist.conf")}
        remote_keys = {"control-enable", "control-interface", "control-port", "control-use-cert",
                       "server-key-file", "server-cert-file", "control-key-file", "control-cert-file"}

        def read(path, section="", stack=()):
            path = path.resolve()
            if path in managed:
                return section
            if path in stack or len(stack) >= 128:
                raise ValueError("Include konfigurasi Unbound berulang atau terlalu dalam.")
            watched[str(path)] = stamp(path)
            with path.open() as source:
                for line in source:
                    match = re.match(r"^\s*([a-zA-Z0-9_-]+):\s*(.*)$", line)
                    if not match:
                        continue
                    key, text = match.groups()
                    if key not in remote_keys | {"directory", "chroot", "include", "include-toplevel"}:
                        if not text.split("#", 1)[0].strip():
                            section = key
                        continue
                    values = shlex.split(text, comments=True)
                    if len(values) != 1:
                        raise ValueError("Opsi kontrol Unbound tidak valid: " + key)
                    value = values[0]
                    if key in ("include", "include-toplevel"):
                        pattern = value if value.startswith("/") else "/etc/unbound/" + value
                        # Directory stamps also detect new files matching an include glob.
                        prefix = re.split(r"[?*\[]", pattern, maxsplit=1)[0]
                        directory = Path(prefix).parent
                        if directory.is_dir():
                            watched[str(directory)] = stamp(directory)
                        for child in sorted(glob.glob(pattern)):
                            result = read(Path(child), "" if key == "include-toplevel" else section, (*stack, path))
                            if key == "include":
                                section = result
                    elif section == "remote-control" and key in remote_keys:
                        remote.append((key, value))
                    elif section == "server" and key in ("directory", "chroot"):
                        server.append((key, value))
            return section

        read(UNBOUND)
        if any(stamp(path) != value for path, value in watched.items()):
            raise ValueError("Konfigurasi berubah saat membaca opsi kontrol. Coba lagi.")
        body = "server:\n" + "".join(f"    {key}: {json.dumps(value, ensure_ascii=False)}\n" for key, value in server)
        body += "remote-control:\n" + "".join(f"    {key}: {json.dumps(value, ensure_ascii=False)}\n" for key, value in remote)
        atomic(CONTROL_CACHE, body.encode(), 0o600)
        atomic(CONTROL_META, json.dumps({"files": watched}).encode(), 0o600)
        return CONTROL_CACHE


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
    apply_config(unit, path, old, data)
    print("File tersimpan dan layanan sudah dimuat ulang.")


def checked(*args):
    result = subprocess.run(host_command(*args), capture_output=True, text=True, timeout=240)
    if result.returncode:
        raise ValueError((result.stderr + result.stdout).strip() or "Validasi konfigurasi gagal.")
    return result.stdout.strip()


def apply_config(unit, path, old, data):
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
        if unit == "unbound":
            # -o validates the whole candidate while reading its daemon username.
            daemon_user = checked("/usr/sbin/unbound-checkconf", "-o", "username", candidate)
            if daemon_user:
                checked("/usr/sbin/runuser", "-u", daemon_user, "--", "/usr/sbin/unbound-checkconf", candidate)
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
        command("systemctl", "reload" if unit == "unbound" else "reload-or-restart", unit)
        command("systemctl", "is-active", "--quiet", unit)
    except (OSError, subprocess.SubprocessError):
        atomic(path, old, *attrs)
        command("systemctl", "reload-or-restart", unit)
        raise


def root_hints_update():
    with tempfile.TemporaryDirectory() as stage:
        source = Path(stage, "named.root")
        command("/usr/bin/curl", "--fail", "--silent", "--show-error", "--noproxy", "*",
                "--proto", "=https", "--max-time", "30", "--max-filesize", "65536",
                "--output", source, "https://www.internic.net/domain/named.root")
        content = source.read_bytes()
        if len(content) > 65536:
            raise ValueError("Isi root hints tidak valid.")
        names = set(re.findall(rb"(?im)^\.\s+(?:\d+\s+)?(?:IN\s+)?NS\s+([A-M])\.ROOT-SERVERS\.NET\.", content.upper()))
        if len(names) != 13:
            raise ValueError("Root hints harus memuat 13 nama root server.")
    if ROOT_HINTS.is_symlink():
        raise ValueError("File root hints tidak boleh berupa symlink.")
    previous = ROOT_HINTS.read_bytes() if ROOT_HINTS.exists() else None
    atomic(ROOT_HINTS, content)
    try:
        if checked("/usr/sbin/unbound-checkconf", "-o", "root-hints", UNBOUND) == str(ROOT_HINTS):
            command("systemctl", "reload", "unbound")
    except (ValueError, OSError, subprocess.SubprocessError):
        if previous is None:
            ROOT_HINTS.unlink(missing_ok=True)
        else:
            atomic(ROOT_HINTS, previous)
        raise
    print(f"Root hints tersimpan di {ROOT_HINTS}.")


def dnssec_anchor():
    tool = Path("/usr/sbin/unbound-anchor")
    if not tool.is_file():
        raise ValueError("Pasang alat trust anchor dengan sudo apt install unbound-anchor.")
    if not ROOT_KEY.parent.is_dir() or ROOT_KEY.is_symlink():
        raise ValueError("Lokasi trust anchor harus berupa file biasa di /var/lib/unbound.")
    result = subprocess.run(["/usr/sbin/runuser", "-u", "unbound", "--", tool, "-v", "-a", ROOT_KEY],
                            capture_output=True, text=True, timeout=90)
    if result.returncode not in (0, 1) or not ROOT_KEY.is_file() or ROOT_KEY.stat().st_size == 0:
        raise ValueError((result.stdout + result.stderr).strip() or "Trust anchor gagal disiapkan.")
    print(f"Trust anchor tersedia di {ROOT_KEY}.")


def control_certificates():
    names = ["unbound_server.key", "unbound_server.pem", "unbound_control.key", "unbound_control.pem"]
    targets = [UNBOUND.parent / name for name in names]
    if any(path.exists() or path.is_symlink() for path in targets):
        if all(path.is_file() and not path.is_symlink() for path in targets):
            print("Sertifikat kontrol sudah tersedia di /etc/unbound.")
            return
        raise ValueError("File sertifikat kontrol lama tidak lengkap. Periksa file di /etc/unbound.")
    with tempfile.TemporaryDirectory() as stage:
        command("/usr/sbin/unbound-control-setup", "-d", stage)
        group = grp.getgrnam("unbound").gr_gid
        created = []
        try:
            for target in targets:
                atomic(target, Path(stage, target.name).read_bytes(), 0o640, (0, group))
                created.append(target)
        except OSError:
            for target in created:
                target.unlink()
            raise
    print("Sertifikat kontrol dibuat di /etc/unbound. Atur bagian remote-control untuk menggunakannya.")


def set_resolver_feature(op):
    path = UNBOUND.resolve(strict=True)
    old = path.read_bytes()
    body = old.decode()
    feature, action = op.rsplit("-", 1)
    marker = "# Unbound Web Manager " + feature
    body = re.sub(r"(?ms)^" + re.escape(marker) + r" begin\n.*?^" + re.escape(marker) + r" end\n?", "", body)
    if feature == "root-hints":
        directives = []
        if action == "enable":
            if not ROOT_HINTS.is_file() or ROOT_HINTS.is_symlink():
                raise ValueError("Unduh root hints terlebih dahulu.")
            directives = [f'root-hints: "{ROOT_HINTS}"']
        elif re.search(r'(?m)^\s*root-hints\s*:', body):
            raise ValueError("Root hints juga diatur manual. Hapus arahan root-hints lewat editor.")
    else:
        modules = checked("/usr/sbin/unbound-checkconf", "-o", "module-config", UNBOUND).split()
        if action == "enable" and "validator" not in modules:
            modules.insert(modules.index("iterator") if "iterator" in modules else 0, "validator")
        if action == "disable":
            modules = [module for module in modules if module != "validator"]
        directives = [f'module-config: "{" ".join(modules)}"']
        if action == "enable":
            if not ROOT_KEY.is_file() or not ROOT_KEY.stat().st_size:
                raise ValueError("Siapkan trust anchor terlebih dahulu.")
            # Ubuntu normally includes this packaged root anchor already.
            configs = [body] + [p.read_text() for p in UNBOUND.parent.glob("unbound.conf.d/*.conf")
                                if p.name != "90-unbound-web-blocklist.conf"]
            if not any(re.search(r'(?m)^\s*auto-trust-anchor-file:\s*"?' + re.escape(str(ROOT_KEY)) + r'(?:"|\s|$)', item) for item in configs):
                directives.append(f'auto-trust-anchor-file: "{ROOT_KEY}"')
    if directives:
        body = body.rstrip() + "\n\n" + marker + " begin\nserver:\n"
        body += "".join("    " + directive + "\n" for directive in directives) + marker + " end\n"
    apply_config("unbound", path, old, body.encode())
    print(json.dumps({"content": body}))


def flags():
    if not FILTER_CRON.exists():
        return ["0", "0"]
    match = re.match(r"# filters ([01]) ([01])\n", FILTER_CRON.read_text())
    if not match:
        raise ValueError("Format cron filter tidak dikenali.")
    return list(match.groups())


def sync(bits):
    command(*host_command("/usr/bin/php8.4", str(APP / "bin/blocklist-sync.php"), *bits))


def main(args):
    op, *params = args
    if op.startswith("firewall-"):
        # UFW also updates kernel network settings. Run this fixed helper through
        # PID 1, outside FPM's read-only mounts / ProtectKernelTunables sandbox.
        command(*host_command("/usr/bin/php8.4", str(APP / "bin/firewall.php"), op.removeprefix("firewall-"), *params))
    elif op == "service-status" and not params:
        print("unbound=" + active("unbound"))
        zabbix = next((unit for unit in AGENTS if active(unit) == "active"), None)
        print("zabbix=" + ("active" if zabbix else "inactive"))
        if UNBOUND.exists():
            version = subprocess.run(["unbound", "-V"], capture_output=True, text=True)
            match = re.search(r"Version\s+([\d.]+)", version.stdout)
            if match:
                print("unbound_version=" + match[1])
    elif op in ("stats", "stats-live") and not params:
        subprocess.run(["unbound-control", "-c", str(control_config()), "stats_noreset"],
                       check=True, timeout=0.6 if op == "stats-live" else 8)
    elif op == "config-read" and len(params) == 1:
        editor(op, *params)
    elif op == "filters-state" and not params:
        block, white = flags()
        print(json.dumps(dict(block=block == "1", white=white == "1")))
    elif op == "cron-view" and not params:
        print("Monitoring: unbound-web-monitor.service / interval 1 detik / " + active("unbound-web-monitor"))
        for path in [WORKER_CRON, FILTER_CRON]:
            if path.is_file():
                print(path.read_text(), end="")
    else:
        with lock():
            if op == "config-save" and len(params) == 2:
                editor(op, *params)
            elif op == "unbound-check" and not params:
                print(checked("/usr/sbin/unbound-checkconf", UNBOUND))
            elif op == "root-hints-update" and not params:
                root_hints_update()
            elif op == "dnssec-anchor" and not params:
                dnssec_anchor()
            elif op == "control-certificates" and not params:
                control_certificates()
            elif op in ("root-hints-enable", "root-hints-disable", "dnssec-enable", "dnssec-disable") and not params:
                set_resolver_feature(op)
            elif op in ("unbound-restart", "unbound-reload") and not params:
                command(*host_command("/usr/sbin/unbound-checkconf", str(UNBOUND)))
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
                previous_cron = FILTER_CRON.read_bytes() if FILTER_CRON.exists() else None
                try:
                    if "1" in params:
                        body = f"# filters {' '.join(params)}\n15 3 * * * root {HELPER} update-blocklists\n"
                        atomic(FILTER_CRON, body.encode())
                    else:
                        FILTER_CRON.unlink(missing_ok=True)
                    sync(params)
                except (OSError, subprocess.SubprocessError):
                    if previous_cron is None:
                        FILTER_CRON.unlink(missing_ok=True)
                    else:
                        atomic(FILTER_CRON, previous_cron)
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
