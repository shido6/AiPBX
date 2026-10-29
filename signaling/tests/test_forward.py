"""Tests for the opt-in HEP fan-out (HOMER + VoIPmonitor at the same time).

The forward list is the only input, so these tests write it, send through a
real UDP socket and read what arrives. The capture loop's own tests cover the
receive path; this file covers the promise that a collector gets an unchanged
copy and that nothing in the file can take the local capture down.
"""

from __future__ import annotations

import json
import os
import socket
import sys
import tempfile
import time
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from aipbx_signaling.__main__ import Capture  # noqa: E402
from aipbx_signaling.forward import MAX_TARGETS, Forwarder  # noqa: E402

from test_daemon import FakeStore  # noqa: E402
from test_hep import INVITE, hep_packet  # noqa: E402


def _listener():
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.bind(("127.0.0.1", 0))
    sock.settimeout(2)
    return sock, sock.getsockname()[1]


class RecordingForwarder:
    def __init__(self):
        self.datagrams = []

    def forward(self, data):
        self.datagrams.append(data)
        return 1

    def report(self):
        return "forwarded=%d" % len(self.datagrams)


class TestForwarder(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp(prefix="aipbx-forward-")
        self.path = os.path.join(self.dir, "forward.json")
        self.targets = []
        self.socks = []

    def tearDown(self):
        for sock in self.socks:
            sock.close()
        if os.path.isfile(self.path):
            os.unlink(self.path)
        os.rmdir(self.dir)

    def _write(self, targets):
        with open(self.path, "w", encoding="utf-8") as fh:
            json.dump({"version": 1, "targets": targets}, fh)

    def _listener(self):
        sock, port = _listener()
        self.socks.append(sock)
        self.targets.append(port)
        return sock, port

    def test_an_unchanged_copy_reaches_the_listed_collector(self):
        sock, port = self._listener()
        self._write([{"name": "homer", "host": "127.0.0.1", "port": port}])
        packet = hep_packet(INVITE.encode())

        forwarder = Forwarder(path=self.path)
        self.assertEqual(1, forwarder.forward(packet, now=100.0))
        self.assertEqual(packet, sock.recvfrom(65535)[0])
        self.assertEqual(1, forwarder.sent)
        self.assertEqual(0, forwarder.errors)

    def test_both_targets_get_the_same_packet(self):
        homer, homer_port = self._listener()
        vm, vm_port = self._listener()
        self._write([
            {"name": "homer", "host": "127.0.0.1", "port": homer_port},
            {"name": "voipmonitor", "host": "127.0.0.1", "port": vm_port},
        ])
        packet = hep_packet(INVITE.encode())
        forwarder = Forwarder(path=self.path)
        self.assertEqual(2, forwarder.forward(packet, now=100.0))
        self.assertEqual(packet, homer.recvfrom(65535)[0])
        self.assertEqual(packet, vm.recvfrom(65535)[0])

    def test_the_file_is_reloaded_within_a_second_without_a_restart(self):
        first, first_port = self._listener()
        second, second_port = self._listener()
        self._write([{"host": "127.0.0.1", "port": first_port}])
        forwarder = Forwarder(path=self.path)
        self.assertEqual(1, forwarder.forward(b"one", now=100.0))
        self.assertEqual(b"one", first.recvfrom(65535)[0])

        self._write([{"host": "127.0.0.1", "port": second_port}])
        # Still the cached list inside the refresh window.
        self.assertEqual(1, forwarder.forward(b"same-window", now=100.5))
        self.assertEqual(b"same-window", first.recvfrom(65535)[0])
        self.assertEqual(1, forwarder.forward(b"two", now=102.0))
        self.assertEqual(b"two", second.recvfrom(65535)[0])

    def test_removing_the_file_stops_forwarding(self):
        sock, port = self._listener()
        self._write([{"host": "127.0.0.1", "port": port}])
        forwarder = Forwarder(path=self.path)
        self.assertEqual(1, forwarder.forward(b"first", now=100.0))
        self.assertEqual(b"first", sock.recvfrom(65535)[0])

        os.unlink(self.path)
        self.assertEqual(0, forwarder.forward(b"second", now=102.0))
        with self.assertRaises(socket.timeout):
            sock.recvfrom(65535)

    def test_an_empty_targets_list_is_an_explicit_stop(self):
        sock, port = self._listener()
        self._write([{"host": "127.0.0.1", "port": port}])
        forwarder = Forwarder(path=self.path)
        forwarder.forward(b"first", now=100.0)
        self.assertEqual(b"first", sock.recvfrom(65535)[0])

        self._write([])
        self.assertEqual(0, forwarder.forward(b"second", now=102.0))
        self.assertEqual(0, len(forwarder.targets))

    def test_a_malformed_file_never_raises_or_keeps_old_targets(self):
        sock, port = self._listener()
        self._write([{"host": "127.0.0.1", "port": port}])
        forwarder = Forwarder(path=self.path)
        forwarder.forward(b"first", now=100.0)
        self.assertEqual(b"first", sock.recvfrom(65535)[0])

        with open(self.path, "w", encoding="utf-8") as fh:
            fh.write("{not json")
        self.assertEqual(0, forwarder.forward(b"second", now=102.0))
        self.assertEqual([], forwarder.targets)

    def test_bad_targets_are_refused_and_duplicates_removed(self):
        sock, port = self._listener()
        self._write([
            {"host": "127.0.0.1", "port": port},
            {"host": "127.0.0.1", "port": port},
            {"host": "bad host", "port": port},
            {"host": "127.0.0.1", "port": 0},
            {"host": "127.0.0.1", "port": "9060"},
            {"host": "", "port": 9060},
            "not an object",
        ])
        forwarder = Forwarder(path=self.path)
        self.assertEqual(1, forwarder.forward(b"only-once", now=100.0))
        self.assertEqual([("127.0.0.1", port)], forwarder.targets)
        self.assertEqual(b"only-once", sock.recvfrom(65535)[0])

    def test_the_target_count_is_bounded(self):
        self._write([{"host": "127.0.0.%d" % i, "port": 9060}
                     for i in range(1, MAX_TARGETS + 5)])
        forwarder = Forwarder(path=self.path)
        forwarder._refresh(100.0)
        self.assertEqual(MAX_TARGETS, len(forwarder.targets))


class TestCaptureForwardsOnlyHepFromTheAllowedSource(unittest.TestCase):
    def test_a_hep_datagram_is_forwarded_before_it_is_stored(self):
        record = RecordingForwarder()
        capture = Capture(FakeStore(), source_check=True, forwarder=record)
        capture.allowed_source = "127.0.0.1"
        packet = hep_packet(INVITE.encode())
        capture.handle(packet, ("127.0.0.1", 4000))
        self.assertEqual([packet], record.datagrams)
        self.assertEqual(1, capture.stats["parsed"])

    def test_non_hep_junk_is_not_forwarded(self):
        record = RecordingForwarder()
        capture = Capture(FakeStore(), source_check=False, forwarder=record)
        capture.handle(b"not hep at all", ("127.0.0.1", 4000))
        self.assertEqual([], record.datagrams)
        self.assertEqual(1, capture.stats["dropped"])

    def test_a_wrong_source_is_not_forwarded(self):
        record = RecordingForwarder()
        capture = Capture(FakeStore(), source_check=True, forwarder=record)
        capture.allowed_source = "127.0.0.1"
        capture.handle(hep_packet(INVITE.encode()), ("192.0.2.99", 4000))
        self.assertEqual([], record.datagrams)
        self.assertEqual(1, capture.stats["wrong_source"])


class TestForwarderEndToEnd(unittest.TestCase):
    def test_a_received_datagram_reaches_the_listener(self):
        with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as fh:
            sock, port = _listener()
            try:
                json.dump({"targets": [{"host": "127.0.0.1", "port": port}]}, fh)
                path = fh.name
            finally:
                fh.close()
        try:
            record = Forwarder(path=path)
            capture = Capture(FakeStore(), source_check=False, forwarder=record)
            packet = hep_packet(INVITE.encode())
            capture.handle(packet, ("127.0.0.1", 4000))
            self.assertEqual(packet, sock.recvfrom(65535)[0])
            self.assertEqual(1, record.sent)
        finally:
            sock.close()
            os.unlink(path)


if __name__ == "__main__":
    unittest.main()
