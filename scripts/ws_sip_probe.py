#!/usr/bin/env python3
"""Register an extension over a SIP WebSocket, the way a browser softphone does.

The browser does not speak SIP over TCP: it opens a WebSocket to /ws, wraps a
REGISTER in a text frame, answers the digest challenge and keeps the socket
warm with CRLF pairs so an idle proxy does not cut it. This does exactly that,
so the WebSocket path (browser -> tunnel -> nginx -> apache -> Asterisk) can be
tested without a browser, a login or a microphone.

usage:
  python3 scripts/ws_sip_probe.py --url ws://127.0.0.1:8088/ws --user 2001 \
      --password-file /tmp/ext.pass
  python3 scripts/ws_sip_probe.py --url wss://aipbx.bid/ws --user 2001 \
      --password-file /tmp/ext.pass --hold 150

Exit codes:
  0  registered over the WebSocket (and stayed connected for --hold seconds)
  2  TCP/TLS connection failed
  3  TLS certificate rejected
  4  the HTTP upgrade was refused (not 101) — proxy or path problem
  5  registrar refused the registration
  6  credentials rejected
  7  the socket dropped while holding it open (idle timeout)

Only the standard library is used. The SIP building and digest code is shared
with sip_tls_probe.py, so both probes answer challenges the same way.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import os
import re
import socket
import ssl
import struct
import sys
import time
import uuid
from urllib.parse import urlsplit

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import sip_tls_probe as sip  # noqa: E402

GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"


class WsError(Exception):
    def __init__(self, code: int, message: str):
        super().__init__(message)
        self.code = code


# --------------------------------------------------------------------------
# WebSocket, hand-rolled because a probe must not need a package
# --------------------------------------------------------------------------

class WebSocket:
    def __init__(self, url: str, timeout: float = 8.0, insecure: bool = False):
        parts = urlsplit(url)
        if parts.scheme not in ("ws", "wss"):
            raise WsError(4, f"unsupported scheme {parts.scheme!r}: use ws:// or wss://")
        host = parts.hostname or ""
        port = parts.port or (443 if parts.scheme == "wss" else 80)
        path = parts.path or "/"
        if parts.query:
            path += "?" + parts.query
        self.timeout = timeout
        self.log: list[str] = []

        try:
            raw = socket.create_connection((host, port), timeout=timeout)
        except OSError as exc:
            raise WsError(2, f"connect {host}:{port}: {exc.strerror or exc}")

        if parts.scheme == "wss":
            ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
            # Browsers offer ALPN; a stream router that sniffs it (the PBX's
            # nginx splits TLS by ALPN between the portal and TURNS) sends a
            # client without ALPN down the wrong branch and closes it.
            ctx.set_alpn_protocols(["http/1.1"])
            if insecure:
                ctx.check_hostname = False
                ctx.verify_mode = ssl.CERT_NONE
            try:
                raw = ctx.wrap_socket(raw, server_hostname=host)
            except ssl.SSLCertVerificationError as exc:
                raw.close()
                raise WsError(3, f"TLS certificate rejected: {exc.verify_message or exc}")
            except (ssl.SSLError, OSError) as exc:
                raw.close()
                raise WsError(2, f"TLS handshake failed: {exc}")
            self.log.append(f"v TLS {raw.version()} {(raw.cipher() or ('?',))[0]}")

        self.sock = raw
        self.buffer = b""
        self._handshake(host, port, path, parts.scheme)
        self.open = True

    def _handshake(self, host: str, port: int, path: str, scheme: str) -> None:
        key = base64.b64encode(os.urandom(16)).decode()
        host_header = host if port in (80, 443) else f"{host}:{port}"
        request = (
            f"GET {path} HTTP/1.1\r\n"
            f"Host: {host_header}\r\n"
            "Upgrade: websocket\r\n"
            "Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\n"
            "Sec-WebSocket-Version: 13\r\n"
            "Sec-WebSocket-Protocol: sip\r\n"
            "User-Agent: AiPBX-WS-Probe/1.0\r\n"
            "\r\n"
        )
        self.sock.sendall(request.encode())
        self.sock.settimeout(self.timeout)
        while b"\r\n\r\n" not in self.buffer:
            chunk = self.sock.recv(65535)
            if not chunk:
                raise WsError(4, "connection closed during the HTTP upgrade")
            self.buffer += chunk
        head, self.buffer = self.buffer.split(b"\r\n\r\n", 1)
        head_text = head.decode("utf-8", "replace")
        status = head_text.split("\r\n", 1)[0]
        match = re.match(r"HTTP/1\.[01] (\d{3})", status)
        code = int(match.group(1)) if match else 0
        if code != 101:
            detail = ""
            extra = [l for l in head_text.split("\r\n")[1:] if l.lower().startswith(("sec-websocket", "location", "server"))]
            if extra:
                detail = " (" + ", ".join(extra[:3]) + ")"
            raise WsError(4, f"upgrade refused: {status}{detail}")
        expect = base64.b64encode(hashlib.sha1((key + GUID).encode()).digest()).decode()
        if f"sec-websocket-accept: {expect}".lower() not in head_text.lower():
            raise WsError(4, "101 without a matching Sec-WebSocket-Accept")
        self.log.append(f"v WebSocket upgraded at {path}")

    # -- frames -----------------------------------------------------------
    def send_text(self, text: str) -> None:
        self._send(0x1, text.encode())

    def _send(self, opcode: int, payload: bytes) -> None:
        mask = os.urandom(4)
        header = bytes([0x80 | opcode])
        length = len(payload)
        if length < 126:
            header += bytes([0x80 | length])
        elif length < 65536:
            header += bytes([0x80 | 126]) + struct.pack(">H", length)
        else:
            header += bytes([0x80 | 127]) + struct.pack(">Q", length)
        masked = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
        self.sock.sendall(header + mask + masked)

    def _read_exact(self, count: int) -> bytes:
        while len(self.buffer) < count:
            chunk = self.sock.recv(65535)
            if not chunk:
                raise WsError(7, "socket closed while waiting for a frame")
            self.buffer += chunk
        out, self.buffer = self.buffer[:count], self.buffer[count:]
        return out

    def recv_text(self) -> str:
        """Return the next text payload; CRLF keepalive frames come back empty."""
        while True:
            b1, b2 = self._read_exact(2)
            opcode = b1 & 0x0F
            length = b2 & 0x7F
            if length == 126:
                length = struct.unpack(">H", self._read_exact(2))[0]
            elif length == 127:
                length = struct.unpack(">Q", self._read_exact(8))[0]
            payload = self._read_exact(length) if length else b""
            if opcode == 0x8:
                self.open = False
                raise WsError(7, "server closed the WebSocket")
            if opcode == 0x9:
                self._send(0xA, payload)
                continue
            if opcode == 0xA:
                continue
            return payload.decode("utf-8", "replace")

    def keepalive(self) -> None:
        """CRLF as a text frame: what JsSIP's keep_alive_interval sends."""
        self.send_text("\r\n")

    def close(self) -> None:
        try:
            self._send(0x8, b"")
            self.sock.close()
        except OSError:
            pass
        self.open = False


# --------------------------------------------------------------------------
# SIP over it
# --------------------------------------------------------------------------

def register(ws: WebSocket, user: str, password: str, host: str, timeout: float) -> tuple[int, list[str]]:
    out: list[str] = []
    call_id = f"{uuid.uuid4()}@ws"
    local = "0.0.0.0"
    contact_host = host

    def send(cseq: int, auth: str = "") -> str:
        msg = sip.build_register(host, 5061, user, local, 5061, call_id, cseq,
                                 f"z9hG4bK{uuid.uuid4().hex[:12]}", auth)
        msg = msg.replace(f"sip:{user}@{local}:5061;transport=tls", f"sip:{user}@{contact_host};transport=ws")
        msg = msg.replace("Via: SIP/2.0/TLS", "Via: SIP/2.0/WSS")
        ws.send_text(msg)
        return msg

    send(1)
    deadline = time.monotonic() + timeout
    reply = ""
    while time.monotonic() < deadline:
        reply = ws.recv_text()
        if reply.strip():
            break
    if not reply.strip():
        raise WsError(7, "no SIP response arrived on the WebSocket")
    code, reason = sip.status_of(reply)
    out.append(f"  unauthenticated REGISTER -> {code} {reason}")
    if code == 200:
        out.append("ok registered over the WebSocket without a challenge")
        return 0, out
    if code not in (401, 407):
        out.append("x registrar refused before authentication")
        return 5, out

    header = "WWW-Authenticate" if code == 401 else "Proxy-Authenticate"
    chal = sip.parse_auth_challenge(reply, header)
    if not chal.get("nonce"):
        out.append(f"x {code} without a usable challenge")
        return 5, out
    out.append(f"  challenge realm={chal.get('realm')} qop={chal.get('qop', 'none')}")

    if not password:
        out.append("! no password supplied: the challenge is as far as this goes")
        return 6, out

    uri = f"sip:{host}"
    response, nc, cnonce, qop, algo = sip.digest_response("REGISTER", uri, user, password, chal)
    fields = [
        f'Digest username="{user}"',
        f'realm="{chal["realm"]}"',
        f'nonce="{chal["nonce"]}"',
        f'uri="{uri}"',
        f'response="{response}"',
        f"algorithm={algo}",
    ]
    if qop:
        fields += [f"qop={qop}", f"nc={nc}", f'cnonce="{cnonce}"']
    if chal.get("opaque"):
        fields.append(f'opaque="{chal["opaque"]}"')
    hdr = "Authorization" if code == 401 else "Proxy-Authorization"
    send(2, f"{hdr}: " + ", ".join(fields))

    reply = ""
    while time.monotonic() < deadline:
        reply = ws.recv_text()
        if reply.strip():
            break
    if not reply.strip():
        raise WsError(7, "no answer to the authenticated REGISTER")
    code, reason = sip.status_of(reply)
    out.append(f"  authenticated REGISTER   -> {code} {reason}")
    if code == 200:
        out.append(f"ok REGISTER accepted over the WebSocket for {user}@{host}")
        return 0, out
    if code in (401, 403):
        out.append("x credentials rejected")
        return 6, out
    return 5, out


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Register over a SIP WebSocket and hold the connection.")
    parser.add_argument("--url", required=True, help="ws://host/ws or wss://host/ws")
    parser.add_argument("--user", required=True)
    parser.add_argument("--password", default="")
    parser.add_argument("--password-file", default="")
    parser.add_argument("--host", default="", help="SIP domain to register to (defaults to the URL host)")
    parser.add_argument("--timeout", type=float, default=8.0)
    parser.add_argument("--hold", type=float, default=0.0,
                        help="keep the socket open this many seconds, sending keepalives")
    parser.add_argument("--keepalive", type=float, default=25.0,
                        help="keepalive interval in seconds (default 25)")
    parser.add_argument("--insecure", action="store_true", help="do not enforce certificate trust")
    args = parser.parse_args(argv)

    password = args.password
    if args.password_file:
        with open(args.password_file, encoding="utf-8") as fh:
            password = fh.read().strip()
    host = args.host or urlsplit(args.url).hostname or ""

    try:
        ws = WebSocket(args.url, args.timeout, args.insecure)
    except WsError as exc:
        print(f"-> {args.url}")
        print(f"x {exc}")
        return exc.code

    print(f"-> {args.url}")
    for line in ws.log:
        print(line)

    code = 0
    try:
        code, lines = register(ws, args.user, password, host, args.timeout)
        for line in lines:
            print(line)
    except WsError as exc:
        print(f"x {exc}")
        code = exc.code

    if code == 0 and args.hold > 0:
        sent = 0
        start = time.monotonic()
        print(f"  holding the socket {args.hold:.0f}s with keepalives every {args.keepalive:.0f}s")
        try:
            while time.monotonic() - start < args.hold:
                time.sleep(min(args.keepalive, max(0.1, args.hold - (time.monotonic() - start))))
                if time.monotonic() - start >= args.hold:
                    break
                ws.keepalive()
                sent += 1
                # A proxy that has dropped us will fail the next write or read.
                ws.sock.settimeout(0.2)
                try:
                    ws.recv_text()
                except socket.timeout:
                    pass
                except WsError as exc:
                    print(f"x socket dropped after {sent} keepalives: {exc}")
                    code = 7
                    break
            else:
                pass
        finally:
            held = time.monotonic() - start
        if code == 0:
            print(f"ok socket still open after {held:.0f}s ({sent} keepalives)")
    ws.close()
    return code


if __name__ == "__main__":
    sys.exit(main())
