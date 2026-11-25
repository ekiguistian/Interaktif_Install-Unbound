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
REF = re.compile(r"[0-9a-f]{40}")
GIT = ["/usr/bin/git", "-c", "credential.helper=", "-c", "protocol.allow=never", "-c", "protocol.https.allow=always"]
GIT_ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "HOME": "/root", "GIT_CONFIG_NOSYSTEM": "1",
           "GIT_CONFIG_GLOBAL": "/dev/null", "GIT_TERMINAL_PROMPT": "0"}


def git(*args, timeout=25):
    return subprocess.check_output([*GIT, *args], env=GIT_ENV, cwd="/", text=True, timeout=timeout).strip()


def read_status():
    state = json.loads(STATUS.read_text()) if STATUS.is_file() else {"phase": "idle"}
    installed = INSTALLED.read_text().strip() if INSTALLED.is_file() else ""
    state["installed"] = installed if REF.fullmatch(installed) else ""
    return state


def publish(phase, commit="", message=""):
    state = {"phase": phase, "commit": commit, "message": message, "updated_at": int(time.time())}
    with tempfile.NamedTemporaryFile(dir=STATUS.parent, prefix=".update-", mode="w", delete=False) as handle:
        path = Path(handle.name)
        json.dump(state, handle)
    try:
        os.replace(path, STATUS)
    finally:
        path.unlink(missing_ok=True)


def check():
    result = git("ls-remote", "--exit-code", REPOSITORY, "refs/heads/main").split()
    if len(result) != 2 or not REF.fullmatch(result[0]) or result[1] != "refs/heads/main":
        raise ValueError("Commit main tidak dapat diverifikasi.")
    commit = result[0]
    if commit == read_status()["installed"]:
        publish("current", commit, "Panel sudah memakai commit terbaru.")
    else:
        publish("available", commit, "Pembaruan tersedia. Periksa commit sebelum memasang.")


def install(commit):
    if not REF.fullmatch(commit):
        raise ValueError("Commit pembaruan tidak valid.")
    WORK.mkdir(mode=0o700, exist_ok=True)
    if WORK.is_symlink() or WORK.stat().st_uid != 0 or WORK.stat().st_mode & 0o077:
        raise ValueError("Direktori checkout pembaruan tidak aman.")
    source = Path(tempfile.mkdtemp(prefix="main-", dir=WORK))
    try:
        publish("cloning", commit, "Mengambil branch main dari GitHub.")
        git("clone", "--depth=1", "--single-branch", "--branch=main", "--", REPOSITORY, str(source), timeout=120)
        if git("-C", str(source), "rev-parse", "HEAD") != commit:
            raise ValueError("Branch main berubah. Jalankan Check for update lagi.")
        installer = source / "install.sh"
        if installer.is_symlink() or not installer.is_file():
            raise ValueError("Installer tidak ditemukan di commit yang dipilih.")
        publish("installing", commit, "Menjalankan install.sh. Panel dapat terputus sebentar.")
        environment = {"PATH": GIT_ENV["PATH"], "HOME": "/root", "UNBOUND_WEB_QUIET_CREDENTIALS": "1"}
        subprocess.run(["/usr/bin/bash", installer], cwd=source, env=environment, check=True, timeout=1800)
        if read_status()["installed"] != commit:
            raise ValueError("Installer selesai, tetapi commit yang terpasang tidak dapat diverifikasi.")
    except BaseException:
        shutil.rmtree(source)
        raise
    publish("done", commit, "Pembaruan berhasil. Muat ulang halaman untuk memakai versi baru.")
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
            publish("queued", state["commit"], "Pembaruan dijadwalkan.")
            try:
                subprocess.run(["/usr/bin/systemctl", "start", "--no-block", "unbound-web-update"], check=True, timeout=10)
            except (OSError, subprocess.SubprocessError):
                publish("available", state["commit"], "Gagal memulai layanan pembaruan.")
                raise
        elif action == "install" and state["phase"] == "queued":
            try:
                install(state["commit"])
            except (ValueError, OSError, subprocess.SubprocessError) as error:
                print(error, file=sys.stderr)
                publish("failed", state["commit"], "Pembaruan gagal. Periksa journal unbound-web-update.")
                raise
        else:
            raise ValueError("Operasi pembaruan tidak valid.")
        print(json.dumps(read_status()))


if __name__ == "__main__":
    try:
        if os.geteuid() != 0 or len(sys.argv) not in (2, 3):
            raise ValueError("Gunakan management helper untuk pembaruan.")
        main(*sys.argv[1:])
    except BlockingIOError:
        sys.exit("Pembaruan sedang berjalan.")
    except (ValueError, OSError, subprocess.SubprocessError) as error:
        sys.exit(str(error))

# System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
