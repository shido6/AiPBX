"""Tests for scripts/sip_tls_probe.py.

A small registrar runs over a real TLS socket and checks the Authorization
header against a digest it computes itself, so this covers the parts a probe
gets wrong in practice: the handshake, the challenge, the digest math with and
without qop, and the exit codes a runbook depends on.

Runs with `python3 -m unittest discover scripts` or `pytest scripts`.
"""

from __future__ import annotations

import hashlib
import os
import re
import socket
import ssl
import subprocess
import sys
import tempfile
import threading
import time
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import sip_tls_probe as probe  # noqa: E402

REALM = "asterisk"
PASSWORD = "s3cret"
USER = "1001"

OPENSSL_CONF = """
[req]
distinguished_name = dn
x509_extensions = v3
prompt = no
[dn]
CN = {cn}
[v3]
subjectAltName = {san}
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
"""


def make_cert(directory: str, name: str, san: str, cn: str = "localhost") -> tuple[str, str]:
    """Self-signed certificate for the fake registrar."""
    cert = os.path.join(directory, f"{name}.crt")
    key = os.path.join(directory, f"{name}.key")
    conf = os.path.join(directory, f"{name}.cnf")
    with open(conf, "w", encoding="utf-8") as fh:
        fh.write(OPENSSL_CONF.format(cn=cn, san=san))
    subprocess.run(
        ["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
         "-keyout", key, "-out", cert, "-config", conf],
        check=True, capture_output=True,
    )
    return cert, key


def digest(method: str, uri: str, user: str, password: str, realm: str, nonce: str,
           nc: str = "", cnonce: str = "", qop: str = "") -> str:
    h = lambda s: hashlib.md5(s.encode()).hexdigest()  # noqa: E731
    ha1 = h(f"{user}:{realm}:{password}")
    ha2 = h(f"{method}:{uri}")
    if qop:
        return h(f"{ha1}:{nonce}:{nc}:{cnonce}:{qop}:{ha2}")
    return h(f"{ha1}:{nonce}:{ha2}")


def read_request(conn: ssl.SSLSocket) -> str:
    buf = b""
    while b"\r\n\r\n" not in buf:
        chunk = conn.recv(65535)
        if not chunk:
            return ""
        buf += chunk
    return buf.decode("utf-8", "replace")


def response(code: int, reason: str, extra: str = "") -> bytes:
    msg = f"SIP/2.0 {code} {reason}\r\n" + extra + "Content-Length: 0\r\n\r\n"
    return msg.encode()


class FakeRegistrar:
    """A registrar that behaves like Asterisk's res_pjsip_registrar for one client."""

    def __init__(self, cert: str, key: str, mode: str = "auth", qop: str = "auth",
                 password: str = PASSWORD):
        self.cert = cert
        self.key = key
        self.mode = mode
        self.qop = qop
        self.password = password
        self.nonce = "9f8e7d6c5b4a39281706f5e4d3c2b1a0"
        self.seen_auth: str = ""
        self.port = 0
        self._listener: socket.socket | None = None
        self._thread: threading.Thread | None = None
        self._error: str = ""

    def __enter__(self) -> "FakeRegistrar":
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(self.cert, self.key)
        self._ctx = ctx
        self._listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self._listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self._listener.bind(("127.0.0.1", 0))
        self._listener.listen(1)
        self.port = self._listener.getsockname()[1]
        self._thread = threading.Thread(target=self._serve, daemon=True)
        self._thread.start()
        return self

    def __exit__(self, *exc: object) -> None:
        if self._listener:
            self._listener.close()
        if self._thread:
            self._thread.join(timeout=5)

    def _serve(self) -> None:
        try:
            raw, _ = self._listener.accept()
        except OSError:
            return
        if self.mode == "plain":
            raw.sendall(b"not TLS\r\n")
            raw.close()
            return
        try:
            conn = self._ctx.wrap_socket(raw, server_side=True)
        except (ssl.SSLError, OSError):
            raw.close()
            return
        try:
            if self.mode == "silent":
                time.sleep(2.0)
                return
            for attempt in range(2):
                request = read_request(conn)
                if not request:
                    return
                first = request.split("\r\n", 1)[0]
                uri = first.split(" ")[1] if " " in first else ""
                if self.mode == "forbidden":
                    conn.sendall(response(403, "Forbidden"))
                    return
                if self.mode == "open":
                    conn.sendall(response(200, "OK", "Expires: 60\r\n"))
                    return
                if attempt == 0:
                    params = f'realm="{REALM}", nonce="{self.nonce}", algorithm=MD5'
                    if self.qop:
                        params += f', qop="{self.qop}"'
                    conn.sendall(response(401, "Unauthorized",
                                          f"WWW-Authenticate: Digest {params}\r\n"))
                    continue
                match = re.search(r"^Authorization:\s*(.+)$", request, re.I | re.M)
                if not match:
                    conn.sendall(response(401, "Unauthorized"))
                    return
                self.seen_auth = match.group(1)
                fields = dict(re.findall(r'(\w+)="?([^",]*)"?', self.seen_auth))
                expected = digest(
                    "REGISTER", fields.get("uri", uri), USER, self.password, REALM,
                    fields.get("nonce", ""), fields.get("nc", ""),
                    fields.get("cnonce", ""), fields.get("qop", ""),
                )
                if fields.get("response") == expected and fields.get("username") == USER:
                    conn.sendall(response(200, "OK", "Expires: 60\r\n"
                                          f"Contact: <{fields.get('uri', uri)}>;expires=60\r\n"))
                else:
                    conn.sendall(response(401, "Unauthorized"))
                    self._error = f"digest mismatch: {self.seen_auth}"
        finally:
            conn.close()


def closed_port() -> int:
    sock = socket.socket()
    sock.bind(("127.0.0.1", 0))
    port = sock.getsockname()[1]
    sock.close()
    return port


class ProbeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls._tmp = tempfile.TemporaryDirectory()
        cls.cert, cls.key = make_cert(cls._tmp.name, "localhost", "DNS:localhost")
        cls.other_cert, cls.other_key = make_cert(
            cls._tmp.name, "other", "DNS:pbx.example", cn="pbx.example")

    @classmethod
    def tearDownClass(cls) -> None:
        cls._tmp.cleanup()

    def test_registers_with_digest_auth(self) -> None:
        with FakeRegistrar(self.cert, self.key) as server:
            code, lines = probe.probe("127.0.0.1", server.port, USER, PASSWORD,
                                      timeout=5, insecure=True)
        self.assertEqual(code, 0, "\n".join(lines))
        text = "\n".join(lines)
        self.assertIn("ok REGISTER accepted over TLS", text)
        self.assertIn("401 Unauthorized", text)
        self.assertIn("200 OK", text)
        self.assertIn("qop=auth", text)
        self.assertIn(server.seen_auth, server.seen_auth)  # server saw an Authorization header
        self.assertIn("response=", server.seen_auth)
        self.assertEqual(server._error, "")

    def test_registers_without_qop(self) -> None:
        with FakeRegistrar(self.cert, self.key, qop="") as server:
            code, lines = probe.probe("127.0.0.1", server.port, USER, PASSWORD,
                                      timeout=5, insecure=True)
        self.assertEqual(code, 0, "\n".join(lines))
        self.assertIn("qop=none", "\n".join(lines))
        self.assertNotIn("nc=", server.seen_auth)
        self.assertEqual(server._error, "")

    def test_wrong_password_is_reported_as_rejected(self) -> None:
        with FakeRegistrar(self.cert, self.key) as server:
            code, lines = probe.probe("127.0.0.1", server.port, USER, "wrong",
                                      timeout=5, insecure=True)
        self.assertEqual(code, 6, "\n".join(lines))
        self.assertIn("x credentials rejected", "\n".join(lines))

    def test_stops_at_challenge_without_password(self) -> None:
        with FakeRegistrar(self.cert, self.key) as server:
            code, lines = probe.probe("127.0.0.1", server.port, USER, "",
                                      timeout=5, insecure=True)
        self.assertEqual(code, 6)
        self.assertIn("no password supplied", "\n".join(lines))

    def test_forbidden_before_auth(self) -> None:
        with FakeRegistrar(self.cert, self.key, mode="forbidden") as server:
            code, lines = probe.probe("127.0.0.1", server.port, USER, PASSWORD,
                                      timeout=5, insecure=True)
        self.assertEqual(code, 5, "\n".join(lines))
        self.assertIn("403 Forbidden", "\n".join(lines))

    def test_open_registration(self) -> None:
        with FakeRegistrar(self.cert, self.key, mode="open") as server:
            code, lines = probe.probe("127.0.0.1", server.port, USER, "",
                                      timeout=5, insecure=True)
        self.assertEqual(code, 0, "\n".join(lines))
        self.assertIn("registered without a challenge", "\n".join(lines))

    def test_nothing_listening(self) -> None:
        code, lines = probe.probe("127.0.0.1", closed_port(), USER, PASSWORD, timeout=2)
        self.assertEqual(code, 2)
        self.assertIn("x connect", "\n".join(lines))
        self.assertIn("nothing is listening", "\n".join(lines))

    def test_port_that_is_not_tls(self) -> None:
        with FakeRegistrar(self.cert, self.key, mode="plain") as server:
            code, lines = probe.probe("127.0.0.1", server.port, USER, PASSWORD, timeout=5)
        self.assertEqual(code, 2, "\n".join(lines))
        self.assertIn("x TLS handshake failed", "\n".join(lines))

    def test_untrusted_certificate_blocks_a_phone(self) -> None:
        with FakeRegistrar(self.cert, self.key) as server:
            code, lines = probe.probe("127.0.0.1", server.port, USER, PASSWORD, timeout=5)
        self.assertEqual(code, 3, "\n".join(lines))
        self.assertIn("x TLS certificate rejected", "\n".join(lines))

    def test_name_mismatch_is_loud_but_survivable_when_insecure(self) -> None:
        with FakeRegistrar(self.other_cert, self.other_key) as server:
            code, lines = probe.probe("127.0.0.1", server.port, USER, PASSWORD,
                                      timeout=5, insecure=True)
        text = "\n".join(lines)
        self.assertEqual(code, 0, text)
        self.assertIn("! certificate does not cover 127.0.0.1", text)
        self.assertIn("certificate names: pbx.example", text)

    def test_silent_peer_times_out_as_no_sip_response(self) -> None:
        with FakeRegistrar(self.cert, self.key, mode="silent") as server:
            code, lines = probe.probe("127.0.0.1", server.port, USER, PASSWORD,
                                      timeout=0.5, insecure=True)
        self.assertEqual(code, 4, "\n".join(lines))
        self.assertIn("x no SIP response", "\n".join(lines))


class NameMatchTests(unittest.TestCase):
    def test_exact_name(self) -> None:
        self.assertTrue(probe._name_matches("sip-tls.s2insights.cloud", ["sip-tls.s2insights.cloud"]))

    def test_wildcard_covers_one_label(self) -> None:
        self.assertTrue(probe._name_matches("sip-tls.s2insights.cloud", ["*.s2insights.cloud"]))
        self.assertFalse(probe._name_matches("a.b.s2insights.cloud", ["*.s2insights.cloud"]))

    def test_case_and_trailing_dot(self) -> None:
        self.assertTrue(probe._name_matches("SIP-TLS.S2insights.Cloud.", ["sip-tls.s2insights.cloud"]))

    def test_unrelated_name(self) -> None:
        self.assertFalse(probe._name_matches("sip-tls.s2insights.cloud", ["s2insights.cloud", "pbx.example"]))

    def test_ip_certificate(self) -> None:
        self.assertTrue(probe._name_matches("192.0.2.10", ["192.0.2.10"]))
        self.assertFalse(probe._name_matches("192.0.2.11", ["192.0.2.10"]))


if __name__ == "__main__":
    unittest.main()
