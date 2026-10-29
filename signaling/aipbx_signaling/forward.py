"""Fan-out of received HEP datagrams to additional collectors.

Asterisk's res_hep has ONE capture_address. When the portal's correlation
target is "both" (HOMER and VoIPmonitor on this server), Asterisk sends that
one stream to the built-in daemon and the daemon forwards an unchanged copy to
each collector listed in a small JSON file written by the portal.

The raw datagram is forwarded — not a re-encoding — so the collector side sees
exactly what Asterisk sent, capture password included. UDP is best-effort by
design: a collector that is down loses packets, exactly as it would if
Asterisk were sending to it directly. Nothing here may raise into the capture
loop.

The list is re-read at most once a second. A missing or malformed file means
"forward nowhere"; an operator's typo must not take the local capture down.
"""

from __future__ import annotations

import json
import logging
import os
import re
import socket
import time

LOG = logging.getLogger("aipbx-signaling.forward")

#: Written by HepSettingsService (web/src/services/ai/HepSettingsService.php).
DEFAULT_PATH = "/var/lib/aipbx/signaling/forward.json"

#: A file with more targets than this is not a configuration we wrote.
MAX_TARGETS = 8

#: Checking and parsing a tiny file once a second is cheap; per datagram is not.
REFRESH_SECONDS = 1.0

_HOST_RE = re.compile(r"^[A-Za-z0-9.:-]{1,253}$")


class Forwarder:
    """Forwards each datagram to the targets currently in the JSON file."""

    def __init__(self, path: str = None, sock: socket.socket = None):
        self.path = path or os.environ.get("AIPBX_SIGNALING_FORWARD", DEFAULT_PATH)
        self.sock = sock
        self.targets: list[tuple[str, int]] = []
        self.sent = 0
        self.errors = 0
        self.last_error = ""
        self._last_check = 0.0
        self._reported: set[tuple[str, int]] = set()
        self._last_file_error = ""

    def _refresh(self, now: float) -> None:
        if now - self._last_check < REFRESH_SECONDS:
            return
        self._last_check = now
        # The file is a few hundred bytes and this runs at most once a second,
        # so read it every time. Trusting mtime alone misses a rewrite inside
        # the same timestamp tick on a coarse filesystem — which is exactly
        # what a portal save can be.
        try:
            with open(self.path, "r", encoding="utf-8") as fh:
                doc = json.load(fh)
        except FileNotFoundError:
            if self.targets:
                LOG.info("forward list removed; no longer forwarding")
            self.targets = []
            self._reported.clear()
            self._last_file_error = ""
            return
        except (OSError, ValueError) as exc:
            # This is retried once a second: say it once per distinct error,
            # not 86,000 times a day.
            message = "%s: %s" % (type(exc).__name__, exc)
            if message != self._last_file_error:
                LOG.warning("cannot read forward list %s: %s", self.path, message)
                self._last_file_error = message
            self.targets = []
            self._reported.clear()
            return
        self._last_file_error = ""

        raw = doc.get("targets") if isinstance(doc, dict) else None
        targets: list[tuple[str, int]] = []
        for item in raw if isinstance(raw, list) else []:
            if not isinstance(item, dict):
                continue
            host = str(item.get("host", "")).strip()
            port = item.get("port")
            if not host or not _HOST_RE.match(host):
                LOG.warning("forward target refused: bad host %r", host)
                continue
            if isinstance(port, bool) or not isinstance(port, int) or not 1 <= port <= 65535:
                LOG.warning("forward target refused: bad port %r for %s", port, host)
                continue
            pair = (host, port)
            if pair not in targets:
                targets.append(pair)
        targets = targets[:MAX_TARGETS]
        if targets == self.targets:
            return
        self.targets = targets
        self._reported.clear()
        if self.targets:
            LOG.info("forwarding HEP copies to %s", ", ".join(
                "%s:%d" % pair for pair in self.targets))
        else:
            LOG.info("forward list has no usable targets")

    def forward(self, data: bytes, now: float = None) -> int:
        """Send one datagram to every target; returns how many sends succeeded."""
        self._refresh(time.monotonic() if now is None else now)
        if not self.targets:
            return 0
        if self.sock is None:
            try:
                self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            except OSError as exc:
                self.errors += 1
                self.last_error = str(exc)
                LOG.warning("cannot open forwarding socket: %s", exc)
                return 0
        sent = 0
        for target in self.targets:
            try:
                self.sock.sendto(data, target)
                self.sent += 1
                sent += 1
            except OSError as exc:
                self.errors += 1
                self.last_error = str(exc)
                if target not in self._reported:
                    # One line per target, not one per datagram.
                    self._reported.add(target)
                    LOG.warning("forward to %s:%d failed: %s", target[0], target[1], exc)
        return sent

    def report(self) -> str:
        return "forwarded=%d forward_errors=%d targets=%d" % (
            self.sent, self.errors, len(self.targets))
