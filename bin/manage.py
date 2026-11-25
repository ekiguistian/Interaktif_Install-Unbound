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
import pwd
import re
import selectors
import shlex
import shutil
import subprocess
import sys
import tempfile
import time

APP = Path("/opt/www/unbound-web")
UNBOUND = Path("/etc/unbound/unbound.conf")
FILTER_CRON = Path("/etc/cron.d/unbound-web-filters")
WORKER_CRON = Path("/etc/cron.d/unbound-web")
HELPER = "/usr/local/sbin/unbound-web-manage"
CONTROL_CACHE = Path("/var/lib/unbound-web-filters/control-client.conf")
CONTROL_META = CONTROL_CACHE.with_suffix(".json")
ROOT_HINTS = Path("/etc/unbound/unbound-web-root.hints")
ROOT_KEY = Path("/var/lib/unbound/root.key")
FILTER_PATHS = ("/etc/unbound/blocklist/blocklist.conf", "/etc/unbound/blocklist/whitelist.conf")
LEGACY_FILTER = Path("/etc/unbound/unbound.conf.d/90-unbound-web-blocklist.conf")
CONTROL_FILES = ("unbound_server.key", "unbound_server.pem", "unbound_control.key", "unbound_control.pem")
LOG_OPTIONS = ("log-queries", "log-replies")
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
            if isinstance(content, Path):
                with content.open("rb") as source:
                    shutil.copyfileobj(source, dest)
            else:
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
            if (CONTROL_CACHE.is_file() and metadata.get("files") and metadata.get("version") == 2
                    and all(stamp(path) == value for path, value in metadata["files"].items())):
                return CONTROL_CACHE
        except (OSError, ValueError, TypeError, KeyError):
            pass
        watched, server, remote = {}, [], []
        anchor_keys = {"auto-trust-anchor-file", "trust-anchor-file", "trust-anchor", "trusted-keys-file"}
        feature_keys = {"root-hints", "module-config", "val-permissive-mode", *LOG_OPTIONS} | anchor_keys
        features = {"anchors": []}
        includes = []
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
                    if key not in remote_keys | feature_keys | {"directory", "chroot", "include", "include-toplevel"}:
                        if not text.split("#", 1)[0].strip():
                            section = key
                        continue
                    values = shlex.split(text, comments=True)
                    if key in feature_keys:
                        if section == "server" and values:
                            if key in anchor_keys and any(values):
                                features["anchors"].append(key)
                            elif key not in anchor_keys:
                                features[key] = " ".join(values)
                        continue
                    if len(values) != 1:
                        raise ValueError("Opsi kontrol Unbound tidak valid: " + key)
                    value = values[0]
                    if key in ("include", "include-toplevel"):
                        pattern = value if value.startswith("/") else "/etc/unbound/" + value
                        # Directory stamps also detect new files matching an include glob.
                        prefix = re.split(r"[?*\[]", pattern, maxsplit=1)[0]
                        directory = Path(prefix) if prefix.endswith('/') else Path(prefix).parent
                        if directory.is_dir():
                            watched[str(directory)] = stamp(directory)
                        for child in sorted(glob.glob(pattern)):
                            includes.append((str(path), str(Path(child).resolve())))
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
        atomic(CONTROL_META, json.dumps({"version": 2, "files": watched, "features": features, "includes": includes, "remote": dict(remote),
                                        "directory": dict(server).get("directory", str(UNBOUND.parent))}).encode(), 0o600)
        return CONTROL_CACHE


@contextlib.contextmanager
def lock():
    with open("/run/unbound-web-control.lock", "a") as handle:
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield


def editor(operation, name, revision=None):
    unit, path = service_config(name)
    path = trusted_config_path(path, Path("/etc/unbound" if name == "unbound" else "/etc/zabbix"))
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
    validate_web_config(name, data.decode())
    apply_config(unit, path, old, data, after=record_editor_config if unit == "unbound" else None)
    print("File tersimpan dan layanan sudah dimuat ulang.")


def trusted_config_path(path, base):
    """The web editor accepts only root-owned, non-writable configuration paths."""
    if not path.is_absolute() or not path.is_relative_to(base) or ".." in path.parts:
        raise ValueError(f"Lokasi konfigurasi harus di dalam {base}.")
    resolved = path.resolve(strict=True)
    if not resolved.is_relative_to(base):
        raise ValueError(f"Target konfigurasi harus di dalam {base}.")
    for item in set((path, resolved, *path.parents, *resolved.parents)):
        metadata = item.stat()
        if metadata.st_uid != 0 or metadata.st_mode & 0o022:
            raise ValueError("Konfigurasi web hanya memakai file dan direktori milik root.")
    return resolved


def validate_web_config(service, content):
    base = Path("/etc/unbound" if service == "unbound" else "/etc/zabbix")
    visited = set()
    remaining = 8 * 1024 * 1024

    def inspect_include(pattern):
        option = Path(pattern)
        if not option.is_absolute() or not option.is_relative_to(base) or ".." in option.parts:
            raise ValueError(f"Include harus memakai path absolut di dalam {base}.")
        # Check the directory before expanding, including a wildcard with no matches.
        wildcard_prefix = re.split(r"[?*\[]", str(option), maxsplit=1)[0]
        directory = Path(wildcard_prefix) if wildcard_prefix.endswith("/") else Path(wildcard_prefix).parent
        trusted_config_path(directory, base)
        for name in sorted(glob.glob(pattern)):
            source = trusted_config_path(Path(name), base)
            if source in visited:
                continue
            if not source.is_file() or len(visited) >= 128:
                raise ValueError("Include konfigurasi tidak valid atau melebihi batas editor web.")
            visited.add(source)
            if source in map(Path, FILTER_PATHS):
                # These generated zone files are updated by the panel and can be hundreds of MB.
                continue
            with source.open("rb") as stream:
                data = stream.read(remaining + 1)
            inspect(data.decode())

    def inspect(body):
        nonlocal remaining
        remaining -= len(body.encode())
        if remaining < 0:
            raise ValueError("Total ukuran include melebihi batas editor web.")
        if service == "unbound":
            lexer = shlex.shlex(body, posix=True, punctuation_chars=":")
            lexer.whitespace_split = True
            tokens = list(lexer)
            for index, key in enumerate(tokens[:-1]):
                if tokens[index + 1] != ":":
                    continue
                key = key.lower()
                value = tokens[index + 2] if index + 2 < len(tokens) else ""
                if key in ("include", "include-toplevel"):
                    inspect_include(value)
                elif key == "username" and value != "unbound":
                    raise ValueError("Editor web hanya mendukung akun layanan unbound.")
                elif key in ("python-script", "dynlib-file") or (key == "module-config" and {"python", "dynlib"} & set(value.split())):
                    raise ValueError("Modul eksekusi eksternal hanya dapat diatur lewat terminal server.")
                elif key == "logfile" and value and (not Path(value).is_relative_to(Path("/var/log/unbound")) or ".." in Path(value).parts):
                    raise ValueError("Gunakan syslog atau file log di /var/log/unbound.")
        else:
            for line in body.splitlines():
                if line.lstrip().startswith("#") or "=" not in line:
                    continue
                key, value = (item.strip() for item in line.split("=", 1))
                if key == "Include":
                    inspect_include(value)
                elif key in {"UserParameter", "UserParameterDir", "AllowKey", "EnableRemoteCommands", "UnsafeUserParameters", "LoadModule", "LoadModulePath"} or key.endswith(".System.Path"):
                    raise ValueError("Opsi eksekusi eksternal Zabbix hanya dapat diatur lewat terminal server.")
                elif (key == "AllowRoot" and value != "0") or (key == "User" and value != "zabbix"):
                    raise ValueError("Editor web hanya mendukung akun layanan zabbix.")

    inspect(content)


def checked(*args):
    result = subprocess.run(host_command(*args), capture_output=True, text=True, timeout=240)
    if result.returncode:
        raise ValueError((result.stderr + result.stdout).strip() or "Validasi konfigurasi gagal.")
    return result.stdout.strip()


def apply_config(unit, path, old, data, reload=True, after=None, before_reload=None):
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
            try:
                daemon_uid = pwd.getpwnam(daemon_user).pw_uid
            except KeyError:
                daemon_uid = 0
            if daemon_uid == 0:
                raise ValueError("Konfigurasi membutuhkan akun layanan Unbound non-root.")
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
    if not reload:
        return
    reload_attempted = False
    try:
        if before_reload:
            before_reload()
        # Agent units without ExecReload require restart for full config changes.
        reload_attempted = True
        command("systemctl", "reload" if unit == "unbound" else "reload-or-restart", unit)
        command("systemctl", "is-active", "--quiet", unit)
        if after:
            after()
    except (ValueError, OSError, subprocess.SubprocessError):
        atomic(path, old, *attrs)
        if reload_attempted:
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
    targets = [UNBOUND.parent / name for name in CONTROL_FILES]
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


def live_option(cache, name):
    result = subprocess.run(["/usr/sbin/unbound-control", "-c", cache, "get_option", name],
                            capture_output=True, text=True, check=True, timeout=1)
    value = result.stdout.strip()
    if value.startswith("error"):
        raise ValueError(value)
    return value


def resolver_state():
    state = {"service": active("unbound"), "configured": False, "live": False}
    if not UNBOUND.is_file():
        return state
    cache = control_config()
    metadata = json.loads(CONTROL_META.read_text())
    features = metadata["features"]
    remote = metadata["remote"]
    hints = features.get("root-hints", "")
    modules = features.get("module-config", "validator iterator")
    if state["service"] == "active":
        try:
            live_modules = live_option(cache, "module-config")
            live_hints = live_option(cache, "root-hints")
            modules, hints = live_modules, live_hints
            state["live"] = True
        except (OSError, ValueError, subprocess.SubprocessError):
            pass

    def ready(path):
        return path.is_file() and path.stat().st_size > 0

    keys = ("server-key-file", "server-cert-file", "control-key-file", "control-cert-file")
    directory = Path(metadata["directory"] or UNBOUND.parent)
    paths = [directory / remote[key] if remote.get(key) else UNBOUND.parent / filename
             for key, filename in zip(keys, CONTROL_FILES)]
    default_paths = [UNBOUND.parent / name for name in CONTROL_FILES]
    unix_control = remote.get("control-interface", "").startswith("/")
    state.update(configured=True, root_hints_active=bool(hints), root_hints_ready=ready(ROOT_HINTS),
                 dnssec_active="validator" in modules.split() and bool(features["anchors"]),
                 dnssec_permissive=features.get("val-permissive-mode") == "yes",
                 anchor_ready=ready(ROOT_KEY), anchor_tool=Path("/usr/sbin/unbound-anchor").is_file(),
                 certificates_ready=all(ready(path) for path in paths),
                 certificates_can_prepare=paths == default_paths and not any(path.exists() or path.is_symlink() for path in paths),
                 tls_enabled=remote.get("control-enable") == "yes" and remote.get("control-use-cert", "yes") == "yes" and not unix_control,
                 unix_control=unix_control)
    return state


def zabbix_state():
    for unit, config in AGENTS.items():
        load = subprocess.check_output(["systemctl", "show", "-p", "LoadState", "--value", unit], text=True).strip()
        if load not in ("not-found", "") or config.is_file():
            return {"installed": True}
    release = {}
    for line in Path("/etc/os-release").read_text().splitlines():
        key, sep, value = line.partition("=")
        if sep and key in ("ID", "VERSION_ID"):
            release[key] = value.strip('"')
    return {"installed": False, "jammy": release.get("ID") == "ubuntu" and release.get("VERSION_ID") == "22.04",
            "architecture": subprocess.check_output(["dpkg", "--print-architecture"], text=True).strip()}


SECTION = re.compile(r"^\s*([a-z][a-z0-9-]*):\s*(?:#.*)?$", re.I)
DIRECTIVE = re.compile(r"^(?P<indent>[ \t]*)(?P<comment>#\s*)?(?P<key>[a-z][a-z0-9-]*):[ \t]*(?P<value>.*)$", re.I)
LEGACY = re.compile(r"(?ms)^# Unbound Web Manager (root-hints|dnssec) begin\r?\n(.*?)^# Unbound Web Manager \1 end[^\n]*\n?")


def server_range(lines):
    buckets = [(index, match[1]) for index, line in enumerate(lines)
               if (match := SECTION.fullmatch(line.rstrip("\r\n")))]
    for offset, (start, key) in enumerate(buckets):
        if key == "server":
            return start + 1, buckets[offset + 1][0] if offset + 1 < len(buckets) else len(lines)
    return None, buckets[0][0] if buckets else len(lines)


def directive_args(value):
    try:
        return shlex.split(value, comments=True)
    except ValueError:
        return []


def include_target(line):
    match = DIRECTIVE.match(line.rstrip("\r\n"))
    if not match or match["comment"] or match["key"] not in ("include", "include-toplevel"):
        return ""
    values = directive_args(match["value"])
    if len(values) != 1:
        return ""
    return str(Path("/etc/unbound") / values[0])


def edit_server(body, settings=None, include=None, migrate_wrapper=False):
    """Edit the existing server section and fold in old panel-generated blocks."""
    options = {}
    newline = "\r\n" if "\r\n" in body else "\n"

    def migrate(match):
        for line in match[2].splitlines():
            option = DIRECTIVE.match(line)
            if option and not option["comment"] and option["key"] in {
                "root-hints", "module-config", "val-permissive-mode", "auto-trust-anchor-file"
            }:
                options[option["key"]] = option["value"]
        return ""

    body = LEGACY.sub(migrate, body)
    options.update(settings or {})
    lines = body.splitlines(keepends=True)
    if migrate_wrapper:
        lines = [line for line in lines if include_target(line) != str(LEGACY_FILTER)]
    start, end = server_range(lines)
    if start is None:
        # Package installations can have only include-toplevel in the main file.
        lines[end:end] = ["server:" + newline, newline]
        start, end = end + 1, end + 2
    indent = next((d["indent"] for line in lines[start:end]
                   if (d := DIRECTIVE.match(line)) and d["indent"]), "    ")

    for key, value in options.items():
        start, end = server_range(lines)
        matches = []
        for index in range(start, end):
            part = DIRECTIVE.match(lines[index])
            if part and part["key"] == key:
                if key == "auto-trust-anchor-file":
                    # Preserve independent trust anchors for privately signed zones.
                    args = directive_args(part["value"])
                    if args and args[0] not in (str(ROOT_KEY), "/etc/unbound/unbound-web-root.key"):
                        continue
                matches.append((index, part))
        if matches:
            index, part = next(((i, d) for i, d in matches if not d["comment"]), matches[0])
            comment = re.search(r"[ \t]+#.*$", part["value"]) if key in LOG_OPTIONS else None
            lines[index] = f'{part["indent"] or indent}{key}: {value}{comment[0] if comment else ""}{newline}'
            for duplicate, item in reversed(matches):
                if duplicate != index and not item["comment"]:
                    del lines[duplicate]
            continue
        marker = next((i + 1 for i in range(start, end) if re.match(
            r"^\s*#\s*ROOT HINTS.*TRUST ANCHOR", lines[i], re.I)), None)
        peers = [i + 1 for i in range(start, end)
                 if re.match(r"^\s*(?:#\s*)?(?:root-hints|auto-trust-anchor-file):", lines[i])]
        if key in ("root-hints", "auto-trust-anchor-file") and (peers or marker is not None):
            index = peers[-1] if peers else marker
        elif key in LOG_OPTIONS:
            logging = next((i + 1 for i in range(start, end) if re.match(r"^\s*(?:#\s*)+LOGGING\b", lines[i], re.I)), None)
            log_peers = [i + 1 for i in range(start, end) if re.match(r"^\s*(?:#\s*)?log-(?:queries|replies):", lines[i])]
            if logging is not None:
                index = logging
                while index < end and re.match(r"^\s*(?:#\s*)?(?:log-[a-z-]+|use-syslog|logfile):", lines[index]):
                    index += 1
            elif log_peers:
                index = log_peers[-1]
            else:
                index = end
                while index > start and not lines[index - 1].strip():
                    index -= 1
                if index and not lines[index - 1].endswith(("\n", "\r")):
                    lines[index - 1] += newline
                lines.insert(index, f"{indent}# LOGGING{newline}")
                index += 1
        else:
            index = end
            while index > start and not lines[index - 1].strip():
                index -= 1
        if index and not lines[index - 1].endswith(("\n", "\r")):
            lines[index - 1] += newline
        lines.insert(index, f"{indent}{key}: {value}{newline}")

    if include is not None:
        target, enabled = include
        # Move an existing explicit include into server and remove duplicates.
        lines = [line for line in lines if include_target(line) != target]
        if enabled:
            start, end = server_range(lines)
            index = end
            while index > start and not lines[index - 1].strip():
                index -= 1
            if not lines[index - 1].endswith(("\n", "\r")):
                lines[index - 1] += newline
            lines.insert(index, f'{indent}include: "{target}"{newline}')
    return "".join(lines)


def set_resolver_feature(op):
    path = UNBOUND.resolve(strict=True)
    old = path.read_bytes()
    feature, action = op.rsplit("-", 1)
    if feature == "root-hints":
        if action == "enable" and (not ROOT_HINTS.is_file() or ROOT_HINTS.is_symlink()):
            raise ValueError("Unduh root hints terlebih dahulu.")
        directives = {"root-hints": json.dumps(str(ROOT_HINTS) if action == "enable" else "")}
    else:
        modules = checked("/usr/sbin/unbound-checkconf", "-o", "module-config", UNBOUND).split()
        if action == "enable" and "validator" not in modules:
            modules.insert(modules.index("iterator") if "iterator" in modules else 0, "validator")
        if action == "disable":
            modules = [module for module in modules if module != "validator"]
        directives = {"module-config": json.dumps(" ".join(modules))}
        if action == "enable":
            directives["val-permissive-mode"] = "no"
            control_config()
            metadata = json.loads(CONTROL_META.read_text())
            included_anchor = any(
                re.search(r'(?m)^\s*auto-trust-anchor-file:\s*"?'
                          + re.escape(str(ROOT_KEY)) + r'(?:"|\s|$)', Path(file).read_text())
                for file in metadata["files"] if file != str(path) and Path(file).is_file()
            )
            if not included_anchor:
                if not ROOT_KEY.is_file() or not ROOT_KEY.stat().st_size:
                    raise ValueError("Siapkan trust anchor terlebih dahulu.")
                directives["auto-trust-anchor-file"] = json.dumps(str(ROOT_KEY))
    body = edit_server(old.decode(), directives)
    apply_config("unbound", path, old, body.encode(), after=record_config)
    print("Konfigurasi tersimpan dan Unbound sudah dimuat ulang.")


def flags():
    if not FILTER_CRON.exists():
        return ["0", "0"]
    match = re.match(r"# filters ([01]) ([01])\n", FILTER_CRON.read_text())
    if not match:
        raise ValueError("Format cron filter tidak dikenali.")
    return list(match.groups())


def sync(bits):
    command(*host_command("/usr/bin/php8.4", str(APP / "bin/blocklist-sync.php"), *bits))


def filter_state():
    block, white = flags()
    control_config()
    metadata = json.loads(CONTROL_META.read_text())
    paths = {target for _, target in metadata["includes"]}
    return {"block": block == "1", "white": white == "1",
            "block_included": FILTER_PATHS[0] in paths, "white_included": FILTER_PATHS[1] in paths}


def record_config():
    state = filter_state()
    metadata = json.loads(CONTROL_META.read_text())
    content = UNBOUND.read_bytes().decode()
    payload = json.dumps({"content": content, "filters": state, "resolver": metadata["features"]}, ensure_ascii=False)
    result = subprocess.run(["/usr/bin/php8.4", str(APP / "bin/record-config.php")],
                            input=payload, capture_output=True, text=True, timeout=15)
    if result.returncode != 0:
        raise ValueError("Gagal menyimpan snapshot konfigurasi ke database.")


def record_editor_config():
    state = filter_state()
    before = flags()
    after = [bit if state[key] else "0" for bit, key in zip(before, ("block_included", "white_included"))]
    if after == before:
        record_config()
        return
    previous = FILTER_CRON.read_bytes()
    try:
        schedule(after)
        record_config()
    except (ValueError, OSError, subprocess.SubprocessError):
        atomic(FILTER_CRON, previous)
        raise


def schedule(bits):
    if "1" in bits:
        body = f"# filters {' '.join(bits)}\n15 3 * * * root {HELPER} update-blocklists\n"
        atomic(FILTER_CRON, body.encode())
    else:
        FILTER_CRON.unlink(missing_ok=True)


@contextlib.contextmanager
def filter_transaction():
    paths = [UNBOUND.resolve(strict=True), FILTER_CRON, LEGACY_FILTER, *map(Path, FILTER_PATHS)]
    with tempfile.TemporaryDirectory() as folder:
        previous = []
        for index, path in enumerate(paths):
            if path.is_symlink():
                raise ValueError(f"File filter tidak boleh berupa symlink: {path}")
            stat = path.stat() if path.is_file() else None
            backup = Path(folder, str(index))
            if stat:
                shutil.copyfile(path, backup)
            previous.append((path, stat, backup))
        try:
            yield
        except (ValueError, OSError, subprocess.SubprocessError):
            for path, stat, backup in previous:
                if stat:
                    if not path.is_file() or not files_equal(path, backup):
                        atomic(path, backup, stat.st_mode & 0o777, (stat.st_uid, stat.st_gid))
                elif path.is_file():
                    path.unlink()
            if active("unbound") == "active":
                command("systemctl", "reload", "unbound")
            raise


def files_equal(first, second):
    if first.stat().st_size != second.stat().st_size:
        return False
    with first.open("rb") as left, second.open("rb") as right:
        for chunk in iter(lambda: left.read(65536), b""):
            if chunk != right.read(65536):
                return False
    return True


def toggle_filter(index, enabled):
    current = filter_state()
    bits = flags()
    bits[index] = "1" if enabled else "0"
    target = FILTER_PATHS[index]
    path = UNBOUND.resolve(strict=True)
    old = path.read_bytes()
    direct = any(include_target(line) == target for line in old.decode().splitlines())
    if not enabled and current[("block_included", "white_included")[index]] and not direct:
        raise ValueError("Include ini berasal dari file lain atau wildcard. Ubah include tersebut melalui konfigurasi asal.")
    with filter_transaction():
        legacy = LEGACY_FILTER.is_file()
        if legacy:
            if not LEGACY_FILTER.read_bytes().startswith(b"# Dikelola Unbound Web Manager."):
                raise ValueError("File include lama bukan buatan panel. Periksa isinya sebelum migrasi.")
            LEGACY_FILTER.unlink()
        body = edit_server(old.decode(), include=(target, enabled and (direct or not current[
            ("block_included", "white_included")[index]])), migrate_wrapper=legacy)
        if legacy and bits[1 - index] == "1" and not current[("block_included", "white_included")[1 - index]]:
            body = edit_server(body, include=(FILTER_PATHS[1 - index], True))
        # A late validation also detects wildcard includes that cannot be removed here.
        apply_config("unbound", path, old, body.encode(), reload=False)
        state = filter_state()
        if state[("block_included", "white_included")[index]] != enabled:
            raise ValueError("Include dari file lain masih memuat filter ini.")
        schedule(bits)
        sync(bits)
        record_config()
    print("Konfigurasi filter, jadwal, dan database sudah diperbarui.")


def logging_state():
    cache = control_config()
    features = json.loads(CONTROL_META.read_text())["features"]
    result = {option: features.get(option) == "yes" for option in LOG_OPTIONS}
    running = active("unbound") == "active"
    if running:
        for option in LOG_OPTIONS:
            try:
                value = live_option(cache, option)
                if value in ("yes", "no"):
                    result[option] = value == "yes"
            except (OSError, ValueError, subprocess.SubprocessError):
                pass
    return {"running": running, **result}


def set_logging(option, value):
    if active("unbound") != "active":
        raise ValueError("Aktifkan Unbound sebelum mengubah logging.")
    path = UNBOUND.resolve(strict=True)
    old = path.read_bytes()
    body = edit_server(old.decode(), {option: value})

    def verify_effective_config():
        control_config()
        features = json.loads(CONTROL_META.read_text())["features"]
        if features.get(option) != value:
            raise ValueError(f"Opsi {option} ditimpa oleh konfigurasi lain. Periksa urutan include atau blok server.")

    apply_config("unbound", path, old, body.encode(), after=record_config, before_reload=verify_effective_config)
    print(json.dumps({"option": option, "enabled": value == "yes"}))


def live_unbound_log():
    # A dedicated reader keeps the log feed from consuming all available web workers.
    with open("/run/unbound-web-live-log.lock", "a") as handle:
        deadline = time.monotonic() + 2
        while True:
            try:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError as error:
                if time.monotonic() >= deadline:
                    raise ValueError("Live Log sedang dibuka di sesi lain. Hentikan sesi tersebut atau coba lagi.") from error
                time.sleep(0.1)
        process = subprocess.Popen(["/usr/bin/journalctl", "--unit=unbound.service", "--follow", "--lines=40",
                                    "--output=short-iso", "--no-pager"], stdin=subprocess.DEVNULL,
                                   stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        try:
            with selectors.DefaultSelector() as watch:
                watch.register(sys.stdin.fileno(), selectors.EVENT_READ)
                watch.register(process.stdout, selectors.EVENT_READ)
                while True:
                    ready = watch.select()
                    # PHP closes stdin on browser disconnect, including when the journal is idle.
                    if any(key.fd == sys.stdin.fileno() for key, _ in ready):
                        return
                    chunk = os.read(process.stdout.fileno(), 4096)
                    if not chunk:
                        if process.wait():
                            raise ValueError("Journal Unbound tidak dapat dibaca.")
                        return
                    sys.stdout.buffer.write(chunk)
                    sys.stdout.buffer.flush()
        except BrokenPipeError:
            pass
        finally:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=2)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()
            process.stdout.close()


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
    elif op == "unbound-live-log" and not params:
        live_unbound_log()
    elif op == "unbound-logging-state" and not params:
        print(json.dumps(logging_state()))
    elif op in ("stats", "stats-live") and not params:
        subprocess.run(["unbound-control", "-c", str(control_config()), "stats_noreset"],
                       check=True, timeout=0.6 if op == "stats-live" else 8)
    elif op == "config-read" and len(params) == 1:
        editor(op, *params)
    elif op == "resolver-state" and not params:
        print(json.dumps(resolver_state()))
    elif op == "zabbix-state" and not params:
        print(json.dumps(zabbix_state()))
    elif op in ("update-status", "update-check") and not params:
        command(*host_command("/usr/bin/python3", "-I", str(APP / "bin/update.py"), op.split("-", 1)[1]))
    elif op == "update-queue" and len(params) == 1 and re.fullmatch(r"[0-9a-f]{40}", params[0]):
        command(*host_command("/usr/bin/python3", "-I", str(APP / "bin/update.py"), "queue", params[0]))
    elif op == "filters-state" and not params:
        print(json.dumps(filter_state()))
    elif op == "cron-view" and not params:
        print("Monitoring: unbound-web-monitor.service / interval 1 detik / " + active("unbound-web-monitor"))
        for path in [WORKER_CRON, FILTER_CRON]:
            if path.is_file():
                print(path.read_text(), end="")
    else:
        with lock():
            if op == "config-save" and len(params) == 2:
                editor(op, *params)
            elif op == "unbound-logging-set" and len(params) == 2 and params[0] in LOG_OPTIONS and params[1] in ("yes", "no"):
                set_logging(*params)
            elif op == "unbound-check" and not params:
                print(checked("/usr/sbin/unbound-checkconf", UNBOUND))
            elif op == "unbound-stop" and not params:
                command("systemctl", "stop", "unbound")
                print("Unbound sudah dihentikan.")
            elif op == "root-hints-update" and not params:
                root_hints_update()
            elif op == "dnssec-anchor" and not params:
                dnssec_anchor()
            elif op == "control-certificates" and not params:
                control_certificates()
            elif op in ("root-hints-enable", "root-hints-disable", "dnssec-enable", "dnssec-disable") and not params:
                set_resolver_feature(op)
            elif op in ("unbound-start", "unbound-restart", "unbound-reload") and not params:
                command(*host_command("/usr/sbin/unbound-checkconf", str(UNBOUND)))
                command("systemctl", op.split("-")[1], "unbound")
            elif op == "update-blocklists" and not params:
                bits = flags()
                if "1" not in bits:
                    raise ValueError("Aktifkan filter terlebih dahulu.")
                sync(bits)
            elif op == "filter-toggle" and len(params) == 2 and params[0] in ("block", "white") and params[1] in ("0", "1"):
                toggle_filter(0 if params[0] == "block" else 1, params[1] == "1")
            elif op == "filters-save" and len(params) == 2 and set(params) <= {"0", "1"}:
                with filter_transaction():
                    schedule(params)
                    sync(params)
                    record_config()
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

# System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
