# Signaling capture

AiPBX ships an optional signaling pipeline: what crosses the SIP stack can
be captured, kept on this server and read by the dialplan editor's Echo box.
Capture is opt-in: `asterisk-config/hep.conf` ships with `enabled = no`, so
a normal installation captures nothing and pays no overhead.

Nothing on this path can affect a call. The capture is a UDP datagram that
Asterisk sends and moves on; the daemon writes to the database in batches
and survives the database going away (events are lost, capture continues).

## The chain

```
Asterisk (res_hep, res_hep_pjsip, res_hep_rtcp)
    │  HEPv3 over UDP, loopback only
    ▼
aipbx-signaling (systemd: aipbx-signaling.service, 127.0.0.1:9061)
    │  parsed -> batched rows
    ▼
MariaDB: pbx_signaling_events
    │  read
    ▼
AI → Dialplan editor (Echo box)
```

**Asterisk side** — `hep.conf`:

```
[general]
enabled = no
capture_address = 127.0.0.1:9061
capture_password = aipbx
capture_id = 1001
```

`modules.conf` loads `res_hep.so`, `res_hep_pjsip.so`, `res_hep_rtcp.so`.
Turning capture on: `enabled = yes` (one word), reload Asterisk. The address
stays loopback unless you deliberately point it elsewhere — a capture port
open to a network turns a PBX into a free SIP sniffer for anyone who finds
it.

**Daemon side** — `python -m aipbx_signaling` (installed by `install.sh`,
and therefore by `aipbx-update` on every upgrade, as
`aipbx-signaling.service`). Reads `/etc/ai-pbx.env` for database credentials
(the same file the portal reads; the `asterisk` group owns it). One setting
comes from `sys_settings` (default when absent, clamped 1..3650):

| setting                    | meaning                                 |
|----------------------------|-----------------------------------------|
| `signaling_retention_days` | delete events older than this (1–3650)  |

A Signaling page that edits this in the portal is a follow-up; today the
setting is written to `sys_settings` directly, and whether Asterisk captures
at all is the `hep.conf` switch above.

## Correlation tools: HOMER, VoIPmonitor, or both

Asterisk's `res_hep` has one `capture_address`. Under *AI → Echo* the
administrator can point it at the built-in daemon, at a local HOMER or
VoIPmonitor on UDP 9060, at an external collector, or at **both** HOMER and
VoIPmonitor, on this server or another host.

In `both` mode `hep.conf` still points at `127.0.0.1:9061`; the daemon stores
the stream as usual and forwards an **unchanged copy** of each HEPv3 datagram
to the targets listed in:

```
/var/lib/aipbx/signaling/forward.json     (www-data, 0640)
```

That file is written by `HepSettingsService` and read by the daemon at most
once a second, so changing the target needs no service restart. The raw
datagram is forwarded, not re-encoded, so the collector sees exactly what
Asterisk sent, including `capture_password`. UDP is best-effort: a collector
that is down loses packets, exactly as it would if Asterisk were sending to
it directly. Removing the target (or choosing any other mode) deletes the
file, and forwarding stops within a second.

### Watching the collector

*Admin → Health checks* includes **SIP correlation collector** (every 15
minutes by default). It watches exactly what Echo is configured for: HOMER in
`homer` mode, VoIPmonitor in `voipmonitor` mode, an external target in
`external` mode, and both targets in `both` mode. The built-in daemon and
`off` are reported as *not used* — there is no external web tier to ask.

When a UI address is set, the check sends a `HEAD` request to it and reads the
status line. A sign-in page, a redirect or a 401 counts as **working**; a
5xx is a **warning**; no answer is **failed** and raises the normal health
alarm. The probe sends no credentials and does not verify the certificate
(a collector on the LAN often has a self-signed one): it proves that something
answers, not who it is. Without a UI address only a TCP listener can be
proven; because HEP is usually UDP, a closed port is reported as information
rather than a false alarm. Echo shows the stored result, and Greg quotes it —
he never claims to have queried the collector himself. Greg can also walk an
administrator through installing HOMER or enabling VoIPmonitor's HEP listener,
using the same commands the Echo page shows; he cannot run them or change the
remote machine.

The local rows are still written in `both` mode, so the Echo box and Greg's
SIP error counts keep working whichever collectors are configured. Greg is
told which collectors and UIs are in use and that he cannot query them; when
a Call-ID needs a call flow he points the administrator at the right UI.

Two collectors on one host need different UDP ports — the card defaults to
HOMER 9060 and VoIPmonitor 9062. Neither product is installed or started by
the portal; both must already be running.

### Reaching a centralized collector

When one collector serves several PBXs, its SSH login is the key to all of
them: use **keys only** — `PasswordAuthentication no`,
`KbdInteractiveAuthentication no`, `PubkeyAuthentication yes` — and copy the
key before switching passwords off. Greg asks for that key before walking
through a remote collector install and never asks for a password. The daily
*SSH key-only login* health check warns while a password path is still open;
the commands and the lockout-safe order are in
[security.md](security.md#ssh).

**Cost.** This is the most expensive choice: each collector processes the full
stream, so their combined CPU, RAM and disk use is roughly double one
collector's. On a small PBX, put one or both collectors on another host; the
PBX then only forwards packets (the daemon's own added work is one UDP receive
and two sends per datagram). HEP is plain UDP, so use loopback, a private
network or a trusted host.

**Measured before the choice.** The card reads the host's CPU cores, one-minute
load, `MemAvailable` and free space on `/var/lib/aipbx` (taking a cgroup quota
into account when the portal runs in a container) before it shows the target
picker. It gives two verdicts: one local collector, and both. The thresholds
are planning gates, not a benchmark — HOMER 7's guide sizes its ingest,
database and web components at 8 vCPU/16 GB/100 GB, 4/16/1 TB and 2/8/40,
while VoIPmonitor recommends 8–16 GB for a small full deployment. Validate the
real message rate, retention and disk growth on the actual installation. Greg
receives the same measured capacity in his prompt, so he does not recommend
installing both locally when the host is below the floor.
Sources: [HOMER 7 setup guide](https://github.com/sipcapture/homer/wiki/homer-seven-setup),
[VoIPmonitor scaling](https://www.voipmonitor.org/doc/Scaling),
[VoIPmonitor hardware](https://www.voipmonitor.org/doc/Hardware).

**Storage** — `pbx_signaling_events`: `ts, call_id, method, proto,
transport, src, dst, from_uri, to_uri, cseq, status_code, first_line, line,
capture_id, rtcp_ssrc, rtcp_lost, rtcp_fraction_lost, rtcp_jitter`. Message
text is kept in `first_line`/`line` (bounded); phones still show in
`from_uri`. Rows are pruned by the retention setting.

## Call quality (RTCP)

`res_hep_rtcp` sends every Sender and Receiver Report through the same
capture, so the numbers that describe media quality are already here. The
daemon keeps the **worst report block** of each datagram (a call is as good as
its lossiest stream):

- `rtcp_fraction_lost` — RFC 3550's 8-bit fraction, 255 = 100 %. 13 is about
  5 %.
- `rtcp_lost` — cumulative packets lost for that stream, as reported.
- `rtcp_jitter` — `ia_jitter` in **RTP timestamp units**. The sample rate is
  not in the report, so it is not converted to milliseconds; a conversion
  would be a guess that changes with the codec.
- `rtcp_ssrc` — the source SSRC of the worst block.

**No audio is captured or analysed.** Greg sees counters, not speech. He is
told plainly that he did not listen and cannot listen in.

A call is **poor** when two RTCP reports in the last 15 minutes are at 5 %
loss or more, **fair** with any loss above 1 %, otherwise **good**. One spike
is not enough. This is deliberately conservative: a brief burst on a
congested Wi-Fi link should not move a carrier.

*Admin → Health checks* has **Call quality (RTCP)** every 5 minutes: a
warning for poor calls, a failure when at least three calls and half of the
measured calls are poor (the loss is then network- or carrier-wide). The
check is read-only.

**The one automatic change.** The `aipbx_quality.php` cron runs every five
minutes. When a poor call's media peer is a trunk (`pbx_trunks.ip_address`),
and an outbound route has another trunk behind it, the lossy trunk moves to
the **end of the chain** and the outbound dialplan is regenerated. New calls
therefore try the other trunk first; **live calls keep their channel**. The
change is written to the audit log and Greg's captain's log, announced as a
notice, and can be reordered back on *Outbound routes*. It is limited to one
demotion per trunk per six hours, is skipped while another outbound change is
waiting for Apply, and rolls the route order back if the dialplan reload
fails. It can be switched off on *AI → VoIP Engineer* ("Let him move a lossy
trunk to the backup position"); switched off, he still records the evidence and
raises the notice, he just leaves the route alone.

There is no safe automatic change when the lossy peer is a phone, Wi-Fi or
the local network, when the trunk is already last, or when every trunk loses
packets. Those cases become a notice that names the call, the peer and the
loss — a human or the carrier is required.

### The evidence trail

Before the raw rows can be pruned, every incident is copied to
`pbx_quality_evidence`: the sampled RTCP counters (time, peer, SSRC, fraction
lost, cumulative loss, jitter), the thresholds applied, the matched trunk, the
action taken, and a dedupe key so the five-minute run stores one record per
call/window/action. The record names its own source:
`res_hep_rtcp -> aipbx-signaling -> pbx_signaling_events (proto='rtcp')`.

Greg reads the last five incidents in his prompt and must cite `evidence #N`,
the peer and time window, the report count and what was changed — the trail a
VoIP engineer writes on a ticket. Notices and alarm e-mails carry the same
compact reference.

```
aipbx_quality.php --report=24      # incidents, with ids
aipbx_quality.php --evidence=12    # one record: samples, thresholds, action
```

Evidence is kept for 180 days (`call_quality.evidence_days`, 7–3650) and
pruned by the quality cron. The live rows keep their own, shorter retention.

**Coverage.** Quality sensing needs local RTCP rows, so it works with the
built-in capture (`builtin`) and with both collectors (`both`). In `homer`,
`voipmonitor` or `external` modes Asterisk sends RTCP straight to the
collector and the portal has nothing to read; the health check says so
instead of claiming the lines are healthy.

## The Echo box (AI → Dialplan editor)

The page's "Ask about the phone system" box answers from this same data:
counts of calls (CDRs), captured SIP messages in the last 24 h, and SIP
error responses grouped by status code (`400/403/404/480/486/500/503/603`
…). The queries are fixed SELECTs — see
`web/src/services/ai/EchoReporter.php`. Nothing writes, and no packet text
leaves this server.

## What stays on this server

Captured events never leave the machine: `pbx_signaling_events` is read by
the dialplan editor's Echo box, locally.
Shipping captures to a third-party cloud is exactly the kind of connector
the planned plugin system should carry — not core.
