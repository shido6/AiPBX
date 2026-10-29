<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/SipProbeService.php';

/**
 * The independent SIP test when Sipfront has no keys: a real OPTIONS on the
 * wire, with tcpdump recording it.
 *
 * The capture reader is the part that can lie, so it is pinned here against
 * hand-built pcaps: methods and status codes counted separately, RTP not
 * mistaken for SIP, a truncated record ending the file instead of corrupting
 * the answer, and anything that is not a packet reported as nothing at all.
 */
final class SipProbeServiceTest extends TestCase
{
    /** A pcap file: global header + one record per frame. */
    private function pcap(array $frames, int $link = 1): string
    {
        $out = pack('VvvVVV', 0xa1b2c3d4, 2, 4, 0, 0, 65535) . pack('V', $link);
        foreach ($frames as $i => $frame) {
            $out .= pack('VVVV', 1_800_000_000 + $i, 0, strlen($frame), strlen($frame)) . $frame;
        }
        return $out;
    }

    /** Ethernet + IPv4 + UDP carrying $payload. */
    private function frame(string $payload, int $srcPort = 40000, int $dstPort = 5060, int $link = 1): string
    {
        $udp = pack('nnnn', $srcPort, $dstPort, 8 + strlen($payload), 0) . $payload;
        $ip = pack('C', 0x45) . "\x00" . pack('n', 20 + strlen($udp)) . "\x00\x01\x00\x00\x40\x11\x00\x00"
            . inet_pton('127.0.0.1') . inet_pton('127.0.0.1');
        if ($link === 1) {
            $head = str_repeat("\x00", 12) . "\x08\x00";               // Ethernet
        } elseif ($link === 113) {
            $head = "\x00\x00\x00\x01\x00\x06" . str_repeat("\x00", 8) . "\x08\x00"; // Linux cooked
        } else {
            $head = '';
        }
        return $head . $ip . $udp;
    }

    private function analyse(string $bytes): array
    {
        $path = sys_get_temp_dir() . '/probe-' . bin2hex(random_bytes(4)) . '.pcap';
        file_put_contents($path, $bytes);
        try {
            return SipProbeService::analyzePcap($path);
        } finally {
            @unlink($path);
        }
    }

    public function testAnOptionsAndItsAnswerAreCountedAsTwoMessages(): void
    {
        $r = $this->analyse($this->pcap([
            $this->frame("OPTIONS sip:health@127.0.0.1 SIP/2.0\r\nCSeq: 1 OPTIONS\r\n\r\n"),
            $this->frame("SIP/2.0 200 OK\r\nCSeq: 1 OPTIONS\r\n\r\n", 5060, 40000),
        ]));
        $this->assertSame(2, $r['packets']);
        $this->assertSame(2, $r['sip_messages']);
        $this->assertSame(1, $r['methods']['OPTIONS']);
        $this->assertSame(1, $r['codes']['200']);
        $this->assertSame('pcap', $r['source']);
    }

    public function testAnotherServersMessagesAreCountedByTheirOwnCode(): void
    {
        $r = $this->analyse($this->pcap([
            $this->frame("INVITE sip:200@127.0.0.1 SIP/2.0\r\n\r\n", 41000, 5060),
            $this->frame("SIP/2.0 401 Unauthorized\r\n\r\n", 5060, 41000),
            $this->frame("REGISTER sip:127.0.0.1 SIP/2.0\r\n\r\n", 42000, 5060),
        ]));
        $this->assertSame(1, $r['methods']['INVITE'] ?? 0);
        $this->assertSame(1, $r['methods']['REGISTER'] ?? 0);
        $this->assertSame(1, $r['codes']['401'] ?? 0);
        $this->assertSame(0, $r['rtp'], 'no RTP in a signalling-only capture');
    }

    public function testRtpIsNotMistakenForSip(): void
    {
        $rtp = "\x80\x00\x00\x01" . pack('NN', 160, 1) . str_repeat("\x55", 160);
        $r = $this->analyse($this->pcap([$this->frame($rtp, 40002, 40000)]));
        $this->assertSame(1, $r['packets']);
        $this->assertSame(1, $r['rtp']);
        $this->assertSame(0, $r['sip_messages']);
    }

    public function testATruncatedRecordEndsTheFileInsteadOfCorruptingTheAnswer(): void
    {
        $bytes = $this->pcap([$this->frame("OPTIONS sip:health@127.0.0.1 SIP/2.0\r\n\r\n")]);
        // A record header that promises 4000 bytes, followed by 20.
        $bytes .= pack('VVVV', 1_800_000_100, 0, 4000, 4000) . str_repeat('x', 20);
        $r = $this->analyse($bytes);
        $this->assertSame(1, $r['packets'], 'the good packet still counts');
        $this->assertSame(1, $r['sip_messages']);
    }

    public function testAFileThatIsNotAPcapIsReportedAsNothing(): void
    {
        $this->assertSame('none', $this->analyse('not a capture at all, really not')['source'], 'a file that is not a pcap is not a capture');
        $this->assertSame('none', SipProbeService::analyzePcap('/nonexistent/capture.pcap')['source']);
    }

    public function testSllCaptureIsUnderstoodToo(): void
    {
        // Linux cooked (linktype 113): 16-byte header, no ethertype.
        $r = $this->analyse($this->pcap([
            $this->frame("OPTIONS sip:health@127.0.0.1 SIP/2.0\r\n\r\n", 40000, 5060, 113),
        ], 113));
        $this->assertSame(1, $r['sip_messages']);
        $this->assertSame(1, $r['methods']['OPTIONS']);
    }

    // ------------------------------------------------------- engine output

    public function testTheFirstStatusLineWins(): void
    {
        $this->assertSame(200, SipProbeService::codeFrom("SIP/2.0 200 OK\r\nVia: SIP/2.0/UDP 1.2.3.4\r\n"));
        $this->assertSame(401, SipProbeService::codeFrom(">> OPTIONS sip:health@127.0.0.1:5060\nSIP/2.0 401 Unauthorized\r\n"));
        $this->assertSame(503, SipProbeService::codeFrom("  Received 503 Service Unavailable"));
        $this->assertNull(SipProbeService::codeFrom('no response at all'));
    }

    public function testWithoutACaptureTheEngineOutputIsCountedAndSaysSo(): void
    {
        $r = SipProbeService::analyzeText(">> OPTIONS sip:health@127.0.0.1:5060\r\n<< SIP/2.0 200 OK\n");
        $this->assertSame('sipp-output', $r['source']);
        $this->assertSame(1, $r['methods']['OPTIONS']);
        $this->assertSame(1, $r['codes']['200']);
        $this->assertSame('probe-response', SipProbeService::analyzeText('', 'probe-response')['source']);
    }

    public function testAHeaderIsNotASecondMessage(): void
    {
        // What the built-in probe prints for a 401, verbatim.
        $out = ">> OPTIONS sip:health@127.0.0.1:5060 SIP/2.0\r\n"
            . "SIP/2.0 401 Unauthorized\r\nVia: SIP/2.0/UDP 127.0.0.1:40575\r\n"
            . "CSeq: 1 OPTIONS\r\nWWW-Authenticate: Digest realm=\"asterisk\"\r\n\r\n";
        $r = SipProbeService::analyzeText($out, 'probe-response');
        $this->assertSame(1, $r['methods']['OPTIONS'], 'CSeq: 1 OPTIONS is not a second OPTIONS');
        $this->assertSame(1, $r['codes']['401']);
        $this->assertSame(2, $r['sip_messages']);
    }

    // ------------------------------------------------------------- target

    public function testTheTargetIsAHostAndAPortNothingElse(): void
    {
        $this->assertSame(['127.0.0.1', 5060], SipProbeService::splitTarget('127.0.0.1:5060'));
        $this->assertSame(['pbx.example.com', 5060], SipProbeService::splitTarget('pbx.example.com'));
        $this->assertSame(['::1', 5080], SipProbeService::splitTarget('[::1]:5080'));
        $this->assertSame(['host', 5060], SipProbeService::splitTarget('host:99999'), 'a bad port falls back, the host stays');
        $this->assertSame(['127.0.0.1', 5060], SipProbeService::splitTarget(':0'));
    }

    public function testTheProbeAlwaysHasAnEngine(): void
    {
        $this->assertTrue(SipProbeService::available(), 'the built-in probe needs no package');
        $this->assertContains(SipProbeService::engine(), ['sipp', 'builtin']);
        $this->assertFalse(SipProbeService::sippAvailable('/nonexistent/sipp'));
        $this->assertStringContainsString('OPTIONS sip:health@', SipProbeService::scenario());
    }
}
