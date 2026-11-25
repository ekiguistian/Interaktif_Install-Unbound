#!/usr/bin/python3
"""Check and install the approved commit from the project's fixed main branch."""
import fcntl
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time

REPOSITORY = "https://github.com/ekiguistian/Unbound_DNS_Web-Manager.git"
APP = Path("/opt/www/unbound-web")
WORK = APP / ".updates"
STATUS = Path("/var/lib/unbound-web-filters/update.json")
INSTALLED = Path("/etc/unbound-web/installed-commit")
INSTALLED_RELEASE = INSTALLED.with_name("installed-release.json")
VERSION_BASE = "dc9806077ddd31ce0924b3aa0321aa6dc43cf498"
REF = re.compile(r"[0-9a-f]{40}")
GIT = ["/usr/bin/git", "-c", "credential.helper=", "-c", "protocol.allow=never", "-c", "protocol.https.allow=always"]
GIT_ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "HOME": "/root", "GIT_CONFIG_NOSYSTEM": "1",
           "GIT_CONFIG_GLOBAL": "/dev/null", "GIT_TERMINAL_PROMPT": "0"}


def git(*args, timeout=25):
    result = subprocess.run([*GIT, *args], env=GIT_ENV, cwd="/", capture_output=True, text=True, timeout=timeout)
    if result.returncode:
        raise ValueError(result.stderr.strip() or "Git gagal memproses pembaruan.")
    return result.stdout.strip()


def read_status():
    state = json.loads(STATUS.read_text()) if STATUS.is_file() else {"phase": "idle"}
    installed = INSTALLED.read_text().strip() if INSTALLED.is_file() else ""
    state["installed"] = installed if REF.fullmatch(installed) else ""
    state["installed_version"] = ""
    if INSTALLED_RELEASE.is_file():
        release = json.loads(INSTALLED_RELEASE.read_text())
        if release.get("commit") == installed:
            state["installed_version"] = release.get("version", "")
    return state


def publish(phase, commit="", message="", version=""):
    state = {"phase": phase, "commit": commit, "message": message, "version": version, "updated_at": int(time.time())}
    with tempfile.NamedTemporaryFile(dir=STATUS.parent, prefix=".update-", mode="w", delete=False) as handle:
        path = Path(handle.name)
        json.dump(state, handle)
    try:
        os.replace(path, STATUS)
    finally:
        path.unlink(missing_ok=True)


def version_for(source, commit):
    if not REF.fullmatch(commit):
        raise ValueError("Commit tidak valid.")
    lineage = git("-c", f"safe.directory={source}", "-C", str(source), "rev-list", "--first-parent", commit).splitlines()
    if VERSION_BASE not in lineage:
        raise ValueError("Riwayat jalur utama tidak lengkap atau bukan dari rilis ini.")
    return f"1.0.{29 + lineage.index(VERSION_BASE)}"


def update_directory():
    WORK.mkdir(mode=0o700, exist_ok=True)
    if WORK.is_symlink() or WORK.stat().st_uid != 0 or WORK.stat().st_mode & 0o077:
        raise ValueError("Direktori checkout pembaruan tidak aman.")
    return WORK


def remote_version(commit):
    history = update_directory() / "history.git"
    if history.is_symlink():
        raise ValueError("Direktori riwayat pembaruan tidak aman.")
    if not history.exists():
        git("init", "--quiet", "--bare", "--initial-branch=main", str(history))
    git("-C", str(history), "fetch", "--no-tags", "--", REPOSITORY, "refs/heads/main", timeout=16)
    if git("-C", str(history), "rev-parse", "FETCH_HEAD") != commit:
        raise ValueError("Branch main berubah. Jalankan Check for update lagi.")
    return version_for(history, commit)


def check():
    result = git("ls-remote", "--exit-code", REPOSITORY, "refs/heads/main", timeout=12).split()
    if len(result) != 2 or not REF.fullmatch(result[0]) or result[1] != "refs/heads/main":
        raise ValueError("Commit main tidak dapat diverifikasi.")
    commit = result[0]
    state = read_status()
    version = remote_version(commit)
    if commit == state["installed"]:
        publish("current", commit, "Panel sudah memakai commit terbaru.", version)
    else:
        publish("available", commit, "Pembaruan tersedia. Periksa commit sebelum memasang.", version)


def install(commit, version):
    if not REF.fullmatch(commit):
        raise ValueError("Commit pembaruan tidak valid.")
    source = Path(tempfile.mkdtemp(prefix="main-", dir=update_directory()))
    try:
        publish("cloning", commit, "Mengambil branch main dari GitHub.", version)
        git("clone", "--no-tags", "--single-branch", "--branch=main", "--", REPOSITORY, str(source), timeout=120)
        if git("-C", str(source), "rev-parse", "HEAD") != commit or version_for(source, commit) != version:
            raise ValueError("Branch main berubah. Jalankan Check for update lagi.")
        installer = source / "install.sh"
        if installer.is_symlink() or not installer.is_file():
            raise ValueError("Installer tidak ditemukan di commit yang dipilih.")
        publish("installing", commit, "Menjalankan install.sh. Panel dapat terputus sebentar.", version)
        environment = {"PATH": GIT_ENV["PATH"], "HOME": "/root", "UNBOUND_WEB_QUIET_CREDENTIALS": "1"}
        subprocess.run(["/usr/bin/bash", installer], cwd=source, env=environment, check=True, timeout=1800)
        installed = read_status()
        if installed["installed"] != commit or installed["installed_version"] != version:
            raise ValueError("Installer selesai, tetapi commit yang terpasang tidak dapat diverifikasi.")
    except BaseException:
        shutil.rmtree(source)
        raise
    publish("done", commit, "Pembaruan berhasil. Muat ulang halaman untuk memakai versi baru.", version)
    for previous in WORK.glob("main-*"):
        if previous != source and previous.is_dir() and not previous.is_symlink() and previous.stat().st_uid == 0:
            shutil.rmtree(previous)


def main(action, expected=""):
    if action == "status":
        state = read_status()
        if state["phase"] in ("queued", "cloning", "installing") and time.time() - state["updated_at"] > 2100:
            state.update(phase="failed", message="Proses pembaruan terputus. Periksa journal unbound-web-update.")
        print(json.dumps(state))
        return
    with open("/run/unbound-web-update.lock", "a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX if action == "install" else fcntl.LOCK_EX | fcntl.LOCK_NB)
        state = read_status()
        if action == "check":
            if state["phase"] == "queued" and time.time() - state["updated_at"] < 60:
                raise ValueError("Pembaruan sedang dimulai.")
            check()
        elif action == "queue":
            if state["phase"] != "available" or not REF.fullmatch(expected) or state["commit"] != expected:
                raise ValueError("Jalankan Check for update terlebih dahulu.")
            publish("queued", state["commit"], "Pembaruan dijadwalkan.", state.get("version", ""))
            try:
                subprocess.run(["/usr/bin/systemctl", "start", "--no-block", "unbound-web-update"], check=True, timeout=10)
            except (OSError, subprocess.SubprocessError):
                publish("available", state["commit"], "Gagal memulai layanan pembaruan.", state.get("version", ""))
                raise
        elif action == "install" and state["phase"] == "queued":
            try:
                install(state["commit"], state.get("version", ""))
            except (ValueError, OSError, subprocess.SubprocessError) as error:
                print(error, file=sys.stderr)
                publish("failed", state["commit"], "Pembaruan gagal. Periksa journal unbound-web-update.", state.get("version", ""))
                raise
        else:
            raise ValueError("Operasi pembaruan tidak valid.")
        print(json.dumps(read_status()))


if __name__ == "__main__":
    try:
        if len(sys.argv) == 4 and sys.argv[1] == "version":
            print(version_for(Path(sys.argv[2]).resolve(), sys.argv[3]))
        elif os.geteuid() != 0 or len(sys.argv) not in (2, 3):
            raise ValueError("Gunakan management helper untuk pembaruan.")
        else:
            main(*sys.argv[1:])
    except BlockingIOError:
        sys.exit("Pembaruan sedang berjalan.")
    except (ValueError, OSError, subprocess.SubprocessError) as error:
        sys.exit(str(error))

# System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
