import hashlib
import importlib.util
import io
import json
from contextlib import nullcontext, redirect_stdout
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

SCRIPT = Path(__file__).resolve().parents[1] / "bin" / "manage.py"
SPEC = importlib.util.spec_from_file_location("unbound_manage", SCRIPT)
manage = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(manage)

CONFIG = (
    'server:\r\n'
    '    interface: 127.0.0.1 # loopback\r\n'
    '    interface: "10.10.10.10@53" # LAN\r\n'
    '    # interface: 192.0.2.1\r\n'
    '    interface: ::1\r\n'
    '    interface: eth0\r\n'
    '    local-zone: "10.10.10.in-addr.arpa." static # reverse\r\n'
    '    local-data: "10.10.10.10.in-addr.arpa. 60 IN PTR ns1.nexan.id." # PTR\r\n'
    '    local-data: "example.net. IN A 192.0.2.10"\r\n'
    '    log-queries: yes\r\n'
    '    include: "/etc/unbound/blocklist/blocklist.conf"\r\n'
    'forward-zone:\r\n'
    '    name: "."\r\n'
    '    forward-addr: 1.1.1.1\r\n'
)


class NetworkConfigTest(unittest.TestCase):
    def test_reads_loopback_ports_and_reverse_records(self):
        rows = manage.network_snapshot(CONFIG)
        self.assertEqual([row["editable"] for row in rows["interface"]], [False, True, False, False])
        self.assertEqual(rows["interface"][1]["port"], "53")
        self.assertEqual([row["mode"] for row in rows["local-zone"]], ["static"])
        self.assertEqual([row["target"] for row in rows["local-data"]], ["ns1.nexan.id."])

    def test_edit_changes_only_selected_value(self):
        cases = [
            (2, {"kind": "interface", "value": "10.10.10.11"}, '"10.10.10.10@53"', '"10.10.10.11@53"'),
            (6, {"kind": "local-zone", "value": "10.10.in-addr.arpa"}, '"10.10.10.in-addr.arpa."', '"10.10.in-addr.arpa."'),
            (7, {"kind": "local-data", "value": "11.10.10.10.in-addr.arpa", "target": "ns2.nexan.id"},
             '10.10.10.10.in-addr.arpa. 60 IN PTR ns1.nexan.id.', '11.10.10.10.in-addr.arpa. 60 IN PTR ns2.nexan.id.'),
        ]
        for line, values, original, replacement in cases:
            with self.subTest(values=values):
                self.assertEqual(manage.edit_network(CONFIG, line, values), CONFIG.replace(original, replacement))

    def test_rejects_loopback_and_invalid_values(self):
        cases = [
            (1, {"kind": "interface", "value": "192.0.2.9"}),
            (4, {"kind": "interface", "value": "2001:db8::9"}),
            (2, {"kind": "interface", "value": "127.0.0.99"}),
            (2, {"kind": "interface", "value": "::ffff:127.0.0.1"}),
            (2, {"kind": "interface", "value": "fe80::1%eth0\nserver:"}),
            (6, {"kind": "local-zone", "value": "example.net"}),
            (7, {"kind": "local-data", "value": "11.10.10.10.in-addr.arpa", "target": 'ns1."example.net'}),
        ]
        for line, values in cases:
            with self.subTest(values=values), self.assertRaises(ValueError):
                manage.edit_network(CONFIG, line, values)

    def test_raw_editor_refuses_to_remove_loopback(self):
        old = CONFIG.encode()
        revision = hashlib.sha256(old).hexdigest()
        submitted = CONFIG.replace("    interface: 127.0.0.1 # loopback\r\n", "").encode()
        path = Mock()
        path.read_bytes.return_value = old
        with patch.object(manage, "trusted_config_path", return_value=path), \
             patch.object(manage, "validate_web_config"), patch.object(manage, "apply_config") as apply, \
             patch.object(sys, "stdin", SimpleNamespace(buffer=io.BytesIO(submitted))), \
             self.assertRaisesRegex(ValueError, "loopback"):
            manage.editor("config-save", "unbound", revision)
        apply.assert_not_called()

    def test_opening_editor_records_the_current_snapshot(self):
        path = Mock()
        path.read_bytes.return_value = CONFIG.encode()
        with patch.object(manage, "trusted_config_path", return_value=path), \
             patch.object(manage, "lock", return_value=nullcontext()), \
             patch.object(manage, "save_config_snapshot") as save, redirect_stdout(io.StringIO()) as output:
            manage.editor("config-read", "unbound")
        result = json.loads(output.getvalue())
        self.assertEqual(result["content"], CONFIG)
        save.assert_called_once_with(dict(revision=result["revision"], network=result["network"]))

    def test_save_checks_revision_and_chooses_service_action(self):
        old = CONFIG.encode()
        revision = hashlib.sha256(old).hexdigest()
        path = Mock()
        path.read_bytes.return_value = old
        with patch.object(manage, "trusted_config_path", return_value=path), \
             patch.object(manage, "validate_web_config"), patch.object(manage, "apply_config") as apply, \
             patch.object(manage, "active", return_value="active") as active, patch("builtins.print"):
            with self.assertRaisesRegex(ValueError, "File berubah"):
                manage.save_network("0" * 64, "2")
            apply.assert_not_called()
            for line, kind, value, status in [
                ("2", "interface", "10.10.10.11", "active"),
                ("6", "local-zone", "10.10.in-addr.arpa", "active"),
                ("2", "interface", "10.10.10.11", "inactive"),
            ]:
                active.return_value = status
                body = json.dumps(dict(kind=kind, value=value)).encode()
                with patch.object(sys, "stdin", SimpleNamespace(buffer=io.BytesIO(body))):
                    manage.save_network(revision, line)
                self.assertEqual(apply.call_args.kwargs,
                                 dict(reload=status == "active", restart=kind == "interface", after=manage.record_config))

    def test_apply_restores_original_after_service_or_database_failure(self):
        old = CONFIG.encode()
        data = CONFIG.replace("10.10.10.10@53", "10.10.10.11@53").encode()
        for database_failure in (False, True):
            with self.subTest(database_failure=database_failure), tempfile.TemporaryDirectory() as directory:
                path = Path(directory) / "unbound.conf"
                path.write_bytes(old)
                actions = []

                def command(*args):
                    if args[:2] == ("systemctl", "restart"):
                        actions.append(path.read_bytes())
                        if not database_failure and len(actions) == 1:
                            raise subprocess.CalledProcessError(1, args)

                def after():
                    raise ValueError("Database gagal")

                with patch.object(manage, "checked", return_value="unbound"), \
                     patch.object(manage.pwd, "getpwnam", return_value=SimpleNamespace(pw_uid=100)), \
                     patch.object(manage, "command", side_effect=command), \
                     patch.object(manage.os, "fchown"):
                    with self.assertRaises(ValueError if database_failure else subprocess.CalledProcessError):
                        manage.apply_config("unbound", path, old, data, after=after, restart=True)
                self.assertEqual(path.read_bytes(), old)
                self.assertEqual(actions, [data, old])


if __name__ == "__main__":
    unittest.main()

# System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
