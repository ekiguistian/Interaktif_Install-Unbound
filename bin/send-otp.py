"""Send a panel recovery email using the selected SMTP transport."""

import json
import smtplib
import ssl
import sys
from email.message import EmailMessage


def send(smtp, recipient, otp):
    message = EmailMessage()
    message["From"] = smtp["sender"]
    message["To"] = recipient
    message["Subject"] = "Kode pemulihan Unbound Web Manager"
    message.set_content(f"Kode pemulihan Anda: {otp}\n\nKode berlaku 10 menit dan hanya dapat dipakai sekali.\n")
    mode = smtp["encryption"]
    if mode not in ("ssl", "starttls", "none") or (mode == "none" and smtp["username"]):
        raise ValueError("Mode SMTP tidak valid.")
    endpoint = (smtp["host"], int(smtp["port"]))
    tls = ssl.create_default_context() if mode != "none" else None
    client = smtplib.SMTP_SSL(*endpoint, timeout=5, context=tls) if mode == "ssl" else smtplib.SMTP(*endpoint, timeout=5)
    with client:
        if mode == "starttls":
            client.starttls(context=tls)
        if smtp["username"]:
            client.login(smtp["username"], smtp["password"])
        client.send_message(message, from_addr=smtp["sender"], to_addrs=[recipient])


if __name__ == "__main__":
    try:
        send(**json.load(sys.stdin))
    except (OSError, ValueError, smtplib.SMTPException):
        sys.exit("Pengiriman OTP gagal.")

# System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
