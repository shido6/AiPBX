# Security

## Firewall

The installer configures **firewalld** (ufw is disabled) and opens the ports listed in
[INSTALL.md](../INSTALL.md#34-firewall-firewalld). Manage them on **Firewall** or with `firewall-cmd`.

**Recommended:** SIP (5060/udp+tcp, 5061/tcp) is scanned constantly on the internet. If only your
carrier and local phones use SIP, remove the open ports and allow the sources instead:

```bash
for src in 203.0.113.10 10.0.0.0/8; do            # carrier IP, internal networks
  for pp in 5060/udp 5060/tcp 5061/tcp; do
    firewall-cmd --permanent --add-rich-rule="rule family=\"ipv4\" source address=\"$src\" port port=\"${pp%/*}\" protocol=\"${pp#*/}\" accept"
  done
done
for pp in 5060/udp 5060/tcp 5061/tcp; do firewall-cmd --permanent --remove-port=$pp; done
firewall-cmd --reload
```

The web softphone and the mobile apps connect over 443 and are not affected. RTP (10000–20000/udp) must
stay open for carrier audio, and UDPTL (4100–4999/udp) for T.38 fax. The **Firewall** page shows and removes these rules.

Asterisk's own TLS WebSocket (8089) listens on loopback only: browsers and the mobile apps use 443
`/ws`, which goes through the nginx/Apache edge. Earlier versions opened 8089 publicly; installing or
updating closes it.

## SSH

**SSH is left as the operating system configured it.** AiPBX never rewrites `sshd_config`: one wrong
line there locks you out of the machine, and a PBX is often the only way in. The installer and the
daily **SSH key-only login** check warn; you change it.

A **centralized installation** — one HOMER or VoIPmonitor collector, or one administrator, for
several PBXs — must log in remotely with **keys only**. A password on a shared host is one
credential that opens every PBX behind it. Keys are recommended for a single PBX too; for a central
host they are required.

On your workstation:

```bash
ssh-keygen -t ed25519 -C aipbx
ssh-copy-id -i ~/.ssh/id_ed25519.pub user@collector
ssh -o PasswordAuthentication=no user@collector true   # proves the key alone works
```

Only after the last command succeeds, on the collector, add
`/etc/ssh/sshd_config.d/99-aipbx-keys.conf`:

```
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
```

then `sudo sshd -t && sudo systemctl reload ssh`. Keep your current session open until a second
key-only login succeeds in a new one. The same order works for the PBX itself.

**Admin → Health checks → SSH key-only login** (daily) reads the effective configuration with
`sshd -T` and warns while password or keyboard-interactive login is accepted, fails if public keys
are refused, reports information when root may still log in with a key, and says so when the
configuration cannot be read. It only reports. Limiting port 22 to trusted networks remains
recommended on top of keys.

## fail2ban

Jails: `asterisk` (failed SIP registrations/auth), `aipbx-web` (portal logins, using the real client IP
from the PROXY protocol) and `sshd`. Ban time, find time, max retries and the ignore list are set on the
**fail2ban** page; the portal writes them to `/etc/fail2ban/jail.d/zz-ai-pbx.local`, which is read last so
it overrides the installer's defaults. Add your internal networks and carrier to the ignore list so a
misconfigured phone cannot lock them out.

- **Failed registration alarm:** The [Failed registrations](health.md#checks) health check monitors
  Asterisk's security log and alarms in the bell and by e-mail when failed SIP authentication attempts
  exceed the configured threshold (see [PBX fine tuning](pbx-tuning.md#failed-registration-alarm)).

## Toll fraud

Three brakes, all off until switched on:

- **Night dialling protection** (*Dial permissions* page): outside the night hours set there (and all
  weekend if wanted), calls to protected prefixes (default `00` international and `0900` premium) are
  blocked ("Your call cannot be completed as dialled", without answering the call) or need a night PIN
  (three tries). It runs before the permission groups, so it also covers trunk-to-trunk transit;
  portal faxes are exempt. Blocked calls carry `NIGHT_GUARD` in the CDR user field.
- **Trunk limits** (*Trunks → Routing*): concurrent channels and calls per minute, with the health
  alarm *Trunk limits* (see [Trunks](trunks.md#limits)).
- **Dial permission groups**: which numbers a group of users may dial at all.

## Root helper (`aipbx-priv`)

The portal never runs arbitrary commands as root. `/etc/sudoers.d/aipbx` allows `www-data` to run only
`/usr/local/sbin/aipbx-priv`, whose subcommands each map to one fixed operation with validated
arguments (service reloads, postfix relay, firewall rules, fail2ban, update check/start).

## Apache sandbox

Ubuntu's `apache2.service` is hardened with systemd sandboxing. The installer's override
(`/etc/systemd/system/apache2.service.d/override.conf`) relaxes only what the portal needs:

- `/etc/sudoers` and `/etc/sudoers.d` readable, so `sudo aipbx-priv` works;
- `/etc/asterisk`, `/etc/postfix` and `/etc/fail2ban/jail.d` writable (everything else under `/etc`
  stays read-only);
- `/root`, `/boot`, `/etc/ssh`, `/etc/apt` stay inaccessible.

## Scheduled task sandbox

Scheduled background scripts run in a hardened `systemd-run` sandbox as the unprivileged user `aipbx-task` with an isolated filesystem, strict resource limits, and private network namespaces (see [Scheduled tasks](scheduled-tasks.md#sandbox-security)).

## Read-only external databases

External database queries in IVR data steps and plugins run in strictly enforced read-only sessions with a 2.5-second statement timeout, single statement restrictions, and encrypted credentials (see [External databases](external-databases.md#supported-engines-and-security)).

## AI egress (opencode)

Greg's opencode brain and the workshop can use a remote model. Every exchange with a remote provider is
recorded on **AI → Echo → opencode traffic**, admin only: the exact prompt handed to the CLI, the final
answer or error, the provider/model, duration, and who asked. Local providers (`ollama`, `lmstudio`,
`llamacpp`, …) are not recorded. Payloads are encrypted with `SecretBox` — the installation key in
`/etc/ai-pbx.env` or `/var/lib/aipbx/settings.key` — so a database dump alone does not reveal them;
metadata, sizes and hashes stay readable. Records expire after 90 days, and **Delete all records** is
itself written to the audit log. This is an application-level record, not a TLS intercept: opencode
adds its own system prompt and tools before calling the provider, and that provider traffic is not
decrypted by the PBX.

## Other measures

TOTP two-factor authentication, passkeys, account/IP lockout with CAPTCHA, CSRF tokens on every form
and API call, sessions revoked on password change, least-privilege database users (runtime vs.
migrations), secrets only in `/etc/ai-pbx.env` (`640 root:asterisk`), Asterisk HTTP/AMI on loopback.
