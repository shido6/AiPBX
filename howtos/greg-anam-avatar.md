# Give Greg a face and a voice with Anam.ai

Greg already answers in text, from this server's own data. This howto sets up
an **optional add-on to his configuration**: a video persona that speaks his
answers aloud. It also shows how the same persona can hold a real two-way
conversation, join a meeting, and where the honest limits of "call him on an
extension" are today. The add-on changes nothing about Greg's answers or the
PBX — remove the key and he is exactly what he was before.

Tested on AiPBX 1.33 with the Anam JavaScript SDK 4.29 and the
`/v1/auth/session-token` and `/v1/meetings/invites` APIs (October 2026).

## What it does, and what it does not

- **Does:** shows a video persona in Greg's chat panel and speaks Greg's answers
  with `talk()` — the words are still his, produced by AiPBX with this server's
  data, not by Anam's model.
- **Can listen:** with *Let the avatar listen* switched on, the browser opens
  the microphone and the persona holds a two-way conversation — answering with
  the model and prompt you set in Anam, **not** with this server's data. Those
  are two different modes on purpose; pick the one that answers the question you
  are asking.
- **Can join a meeting:** Anam puts the persona into a Google Meet, Zoom or
  Microsoft Teams call as a participant, now or up to seven days ahead.
- **Does not answer a SIP extension today.** Anam documents no native
  SIP/PSTN/PBX interface (see *Calls, meetings, extensions* below for the bridge
  that would be needed).
- **Is not a phone line.** The browser face is WebRTC to Anam's cloud; nothing
  about the PBX's own calling changes.

## What it costs

Anam is a separate service with its own account and its own billing; AiPBX
neither resells nor proxies it. **Prices change — the current ones are at
<https://anam.ai/pricing>**, which the settings card links to. As published in
October 2026:

| Plan | Per month | Minutes included | Extra minute | Session cap |
|---|---:|---:|---:|---:|
| Free | $0 | 30 | — | 3 min |
| Starter | $12 | 50 | $0.16 | 5 min |
| Explorer | $49 | 250 | $0.14 | 10 min |
| Growth | $299 | 2,000 | $0.12 | 2 h |
| Professional | $999 | 8,000 | $0.11 | 2 h |
| Enterprise | custom | varies | $0.04 | unlimited |

Two facts decide how this behaves in your portal:

- **A minute is session time, not speaking time.** The clock starts when the
  session starts and runs whether anyone talks or not — silence, a muted
  microphone and an idle avatar do not pause it.
- **Each plan caps a single session.** Set *Session length* on the card inside
  your plan's cap (180 s is the free tier); the browser stops on the same
  deadline.

## Step 1 — Create the persona in Anam

1. Sign up at <https://anam.ai> and open the dashboard.
2. **Personas → New persona.** Choose an avatar and a voice, and name it (for
   example *Greg*).
3. Leave the persona's own LLM at its default **unless you want the listening
   mode** — then give it a model and a prompt, because that is what answers.
4. Copy the **persona id** (a short string of letters, digits and dashes).

## Step 2 — Create an API key

**Dashboard → API keys → Create key**, and copy it once. It can mint sessions
and send the persona into meetings for every persona in the account, so treat it
like a password: entered only in the portal, never in a file in the repository.

## Step 3 — Enter both in AiPBX

1. Sign in as an administrator.
2. **AI → VoIP Engineer**, scroll to **Face and voice — Anam.ai avatar**.
3. Paste the **persona id** and the **API key**, set the **session length**.
4. **Save.** The key is encrypted with the installation's settings key; the field
   is a password field, and empty means "unchanged". **Remove key** deletes it.
5. **Test saved settings** asks Anam for the persona and shows its name.
6. Tick **Show the avatar in Greg's chat** and save. Switching it on without both
   halves is refused rather than stored.

Every change writes an audit row (who, when, on or off, session length) — never
the key, never a token.

## Step 4 — Use it

Open **Chat**; Greg's panel now has a person icon next to the send button.

- Click it: the face appears and the countdown starts.
- Ask Greg something: the answer appears as text and is spoken.
- **Let the avatar listen** (on the settings card) changes the mode: the browser
  asks for the microphone once, the persona answers by voice with its own model,
  and Greg's written answers are no longer spoken over it.
- Click again, press stop, leave the page, or reach the limit to end the session.

The page never sees the API key. It receives a **session token** minted on the
server, bound to the persona, limited in length, and rate-limited so a double
click cannot buy two sessions.

## Calls, meetings, extensions

**WebRTC (what this integration uses).** The JavaScript SDK connects over
WebRTC; with `disableInputAudio` off it starts capturing the microphone, which
is exactly what a two-way call is. That is the listening mode above.

**Embeds elsewhere.** Anam publishes an `<anam-agent>` web component and a
player iframe for pages that only want the face
(<https://anam.ai/docs/embed/overview>). AiPBX does not use them — the portal
has its own widget and the SDK — but a dealer page or the intranet can host the
same persona without touching this code.

**Meetings (built in).** On the same card, *Send him into a meeting* takes a
Google Meet, Zoom or Microsoft Teams link and an optional join time (empty =
now; up to seven days ahead — scheduling at least ten minutes ahead reserves a
place — <https://anam.ai/docs/api-reference/meetings/create-meeting-invite>).
The request is Anam's documented `POST /v1/meetings/invites` with
`meetingUrl`, `personaConfig.personaId`, `groupCall` and `joinAt`. In a group
call the persona stays quiet until someone addresses it by display name; turn
the checkbox off for a 1:1 meeting. Anam adds an AI-disclosure suffix to the
name it joins with. Only the meeting *host* is written to the audit log — a
meeting link is often the only thing standing between a stranger and the room.

**Extensions (not native — a bridge).** Anam publishes no SIP, PSTN or phone
number interface, so "dial 701 and talk to Greg" cannot be done by pointing
Asterisk at Anam. The documented building blocks for that are:

- **Pipecat** with `AnamTransport` — a Python agent that owns STT, LLM and TTS
  and sends audio to the avatar for lip-sync;
- **LiveKit** — Anam's engine joins the room as a participant and publishes the
  avatar as a separate track, while your agent handles the audio;
- the **Python SDK** with audio passthrough (`send_audio_chunk`,
  `end_sequence`) for a custom bridge.

The most direct bridge today is **LiveKit SIP**: Asterisk sends the call over a
SIP trunk to LiveKit (an inbound trunk plus a dispatch rule puts each caller in
a room — <https://docs.livekit.io/telephony/accepting-calls/inbound-trunk/>),
the persona joins that same room through Anam's LiveKit integration
(<https://anam.ai/docs/integrations/livekit/overview>), and the agent in the
middle owns the audio. ExternalMedia/AudioSocket into the Python SDK is the
roll-your-own version of the same idea.

Either way it is a project, not a setting: a SIP trunk and dispatch rules, a
small always-on agent service, latency and echo tuning, and every minute billed
as session time. Nothing in this howto claims it exists yet — what exists in
AiPBX today is the browser face, the listening mode and the meeting invites.

## Going further

| File | What it does |
|------|--------------|
| `web/src/services/AnamService.php` | settings (persona, key, limit, listening), session token, meetings, the persona check |
| `web/api/anam.php` | the browser endpoint: `session`, `status`, `test` |
| `web/assets/js/greg_avatar.js` | mounts the stream, speaks answers, owns the countdown and the microphone switch |

Ideas that fit the same shape: per-role personas (store one id per role), a
"Greg joins this call" button on the CDR page, or a LiveKit room per
conference — the persona is a participant, and the PBX decides which room.

## Troubleshooting

| What you see | What it means |
|---|---|
| *No Anam persona is configured yet* | The toggle is on but the id or the key is missing |
| *Anam refused the API key (401/403)* | Key revoked, mistyped, or from another account |
| *Anam does not know this persona id* | Wrong id, or the persona was deleted |
| *Could not reach Anam* | No outbound HTTPS from the server (firewall, proxy) |
| Video appears but nothing is heard | The browser blocked autoplay with sound — click once inside the page, then start again |
| The persona cannot hear you | *Let the avatar listen* is off, or the microphone prompt was refused |
| *Anam's Meetings API accepts Google Meet, Zoom and Microsoft Teams links* | The link's host is not one of the supported meeting providers |
| *The join time must be within the next seven days* | Anam schedules at most a week ahead |
| *Session limit reached* | Working as designed: the limit protects the budget |

## A note on the repository

AiPBX ships with **no persona id and no key**. Whatever you create in Anam is
yours and lives in your database, not in git; the same is true for anyone who
forks this project. Please keep it that way in pull requests — ids, keys, host
names, meeting links and customer names do not belong in a public repository.
