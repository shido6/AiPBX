<?php
/**
 * The independent call test when Sipfront is not configured.
 *
 * A health check should not rely only on the PBX's own opinion of itself.
 * Sipfront gives that from outside when keys are entered; without an account,
 * this service sends a real SIP OPTIONS to the PBX over UDP and reports the
 * response code. OPTIONS is deliberately chosen: it is the standard "are you
 * alive" request, it creates no call, no CDR and no audio.
 *
 * Two engines, same transaction:
 *   - SIPp when it is installed (a real SIP stack, and what a site that wants
 *     full call tests already uses), otherwise
 *   - a built-in UDP OPTIONS probe written here, because SIPp is not packaged
 *     on every distribution AiPBX installs on — the test must not disappear
 *     just because a package is missing.
 * Either way tcpdump records the exchange (when it may), and the capture is
 * parsed: message counts by method and status code, plus RTP packets. When the
 * capture is refused — the web user has no CAP_NET_RAW — the report falls back
 * to the engine's own output and says so.
 *
 * The result is reported honestly: 200 means the port answers and speaks SIP;
 * 401/407 means it answers and demands credentials (still reachable, and normal
 * for an unknown user); a timeout means nothing answered. A full call still
 * needs a registered extension, which is what the Sipfront tests do.
 *
 * The binary and the target are never taken from the browser: settings only.
 */

class SipProbeService
{
    public const DEFAULT_BIN = 'sipp';
    public const DEFAULT_TARGET = '127.0.0.1:5060';

    public static function bin(): string
    {
        $bin = trim((string) getSystemSetting('sipp.bin', self::DEFAULT_BIN));
        return $bin !== '' ? $bin : self::DEFAULT_BIN;
    }

    /** Only host:port — never a URL, a flag or anything a shell could read. */
    public static function target(): string
    {
        $target = trim((string) getSystemSetting('sipp.target', self::DEFAULT_TARGET));
        return preg_match('/^[A-Za-z0-9.:\[\]-]{3,80}$/', $target) ? $target : self::DEFAULT_TARGET;
    }

    /** The engine that will run: 'sipp' when installed, 'builtin' otherwise. */
    public static function engine(): string
    {
        return self::sippAvailable() ? 'sipp' : 'builtin';
    }

    public static function sippAvailable(?string $bin = null): bool
    {
        return self::executable($bin ?? self::bin());
    }

    /** The probe always has an engine: the built-in one needs nothing. */
    public static function available(?string $bin = null): bool
    {
        return true;
    }

    private static function executable(string $bin): bool
    {
        if (str_contains($bin, '/')) {
            return is_file($bin) && is_executable($bin);
        }
        foreach (explode(':', getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') as $dir) {
            if ($dir !== '' && is_file($dir . '/' . $bin) && is_executable($dir . '/' . $bin)) {
                return true;
            }
        }
        return false;
    }

    /** The OPTIONS scenario, written to a throwaway file for each SIPp run. */
    public static function scenario(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="ISO-8859-1" ?>
<!DOCTYPE scenario SYSTEM "sipp.dtd">
<scenario name="AiPBX health OPTIONS">
  <send retrans="500">
    <![CDATA[
      OPTIONS sip:health@[remote_ip]:[remote_port] SIP/2.0
      Via: SIP/2.0/[transport] [local_ip]:[local_port];branch=[branch]
      From: <sip:health@[local_ip]:[local_port]>;tag=[call_number]
      To: <sip:health@[remote_ip]:[remote_port]>
      Call-ID: [call_id]
      CSeq: 1 OPTIONS
      Contact: <sip:health@[local_ip]:[local_port]>
      Max-Forwards: 70
      User-Agent: AiPBX-health
      Content-Length: 0

    ]]>
  </send>
  <recv response="200" optional="true"/>
  <recv response="401" optional="true"/>
  <recv response="403" optional="true"/>
  <recv response="404" optional="true"/>
  <recv response="405" optional="true"/>
  <recv response="407" optional="true"/>
  <recv response="500" optional="true"/>
  <recv response="503" optional="true"/>
  <timeout/>
</scenario>
XML;
    }

    /** The first SIP status code in a message or in SIPp's output. */
    public static function codeFrom(string $output): ?int
    {
        if (preg_match('/^\s*SIP\/2\.0\s+(\d{3})/mi', $output, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\b(?:received|response)[^0-9]{0,20}(\d{3})\b/i', $output, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Run one OPTIONS probe.
     * @return array{available:bool, ran:bool, code:?int, engine:string, capture:array, output:string}
     */
    public static function run(int $timeout = 8): array
    {
        $timeout = max(2, $timeout);
        $engine = self::engine();
        // 0755, not 0700: tcpdump drops to its own user after opening the
        // device and must still be able to create the capture inside here.
        $dir = sys_get_temp_dir() . '/aipbx-sipp-' . bin2hex(random_bytes(4));
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['available' => true, 'ran' => false, 'code' => null, 'engine' => $engine,
                'capture' => ['packets' => 0, 'sip_messages' => 0, 'methods' => [], 'codes' => [], 'rtp' => 0, 'source' => 'none'],
                'output' => ''];
        }
        $pcap = $dir . '/probe.pcap';
        $capture = self::startCapture($pcap);
        if ($engine === 'sipp') {
            $scenario = $dir . '/options.xml';
            @file_put_contents($scenario, self::scenario());
            $out = self::runSipp($scenario, $dir, $timeout);
        } else {
            $out = self::runBuiltin($timeout, $capture ? $pcap : null);
        }
        self::stopCapture($capture);

        $captureStats = self::analyzePcap($pcap);
        if ($captureStats['sip_messages'] === 0) {
            // No capture (tcpdump missing or unprivileged): fall back to what
            // the engine itself saw. The report says which source was used.
            $captureStats = self::analyzeText($out, $engine === 'sipp' ? 'sipp-output' : 'probe-response');
        }
        self::cleanup($dir);
        return [
            'available' => true,
            'ran' => true,
            'code' => self::codeFrom($out),
            'engine' => $engine,
            'capture' => $captureStats,
            'output' => mb_substr($out, 0, 4000),
        ];
    }

    /** SIPp: one OPTIONS, one response, its own output captured. */
    private static function runSipp(string $scenario, string $dir, int $timeout): string
    {
        // -trace_msg writes what SIPp received to <scenario>_*_messages.log;
        // its stdout is statistics only, so without this a perfectly good 200
        // would be reported as "no answer".
        $cmd = [self::bin(), '-sf', $scenario, '-m', '1', '-r', '1', '-rp', '1000',
            '-timeout', $timeout . 's', '-nostdin', '-trace_msg', self::target()];
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        if (!is_resource($proc)) {
            return '';
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $start = time();
        while (true) {
            $status = proc_get_status($proc);
            $out .= (string) stream_get_contents($pipes[1]);
            $out .= (string) stream_get_contents($pipes[2]);
            if (!$status['running'] || time() - $start > $timeout + 4) {
                if ($status['running']) {
                    proc_terminate($proc, 9);
                }
                break;
            }
            usleep(100000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $out .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        foreach (glob($dir . '/*_messages.log') ?: [] as $log) {
            $out .= "\n" . (string) file_get_contents($log);
        }
        return $out;
    }

    /**
     * The built-in probe: a real SIP OPTIONS datagram over UDP, no packages.
     *
     * The first attempt is retransmitted like a user agent would, for two
     * reasons: UDP loses packets, and tcpdump needs a moment after start-up
     * before its filter sees anything. So the probe watches its own capture:
     * as long as the capture holds no SIP message, it sends again (same
     * transaction, 250 ms apart, bounded) — once a message is recorded, the
     * exchange is in the pcap and the loop stops.
     *
     * @param string|null $pcap the capture to watch, null when there is none
     */
    private static function runBuiltin(int $timeout, ?string $pcap = null): string
    {
        [$host, $port] = self::splitTarget(self::target());
        $out = '';
        $code = null;
        $deadline = microtime(true) + max(2, $timeout);
        $attempts = 0;
        // One transaction, retransmitted: same Call-ID, branch and tag, so the
        // PBX sees a retransmission rather than a new request each time.
        $callId = bin2hex(random_bytes(8)) . '@' . $host;
        $branch = 'z9hG4bK' . bin2hex(random_bytes(6));
        $tag = bin2hex(random_bytes(4));
        do {
            $attempts++;
            $r = self::sendOptions($host, $port, min(1.0, max(0.2, $deadline - microtime(true))), $callId, $branch, $tag);
            if ($r['text'] !== '') {
                $out .= $r['text'];
            }
            if ($code === null && $r['code'] !== null) {
                $code = $r['code'];
            }
            if ($pcap !== null) {
                clearstatcache(true, $pcap);
                if (self::analyzePcap($pcap)['sip_messages'] > 0) {
                    break; // the capture is live: this exchange is recorded
                }
            } elseif ($code !== null) {
                break; // no capture to feed: one answer is enough
            }
            usleep(250000);
        } while ($attempts < 6 && microtime(true) < $deadline);
        return $out;
    }

    /**
     * One OPTIONS transaction: send, wait for the response.
     * @return array{code:?int, text:string}
     */
    private static function sendOptions(string $host, int $port, float $wait, string $callId, string $branch, string $tag): array
    {
        $sock = @stream_socket_client('udp://' . $host . ':' . $port, $errno, $errstr, 3);
        if (!is_resource($sock)) {
            return ['code' => null, 'text' => 'connect failed: ' . ($errstr !== '' ? $errstr : (string) $errno)];
        }
        $local = (string) stream_socket_get_name($sock, false);
        if ($local === '') {
            $local = '127.0.0.1:0';
        }
        $msg = "OPTIONS sip:health@{$host}:{$port} SIP/2.0\r\n"
            . "Via: SIP/2.0/UDP {$local};branch={$branch}\r\n"
            . "Max-Forwards: 70\r\n"
            . "From: <sip:health@{$host}>;tag={$tag}\r\n"
            . "To: <sip:health@{$host}:{$port}>\r\n"
            . "Call-ID: {$callId}\r\n"
            . "CSeq: 1 OPTIONS\r\n"
            . "Contact: <sip:health@{$local}>\r\n"
            . "User-Agent: AiPBX-health\r\n"
            . "Accept: application/sdp\r\n"
            . "Content-Length: 0\r\n\r\n";
        $text = '>> OPTIONS sip:health@' . $host . ':' . $port . "\r\n";
        if (@fwrite($sock, $msg) === false) {
            fclose($sock);
            return ['code' => null, 'text' => $text . '<< (send failed)' . "\n"];
        }
        stream_set_timeout($sock, (int) max(1, ceil($wait)), (int) ((max(1, ceil($wait)) - floor($wait)) * 1000000));
        $resp = '';
        $until = microtime(true) + $wait;
        while (microtime(true) < $until) {
            $chunk = @fread($sock, 8192);
            if (is_string($chunk) && $chunk !== '') {
                $resp = $chunk;
                break;
            }
            if (!empty(stream_get_meta_data($sock)['timed_out'])) {
                break;
            }
        }
        fclose($sock);
        if ($resp === '') {
            return ['code' => null, 'text' => $text . '<< (no response)' . "\n"];
        }
        return ['code' => self::codeFrom($resp), 'text' => $text . $resp];
    }

    /** @return array{0:string,1:int} host, port — from a validated host:port */
    public static function splitTarget(string $target): array
    {
        $pos = strrpos($target, ':');
        $host = $pos === false ? $target : trim(substr($target, 0, $pos), '[]');
        $port = $pos === false ? 5060 : (int) substr($target, $pos + 1);
        if ($port < 1 || $port > 65535) {
            $port = 5060;
        }
        if ($host === '') {
            $host = '127.0.0.1';
        }
        return [$host, $port];
    }

    /**
     * Summarise a pcap: SIP messages by method and status code, plus RTP.
     * A deliberately small reader — pcap (not pcapng), Ethernet/SLL/null
     * link layers, IPv4, UDP. It never trusts lengths past the record.
     *
     * @return array{packets:int, sip_messages:int, methods:array, codes:array, rtp:int, source:string}
     */
    public static function analyzePcap(string $path): array
    {
        $empty = ['packets' => 0, 'sip_messages' => 0, 'methods' => [], 'codes' => [], 'rtp' => 0, 'source' => 'none'];
        if (!is_file($path) || filesize($path) < 24) {
            return $empty;
        }
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return $empty;
        }
        $gh = (string) fread($fh, 24);
        $magic = substr($gh, 0, 4);
        $le = $magic === "\xd4\xc3\xb2\xa1";
        if (!$le && $magic !== "\xa1\xb2\xc3\xd4") {
            fclose($fh);
            return $empty; // not a pcap (pcapng and plain files are not claims of a capture)
        }
        $link = unpack($le ? 'V' : 'N', substr($gh, 20, 4))[1] ?? 1;
        $packets = 0;
        $rtp = 0;
        $methods = [];
        $codes = [];
        while (strlen($rh = (string) fread($fh, 16)) === 16) {
            $h = unpack($le ? 'Vts/Vtu/Vincl/Vorig' : 'Nts/Ntu/Nincl/Norig', $rh);
            $len = (int) $h['incl'];
            if ($len <= 0 || $len > 262144) {
                break;
            }
            $data = (string) fread($fh, $len);
            if (strlen($data) < $len) {
                break;
            }
            $packets++;
            $udp = self::udpPayload($data, $link);
            if ($udp === null) {
                continue;
            }
            [$srcPort, $dstPort, $payload] = $udp;
            $first = ltrim($payload);
            if (preg_match('/^(OPTIONS|INVITE|REGISTER|ACK|BYE|CANCEL|MESSAGE|INFO|UPDATE|PRACK|SUBSCRIBE|NOTIFY|PUBLISH|REFER)\s/', $first, $m)) {
                $methods[$m[1]] = ($methods[$m[1]] ?? 0) + 1;
            } elseif (preg_match('/^SIP\/2\.0\s+(\d{3})/', $first, $m)) {
                $codes[$m[1]] = ($codes[$m[1]] ?? 0) + 1;
            } elseif ($srcPort !== 5060 && $dstPort !== 5060 && strlen($payload) >= 12
                && (ord($payload[0]) & 0xC0) === 0x80) {
                $rtp++;
            }
        }
        fclose($fh);
        return [
            'packets' => $packets,
            'sip_messages' => array_sum($methods) + array_sum($codes),
            'methods' => $methods,
            'codes' => $codes,
            'rtp' => $rtp,
            'source' => 'pcap',
        ];
    }

    /** Fallback when tcpdump could not run: count what the engine itself saw. */
    public static function analyzeText(string $output, string $source = 'sipp-output'): array
    {
        $methods = [];
        $codes = [];
        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            // Start lines only: "CSeq: 1 OPTIONS" and a ">> "/"<< " prefix are
            // not extra messages, and must not inflate the count.
            $line = ltrim($line, " \t>|<");
            if (preg_match('/^(OPTIONS|INVITE|REGISTER|ACK|BYE|CANCEL|MESSAGE|INFO|UPDATE|PRACK|SUBSCRIBE|NOTIFY|PUBLISH|REFER)\s/', $line, $m)) {
                $methods[$m[1]] = ($methods[$m[1]] ?? 0) + 1;
            } elseif (preg_match('/^SIP\/2\.0\s+(\d{3})/', $line, $m)) {
                $codes[$m[1]] = ($codes[$m[1]] ?? 0) + 1;
            }
        }
        return [
            'packets' => 0,
            'sip_messages' => array_sum($methods) + array_sum($codes),
            'methods' => $methods,
            'codes' => $codes,
            'rtp' => 0,
            'source' => $source,
        ];
    }

    /** @return array{0:int,1:int,2:string}|null src port, dst port, UDP payload */
    private static function udpPayload(string $frame, int $link): ?array
    {
        $off = match ($link) {
            1 => 14,      // Ethernet
            113 => 16,    // Linux cooked
            276 => 20,    // Linux cooked v2
            0 => 4,       // BSD loopback
            default => -1,
        };
        if ($off < 0 || strlen($frame) < $off + 20) {
            return null;
        }
        $etherType = $link === 1 ? unpack('n', substr($frame, 12, 2))[1] : null;
        $ipOff = $off;
        $version = ord($frame[$ipOff]) >> 4;
        if ($version !== 4) {
            return null;
        }
        if ($link === 1 && $etherType !== 0x0800) {
            return null;
        }
        $ihl = (ord($frame[$ipOff]) & 0x0F) * 4;
        if ($ihl < 20 || strlen($frame) < $ipOff + $ihl + 8) {
            return null;
        }
        if (ord($frame[$ipOff + 9]) !== 17) {
            return null;
        }
        $u = $ipOff + $ihl;
        $src = unpack('n', substr($frame, $u, 2))[1];
        $dst = unpack('n', substr($frame, $u + 2, 2))[1];
        return [$src, $dst, substr($frame, $u + 8)];
    }

    /**
     * Start tcpdump on the capture interface. It needs root or CAP_NET_RAW;
     * when that is refused (the web user), the probe still runs and the
     * analysis falls back to what the engine saw.
     * @return array|null process + pipes
     */
    private static function startCapture(string $pcap): ?array
    {
        $bin = trim((string) getSystemSetting('capture.tcpdump_bin', 'tcpdump'));
        $path = str_contains($bin, '/') ? $bin : trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
        if ($path === '' || !is_executable($path)) {
            return null;
        }
        [, $port] = self::splitTarget(self::target());
        // tcpdump drops privileges to its own user after opening the device, so
        // it cannot create a file in our 0700 directory: create it writable.
        @touch($pcap);
        @chmod($pcap, 0666);
        $cmd = [$path, '-i', 'any', '-n', '-s', '0', '-U', '-w', $pcap, 'udp', 'port', (string) $port];
        $proc = @proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
        if (!is_resource($proc)) {
            return null;
        }
        // Wait for tcpdump's own "listening on" line, not a fixed guess: the
        // header is written when the file opens, but the filter is installed
        // later, and a packet sent in between is simply not in the capture.
        stream_set_blocking($pipes[2], false);
        $note = '';
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $note .= (string) stream_get_contents($pipes[2]);
            if (str_contains($note, 'listening on')) {
                break;
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            usleep(20000);
        }
        // "listening on" is printed when the device is open; the BPF filter is
        // installed just after it, so give it a moment before the first packet.
        usleep(400000);
        clearstatcache(true, $pcap);
        if (!is_file($pcap)) {
            proc_terminate($proc, 15);
            @fclose($pipes[2]);
            proc_close($proc);
            return null; // tcpdump never opened the capture (no permission)
        }
        return ['proc' => $proc, 'pipes' => $pipes];
    }

    /** @param array|null $capture */
    private static function stopCapture(?array $capture): void
    {
        if (!$capture) {
            return;
        }
        $status = proc_get_status($capture['proc']);
        if ($status['running']) {
            proc_terminate($capture['proc'], 15);
        }
        if (isset($capture['pipes'][2])) {
            @fclose($capture['pipes'][2]);
        }
        proc_close($capture['proc']);
        usleep(150000);
    }

    private static function cleanup(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
