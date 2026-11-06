#!/usr/bin/env python3
"""Register an extension to a PBX over SIP/TLS, the way a phone does it.

This answers one question with evidence instead of a guess: can a real phone
with a real certificate store complete a REGISTER against this host over TLS?
It does the whole handshake itself -- TLS, the unauthenticated REGISTER, the
digest challenge, the authenticated REGISTER -- and prints what came back at
each step, so a failure points at the one thing that is wrong (no listener,
untrusted certificate, a name that does not match the certificate, a firewall
that answers but never speaks SIP, bad credentials) rather than "TLS is broken".

usage:
  python3 scripts/sip_tls_probe.py --host sip-tls.s2insights.cloud --user 1001 --password ***
  python3 scripts/sip_tls_probe.py --host 127.0.0.1 --port 5061 --user 1001 \
      --password-file /etc/aipbx/probe.pass --insecure

Exit codes:
  0  registered over TLS
  2  TLS handshake failed (no listener, wrong port, firewall, version mismatch)
  3  certificate is not trusted for this name (what a phone will also refuse)
  4  connection and TLS fine, but no SIP response arrived
  5  registrar answered, refused the registration (403/404/...)
  6  challenge answered and credentials were rejected

Only the standard library is used, so it runs on the PBX itself.
"""

from __future__ import annotations

import argparse
import hashlib
import os
import re
import socket
import ssl
import sys
import uuid

DEFAULT_PORT = 5061
USER_AGENT = "AiPBX-TLS-Probe/1.0"
BODY_END = b"\r\n\r\n"


class StepError(Exception):
    """A step failed; carries the exit code the run should report."""

    def __init__(self, code: int, lines: list[str]):
        super().__init__(lines[-1] if lines else "failed")
        self.code = code
        self.lines = lines


# --------------------------------------------------------------------------
# TLS
# --------------------------------------------------------------------------

def _tlv(data: bytes, i: int) -> tuple[int, int, int]:
    """Read one DER element: returns (tag, value_start, value_end)."""
    tag = data[i]
    length = data[i + 1]
    i += 2
    if length & 0x80:
        count = length & 0x7F
        length = int.from_bytes(data[i:i + count], "big")
        i += count
    return tag, i, i + length


def _der_names(der: bytes) -> list[str]:
    """Pull the subjectAltName entries out of a DER certificate.

    Needed because getpeercert() returns an empty dict when verification is
    disabled, which is exactly the case where an operator wants the names.
    """
    names: list[str] = []
    try:
        _, start, end = _tlv(der, 0)          # Certificate SEQUENCE
        _, start, end = _tlv(der, start)      # tbsCertificate SEQUENCE
        i = start
        while i < end:
            tag, vstart, vend = _tlv(der, i)
            i = vend
            if tag != 0xA3:                   # [3] extensions
                continue
            _, ext_start, ext_end = _tlv(der, vstart)
            j = ext_start
            while j < ext_end:
                _, seq_start, seq_end = _tlv(der, j)   # Extension SEQUENCE
                j = seq_end
                oid_tag, oid_start, oid_end = _tlv(der, seq_start)
                if der[oid_start:oid_end] != b"\x55\x1d\x11":   # 2.5.29.17
                    continue
                k = oid_end
                while k < seq_end:
                    inner_tag, v1, v2 = _tlv(der, k)
                    k = v2
                    if inner_tag == 0x04:             # OCTET STRING
                        _, gn_start, gn_end = _tlv(der, v1)
                        g = gn_start
                        while g < gn_end:
                            gn_tag, vstart2, vend2 = _tlv(der, g)
                            g = vend2
                            if gn_tag == 0x82:            # dNSName
                                names.append(der[vstart2:vend2].decode("ascii", "replace"))
                            elif gn_tag == 0x87 and vend2 - vstart2 in (4, 16):
                                names.append(socket.inet_ntop(
                                    socket.AF_INET if vend2 - vstart2 == 4 else socket.AF_INET6,
                                    der[vstart2:vend2]))
    except (IndexError, ValueError):
        return names
    return names


def _san_names(cert: dict) -> list[str]:
    names = []
    for kind, value in cert.get("subjectAltName", ()):  # type: ignore[union-attr]
        if kind in ("DNS", "IP Address"):
            names.append(value)
    if not names:
        for rdn in cert.get("subject", ()):  # type: ignore[union-attr]
            for key, value in rdn:
                if key == "commonName":
                    names.append(value)
    return names


def _name_matches(host: str, names: list[str]) -> bool:
    host = host.lower().rstrip(".")
    for name in names:
        name = name.lower().rstrip(".")
        if name == host:
            return True
        if name.startswith("*.") and host.count(".") >= name.count("."):
            # *.example.com matches a.example.com but not a.b.example.com
            if host.split(".", 1)[1] == name[2:]:
                return True
        # An IP certificate may be written either way round
        try:
            if socket.inet_aton(name) == socket.inet_aton(host):
                return True
        except OSError:
            pass
    return False


def open_tls(host: str, port: int, timeout: float, insecure: bool) -> tuple[ssl.SSLSocket, list[str]]:
    """Connect and complete the TLS handshake. Raises StepError on failure."""
    lines: list[str] = []
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
    if insecure:
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        lines.append("! --insecure: certificate and name are reported but not enforced")

    try:
        raw = socket.create_connection((host, port), timeout=timeout)
    except OSError as exc:
        raise StepError(2, lines + [
            f"x connect {host}:{port}: {exc.strerror or exc}",
            "  nothing is listening on that port, or a firewall dropped it",
        ])

    try:
        sock = ctx.wrap_socket(raw, server_hostname=host)
    except ssl.SSLCertVerificationError as exc:
        raw.close()
        raise StepError(3, lines + [
            f"x TLS certificate rejected: {exc.verify_message or exc}",
            "  a phone that checks certificates will fail at this same step",
        ])
    except (ssl.SSLError, OSError) as exc:
        raw.close()
        raise StepError(2, lines + [
            f"x TLS handshake failed: {exc}",
            "  the port answers but does not speak TLS, or the versions do not overlap",
        ])

    version = sock.version() or "?"
    cipher = (sock.cipher() or ("?",))[0]
    lines.append(f"v TLS {version} {cipher}")
    try:
        cert = sock.getpeercert() or {}
    except ValueError:
        cert = {}
    names = _san_names(cert)
    if not names:
        try:
            names = _der_names(sock.getpeercert(binary_form=True) or b"")
        except (ValueError, ssl.SSLError):
            names = []
    if names:
        lines.append(f"  certificate names: {', '.join(names)}")
    if not names:
        lines.append("! certificate has no subjectAltName and no CN")
    elif not _name_matches(host, names):
        if insecure:
            lines.append(f"! certificate does not cover {host} (names: {', '.join(names)})")
            lines.append("  a phone that checks the name will refuse this registration")
        else:
            lines.append(f"x certificate does not cover {host} (names: {', '.join(names)})")
            lines.append("  phones reject this even when the certificate itself is valid")
            sock.close()
            raise StepError(3, lines)
    return sock, lines


# --------------------------------------------------------------------------
# SIP
# --------------------------------------------------------------------------

def read_message(sock: ssl.SSLSocket, buf: bytes, timeout: float) -> tuple[str, bytes]:
    """Read one complete SIP message (headers + Content-Length body)."""
    sock.settimeout(timeout)
    while True:
        head_end = buf.find(BODY_END)
        if head_end >= 0:
            head = buf[:head_end].decode("utf-8", "replace")
            match = re.search(r"^content-length\s*:\s*(\d+)", head, re.I | re.M)
            body_len = int(match.group(1)) if match else 0
            if len(buf) >= head_end + len(BODY_END) + body_len:
                whole_end = head_end + len(BODY_END) + body_len
                return buf[:whole_end].decode("utf-8", "replace"), buf[whole_end:]
        try:
            chunk = sock.recv(65535)
        except socket.timeout:
            raise StepError(4, ["x no SIP response within the timeout",
                                "  TLS completed, so this is SIP or firewall, not certificates"])
        except OSError as exc:
            raise StepError(4, [f"x connection broke while waiting for SIP: {exc}"])
        if not chunk:
            raise StepError(4, ["x peer closed the connection without a SIP response"])
        buf += chunk


def parse_auth_challenge(message: str, header: str) -> dict[str, str]:
    match = re.search(rf"^{header}\s*:\s*(.+)$", message, re.I | re.M)
    if not match:
        return {}
    value = match.group(1)
    params: dict[str, str] = {}
    for key, quoted, bare in re.findall(r"(\w+)\s*=\s*(?:\"([^\"]*)\"|([^,\s]+))", value):
        params[key.lower()] = quoted or bare
    return params


def status_of(message: str) -> tuple[int, str]:
    first = message.split("\r\n", 1)[0]
    match = re.match(r"SIP/2\.0\s+(\d{3})\s*(.*)", first)
    if not match:
        raise StepError(4, [f"x not a SIP response: {first[:120]}"])
    return int(match.group(1)), match.group(2)


def digest_response(method: str, uri: str, user: str, password: str,
                    chal: dict[str, str]) -> tuple[str, str, str, str, str]:
    algo = chal.get("algorithm", "MD5").upper().replace("-", "")
    hasher = hashlib.sha256 if algo == "SHA256" else hashlib.md5
    h = lambda s: hasher(s.encode("utf-8")).hexdigest()  # noqa: E731

    realm = chal.get("realm", "")
    nonce = chal.get("nonce", "")
    nc = "00000001"
    cnonce = uuid.uuid4().hex[:16]
    qop = "auth" if "auth" in [q.strip() for q in chal.get("qop", "").split(",") if q.strip()] else ""

    ha1 = h(f"{user}:{realm}:{password}")
    ha2 = h(f"{method}:{uri}")
    if qop:
        response = h(f"{ha1}:{nonce}:{nc}:{cnonce}:{qop}:{ha2}")
    else:
        response = h(f"{ha1}:{nonce}:{ha2}")
    return response, nc, cnonce, qop, algo


def build_register(host: str, port: int, user: str, local_ip: str, local_port: int,
                   call_id: str, cseq: int, branch: str, auth: str = "") -> str:
    uri = f"sip:{host}" if port == DEFAULT_PORT else f"sip:{host}:{port}"
    to_addr = f"sip:{user}@{host}"
    lines = [
        f"REGISTER {uri} SIP/2.0",
        f"Via: SIP/2.0/TLS {local_ip}:{local_port};branch={branch};rport;alias",
        f"Max-Forwards: 70",
        f"From: <{to_addr}>;tag={call_id[:8]}",
        f"To: <{to_addr}>",
        f"Call-ID: {call_id}",
        f"CSeq: {cseq} REGISTER",
        f"Contact: <sip:{user}@{local_ip}:{local_port};transport=tls>;expires=60",
        f"Expires: 60",
        f"User-Agent: {USER_AGENT}",
        f"Allow: REGISTER, INVITE, ACK, CANCEL, BYE, OPTIONS, INFO, MESSAGE",
    ]
    if auth:
        lines.append(auth)
    lines.append("Content-Length: 0")
    return "\r\n".join(lines) + "\r\n\r\n"


def probe(host: str, port: int, user: str, password: str, timeout: float = 8.0,
          insecure: bool = False, trace: list[str] | None = None) -> tuple[int, list[str]]:
    """Run the whole registration. Returns (exit code, report lines).

    Pass a list as `trace` to collect the raw SIP messages sent and received.
    """
    out: list[str] = [f"-> {user}@{host}:{port} over TLS"]
    try:
        sock, tls_lines = open_tls(host, port, timeout, insecure)
        out += tls_lines
        with sock:
            local_ip, local_port = sock.getsockname()[:2]
            call_id = f"{uuid.uuid4()}@{local_ip}"
            buf = b""

            request = build_register(host, port, user, local_ip, local_port,
                                     call_id, 1, f"z9hG4bK{uuid.uuid4().hex[:12]}")
            if trace is not None:
                trace.append(">>> " + request)
            sock.sendall(request.encode())
            reply, buf = read_message(sock, buf, timeout)
            if trace is not None:
                trace.append("<<< " + reply)
            code, reason = status_of(reply)
            out.append(f"  unauthenticated REGISTER -> {code} {reason}")

            if code == 200:
                out.append("ok registered without a challenge (registration is open)")
                out += _registration_detail(reply)
                return 0, out
            if code not in (401, 407):
                out.append("x registrar refused before authentication")
                out.append("  check the transport accepts this domain and the extension exists")
                return 5, out

            header = "WWW-Authenticate" if code == 401 else "Proxy-Authenticate"
            chal = parse_auth_challenge(reply, header)
            if not chal.get("realm") or not chal.get("nonce"):
                out.append(f"x {code} without a usable {header} challenge")
                return 5, out
            out.append(f"  challenge realm={chal.get('realm')} qop={chal.get('qop', 'none')} "
                       f"algorithm={chal.get('algorithm', 'MD5')}")

            if not password:
                out.append("! no password supplied: the challenge is the farthest a probe can go")
                out.append("  rerun with --password or --password-file to finish the registration")
                return 6, out

            uri = f"sip:{host}" if port == DEFAULT_PORT else f"sip:{host}:{port}"
            response, nc, cnonce, qop, algo = digest_response("REGISTER", uri, user, password, chal)
            fields = [
                f'Digest username="{user}"',
                f'realm="{chal["realm"]}"',
                f'nonce="{chal["nonce"]}"',
                f'uri="{uri}"',
                f'response="{response}"',
                f'algorithm={algo}',
            ]
            if qop:
                fields += [f"qop={qop}", f"nc={nc}", f'cnonce="{cnonce}"']
            if chal.get("opaque"):
                fields.append(f'opaque="{chal["opaque"]}"')
            hdr = "Authorization" if code == 401 else "Proxy-Authorization"
            auth = f"{hdr}: " + ", ".join(fields)

            request = build_register(host, port, user, local_ip, local_port,
                                     call_id, 2, f"z9hG4bK{uuid.uuid4().hex[:12]}", auth)
            if trace is not None:
                trace.append(">>> " + request)
            sock.sendall(request.encode())
            reply, buf = read_message(sock, buf, timeout)
            if trace is not None:
                trace.append("<<< " + reply)
            code, reason = status_of(reply)
            out.append(f"  authenticated REGISTER   -> {code} {reason}")

            if code == 200:
                out.append(f"ok REGISTER accepted over TLS for {user}@{host}")
                out += _registration_detail(reply)
                return 0, out
            if code in (401, 403):
                out.append("x credentials rejected, or the extension is not allowed on this transport")
                return 6, out
            out.append("x registrar refused the authenticated REGISTER")
            return 5, out
    except StepError as exc:
        return exc.code, out + exc.lines


def _registration_detail(reply: str) -> list[str]:
    lines = []
    for key in ("Expires", "Date", "Server"):
        match = re.search(rf"^{key}\s*:\s*(.+)$", reply, re.I | re.M)
        if match:
            lines.append(f"  {key}: {match.group(1).strip()}")
    contact = re.search(r"^Contact\s*:\s*(.+)$", reply, re.I | re.M)
    if contact:
        exp = re.search(r"expires=(\d+)", contact.group(1), re.I)
        if exp:
            lines.append(f"  contact bound for {exp.group(1)}s")
    return lines


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Register an extension over SIP/TLS and report what happened.")
    parser.add_argument("--host", required=True, help="the name the phone is configured with, e.g. sip-tls.s2insights.cloud")
    parser.add_argument("--port", type=int, default=DEFAULT_PORT)
    parser.add_argument("--user", required=True, help="extension number, e.g. 1001")
    parser.add_argument("--password", default="", help="extension password (or use --password-file)")
    parser.add_argument("--password-file", default="", help="read the extension password from this file")
    parser.add_argument("--timeout", type=float, default=8.0)
    parser.add_argument("--insecure", action="store_true",
                        help="do not enforce certificate trust or the name (lab only)")
    parser.add_argument("--trace", action="store_true",
                        help="print the raw SIP messages, secrets included")
    args = parser.parse_args(argv)

    password = args.password
    if args.password_file:
        try:
            with open(args.password_file, encoding="utf-8") as fh:
                password = fh.read().strip()
        except OSError as exc:
            print(f"x cannot read {args.password_file}: {exc}", file=sys.stderr)
            return 1

    trace: list[str] = [] if args.trace else None  # type: ignore[assignment]
    code, lines = probe(args.host, args.port, args.user, password, args.timeout,
                        args.insecure, trace)
    for line in lines:
        print(line)
    for raw in trace or []:
        print(raw)
    return code


if __name__ == "__main__":
    sys.exit(main())
