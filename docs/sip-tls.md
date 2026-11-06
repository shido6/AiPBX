# SIP over TLS for `sip-tls.s2insights.cloud`

What a phone does when it registers over TLS, what is already true on the
`aipbx-dev` box, and the three things that are still outside this repository.

## The shape of it

```
phone ──TLS 5061──▶ sip-tls.s2insights.cloud ──▶ router ──▶ bat-pop-os:5061
                                                          └── container aipbx-dev:5061
                                                              [transport-tls]
directory / portal / agents: cloudflared tunnel, untouched
```

The SIP/TLS name must resolve **straight to the PBX with the proxy off**. It
must not go through the tunnel: Cloudflare's proxy carries HTTP(S), not
arbitrary SIP over TLS on 5061, and `cloudflared` cannot forward a raw SIP
registration. The directory keeps living on the tunnel; only this one name is
a plain DNS record.

## What is already done

* `SyncTransports.php` writes `domain=` on the TLS transport from
  `pjsipsettings.tls_domain`, and takes the method list from
  `pjsipsettings.tls_method` (default `tlsv1_2`, so nothing changes until it is
  set).
* The TLS transport exists and binds `0.0.0.0:5061`. It carries no `domain=`
  of its own: `scripts/aipbx_tls_enable.sh` writes `pjsipsettings.tls_domain`,
  regenerates the transport and reloads PJSIP, so the name is set in the same
  step as the certificate that has to match it.
* `scripts/aipbx_tls_enable.sh` does the whole job again from a certificate:
  installs the pair into the container, checks the key belongs to the
  certificate and that the certificate covers the name, writes the settings,
  regenerates the transport, reloads PJSIP, and clears the pending-sync row.
* `scripts/sip_tls_probe.py` performs what a phone performs, and says which
  step broke instead of "TLS is broken".
* Two `sys_users` rows on extension `1000` made `syncAllExtensions()` write
  that extension twice. PJSIP rejects the whole `pjsip.conf` on a duplicate
  object, so the next reload left **zero** endpoints. The generator now keeps
  one block per extension and logs the extra row; `SyncGeneratorTest` covers
  it (`testDuplicateUserRowForOneExtensionGeneratesOneBlock`).

## What is still missing, in order

1. **A certificate a phone trusts, for this name.** The transport currently
   uses `/etc/asterisk/keys/fullchain.pem`, which is the self-signed
   `CN=aipbx.local` certificate (SAN `aipbx.local`, `172.28.0.2`). It was made
   for the container, not for the internet. Options:
   * Let's Encrypt over DNS-01 (works even while the name is proxied, and
     renews without opening 5061). The box has `certbot` but **not**
     `python3-certbot-dns-cloudflare`, and no `~/.cloudflare.ini` — both are
     needed first:
     `certbot certonly --dns-cloudflare --dns-cloudflare-credentials ~/.cloudflare.ini -d sip-tls.s2insights.cloud`
     then
     `scripts/aipbx_tls_enable.sh --domain sip-tls.s2insights.cloud --cert /etc/letsencrypt/live/sip-tls.s2insights.cloud/fullchain.pem --key /etc/letsencrypt/live/sip-tls.s2insights.cloud/privkey.pem`
   * Or any CA-issued certificate for that name, pasted to the same command.
   The script refuses a key that does not match and a certificate that does not
   cover the name, so a wrong pair cannot reach Asterisk.

2. **A DNS record with the proxy off.** `sip-tls.s2insights.cloud` has **no
   record at all** in the `s2insights.cloud` zone (checked 2026-11-06), so the
   name does not resolve yet. It has to become a DNS-only `A` record for
   `24.212.186.194` (the WAN address of the box; the host itself is
   `192.168.1.214`). Do this after the certificate exists, so the name never
   serves a certificate it cannot justify.

   `s2insights.cloud` is gone — the registration lapsed, the zone reports status
   `moved`, and nothing on the box should be pointed at it again.

3. **The port, from the internet to the container.** One hop left:
   * host: **done** — the container publishes `5061/tcp` (`docker port
     aipbx-dev` → `5061/tcp -> 0.0.0.0:5061`), and `docker compose config` in
     `~/aipbx-docker` now exits 0, so the `cgroupns` refusal recorded here
     earlier no longer reproduces;
   * router: still to do — forward `5061/tcp` to `192.168.1.214`.

## Verifying, in the order that separates the causes

On the PBX host, against the container, this needs no certificate trust and no
DNS — it proves the transport and the credentials:

```
python3 scripts/sip_tls_probe.py --host 172.28.0.2 --port 5061 \
    --user 2001 --password-file /tmp/ext.pass --insecure
```

`2001` ("Test Desk 2001") answers with `401`, `realm=asterisk`, `qop=auth` and
then `200` — a completed registration over TLS, contact bound for 60 s. That is
the transport, the challenge and the credentials proven end to end on the box;
what it cannot prove is the certificate (it is self-signed, hence `--insecure`)
and reachability from the internet.

One trap while probing from the host: the container's `asterisk` fail2ban jail
watches SIP and bans the bridge address `172.28.0.1` after a few failed
authentications, which then looks exactly like "the port is closed" (nftables
rejects 5060/5061 with icmp-port-unreachable). Unban with
`docker exec aipbx-dev fail2ban-client set asterisk unbanip 172.28.0.1`, and
keep `172.28.0.1` in that jail's `ignoreip` if the portal probes SIP itself.

Once DNS and the port are in place, from anywhere:

```
python3 scripts/sip_tls_probe.py --host sip-tls.s2insights.cloud --user 1001 --password-file … 
```

Exit codes: `0` registered, `2` TLS/handshake (no listener, wrong port,
firewall), `3` certificate not trusted or not for this name — the step a phone
fails at too, `4` TLS fine but no SIP answer, `5` refused (403/404), `6`
credentials rejected.

## Phone settings

| field | value |
|---|---|
| SIP server / domain | `sip-tls.s2insights.cloud` |
| port | `5061` |
| transport | TLS (not "TLS/SRTP" on the transport line; SRTP is a media setting) |
| outbound proxy | leave empty, or `sip:sip-tls.s2insights.cloud:5061;transport=tls` |
| certificate check | on. That is the point of the exercise |

SRTP is negotiated per call (`media_encryption`), so turning TLS on does not by
itself encrypt audio.
