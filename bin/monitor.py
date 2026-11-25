#!/usr/bin/python3
"""Collect one sample per second, with no overlapping workers or catch-up bursts."""
import subprocess
import time


def main():
    deadline = time.monotonic()
    while True:
        try:
            subprocess.run(
                ["/usr/bin/php8.4", "/opt/www/unbound-web/bin/worker.php"],
                stdout=subprocess.DEVNULL, timeout=20, check=True,
            )
        except (subprocess.SubprocessError, OSError) as error:
            print(f"Pengambilan metrik gagal: {error}", flush=True)
        deadline += 1.0
        now = time.monotonic()
        if deadline <= now:
            deadline = now
        time.sleep(deadline - now)


if __name__ == "__main__":
    main()

# System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
