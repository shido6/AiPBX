# Health checks

**System → Health checks** watches the server and e-mails the chosen people when something stops
working — and again when it works again. Every morning it can send a **daily summary** for
managers: yesterday's calls and faxes and the state of every check.

## Checks

| Check | Default | Warning / failed when |
|-------|---------|------------------------|
| Asterisk | 5 min | Asterisk does not answer |
| Database | 5 min | MariaDB does not answer |
| Web portal | 5 min | the sign-in page does not answer 200 |
| Chat service | 5 min | `aipbx-chat` is not running |
| Trunks | 5 min | an active trunk is unreachable (qualify) or its registration is rejected/unregistered |
| Disk space | 1 h | warning from 85 %, failed from 95 % (`/`, `/var`, `/var/spool/asterisk`) |
| Memory | 15 min | warning under 10 % available, failed under 5 % |
| E-mail sending | 1 h | postfix not running (failed); messages queued for over an hour (warning) |
| Pending changes | 1 h | changes not applied for over an hour |
| Outgoing faxes | 1 h | faxes waiting to be sent for over an hour |
| DHCP / TFTP / FTP | 15 min | switched on, but the service does not run |
| Server address | 60 min | a stored address (server, phones, coturn, mDNS) is not this server's any more: see [Network settings](network-settings.md#when-the-servers-ip-address-changed) |
| Local AI service | 15 min | installed and enabled, but not running |
| TLS certificate | daily | warning 14 days before it ends, failed 3 days before |
| SIP call test | 1 h | With Sipfront keys, its latest test run is quoted instead of this check. Otherwise this server sends a real SIP OPTIONS to itself (SIPp when installed, otherwise a built-in probe): OK on 200, 401 or 407, warning when nothing answers. `tcpdump` records the exchange and the capture is parsed — methods, status codes and RTP — and the result says which source it used (`capture: pcap` or the engine's own output). |
| Daily backup | daily | the newest file in `/var/backups/aipbx-daily` is older than 26 h |
| Installation | daily | something `install.sh` sets up is missing or has the wrong owner/rights (helper, sudo rule, cron, TFTP folder, voicemail folders, masked vsftpd unit) |
| AiPBX version | daily | a newer release is out — information only, never an alarm |

Each check's **interval** can be changed on the page (off, 5 or 15 minutes, 1 or 6 hours, daily).
A check that does not apply to the server (no trunks, FTP switched off) shows *Not used*.

## Greg runs the checks with you

Type **health check** in the chat (or say *sağlık kontrolü*). Greg does the round of clicking for
you:

1. **Runs every check now**, as root, so the answer comes from this minute rather than from a
   stored result up to an hour old.
2. **Fixes what is safe to fix himself** — a service that is down is started again, one that is up
   but not answering is reloaded, a stuck mail queue is flushed:

   | Check | What Greg does |
   |-------|----------------|
   | Chat service | restart `aipbx-chat` |
   | Local AI service | restart `aipbx-ai` |
   | Asterisk | reload Asterisk |
   | Web portal | reload Apache |
   | E-mail | restart postfix when it is down, flush the queue when it is stuck |

   Everything goes through `aipbx-priv` (the only command the portal may run as root), is audited,
   and is written to Greg's captain's log. Nothing outside that list is touched — a full disk, an
   old backup, a carrier that drops calls are reported, not "fixed".
3. **Re-runs the checks** and says whether each attempt worked.
4. **Asks questions only when something actually failed.** A healthy system, or one with warnings
   only, is answered in one message. When something is still broken he asks five short questions —
   what is not working, which extensions or numbers, since when, whether audio flows, every call or
   some — because that part only a person knows.

The finished report is saved as it was at that moment (answers, check results, the call test and
what Greg did), listed under the checks on this page. A user who is not an administrator sees their
own answers and the verdict, without the server's technical messages. Type `cancel` (or *iptal*) to
stop — Greg still tells you which fixes he had already made.

The independent SIP call test is quoted with its evidence: the last Sipfront run when keys are
entered, otherwise a real `OPTIONS` from this server (SIPp when installed, a built-in probe when
not) with `tcpdump` recording the exchange. Without `tcpdump` the same test runs and reports what
the probe itself saw, naming that source instead of a capture.

## Alarms

- E-mails go to the **users** ticked on the page (in their own language) and to **other e-mail
  addresses**.
- Checks that run every 15 minutes or more often report a problem only after **two bad results in
  a row**, so a short hiccup stays quiet. Less frequent checks report the first bad result.
- While a problem lasts, the alarm is **repeated** after the chosen number of hours; a check that gets
  worse (warning → failed) is reported at once.
- When it works again, a **back to normal** e-mail follows.
- All problems found in one run go into one e-mail.

The texts are e-mail templates (*E-mail → Templates*: Health alarm, Health back to normal, Daily
summary). A server that is completely down cannot send an e-mail itself: watch it from outside
for that (e.g. a monitoring service that opens the sign-in page).

## Daily summary

At the chosen time (default 08:00), to the same people:

- yesterday's calls: total, incoming, outgoing, internal, answered, missed, busy, average and total
  talk time (counted per call, as on the call report), and faxes received / sent / failed;
- the state of every check now;
- the checks that raised an alarm yesterday.

*Send the summary now* on the page sends it at once (to check the recipients and the mail settings).

## Command line

```
sudo aipbx-selftest
```

runs every check at once and prints the results; the exit code is 1 when a check fails. It sends no
e-mail. `install.sh` runs it at the end of every installation and update, and CI runs it after a
fresh install.

## How it works

- `/etc/cron.d/aipbx` runs `/usr/local/bin/aipbx_health.php` every minute as root; it runs the checks
  whose interval has passed (most need root: Asterisk CLI, systemd, file owners), stores the
  results in `health_checks`, status changes in `health_events` (kept 30 days) and sends the
  e-mails. *Check now* on the page asks the next run to do every check.
- Checks only read; none of them changes anything on the server.
