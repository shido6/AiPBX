# Cloud AI services (Plugin)

> **Plugin (`ai-cloud` / `ai-voicemail`)**: Optional plugins from the official catalog. Enable them under **Integrations → Plugins** (see [Plugin system & developer guide](plugin-development.md)).

**AI → Cloud services** (admin only) holds the accounts of cloud AI providers in one place and
chooses, for each job and language, which engine AI features use: a local model of this server
(see [Local AI models](local-ai.md)) or a cloud provider.

> A cloud engine sends audio or text to that provider. Check its terms and your data-protection
> duties (KVKK/GDPR) before choosing one. Local models keep everything on the server.

## Providers

| Provider | Speech | Speech to text | Language models | Embeddings | Free tier |
|----------|:------:|:--------------:|:---------------:|:----------:|:---------:|
| Google AI Studio (Gemini, Gemma) | | ✓ (Gemini) | ✓ | ✓ | ✓ |
| OpenAI | ✓ | ✓ (gpt-4o-mini-transcribe) | ✓ | ✓ | |
| Microsoft Azure AI Speech | ✓ | ✓ | | | ✓ |
| Google Cloud Text-to-Speech | ✓ | | | | ✓ |
| Amazon Polly | ✓ | | | | ✓ |
| ElevenLabs | ✓ | ✓ (Scribe) | | | ✓ |
| Deepgram | | ✓ (Nova-3) | | | ✓ |
| Groq | | ✓ (Whisper large v3 turbo) | ✓ (Llama, Gemma, …) | | ✓ |
| OpenRouter | | | ✓ (hundreds of models) | | some models |
| NVIDIA NIM (build.nvidia.com) | | | ✓ (Llama, Nemotron, Qwen, DeepSeek …) | | trial credits |
| Qwen (Alibaba Cloud Model Studio) | | | ✓ (qwen-plus, qwen-turbo, qwen-max …) | | ✓ (new accounts) |
| OpenAI-compatible (own address) | | | ✓ (any service with the OpenAI chat API) | | — |

Each card has the key fields, a link to get a key, a **Test** button (a cheap authenticated call
that shows the provider's own error when the key is wrong) and this month's use: minutes of audio
turned into text, characters spoken and language-model tokens (table `ai_cloud_usage`).

Keys are stored encrypted in `sys_settings` as `ai_tts.<provider>.<field>` — the names the Cloud
TTS page used before, so existing accounts keep working. The Cloud TTS page now only shows the
status of its providers and links here; changing keys needs the admin role.

## Engine for each job

For speech, speech to text and the **language model** in Turkish, German and English, choose a
running or downloaded local model or a configured cloud provider (`ai_engine.<job>.<lang>` in `sys_settings`, values
`local:<model id>` or `cloud:<provider>`). AI features that come later (AI applications, voice
requests) use these choices, so switching between local and cloud needs no other change.

## Trying

*AI → Local models → Try: speech to text* lists the configured cloud providers next to the local
models: record from the microphone or upload a WAV file, pick the language, and compare the text
and the time each engine needs.

## Language models

Large language models are not run on the PBX itself (most servers cannot carry them); they come
from the providers above. They are used by:

- **AI applications** — routing by speech and voice requests with *Understanding: cloud model*
  ([AI applications](ai-apps.md#understanding)),
- **voicemail as text** — the one-sentence summary (below).

The model of Google AI, OpenAI, Groq and OpenRouter is fixed (a small, fast one: gemini-2.5-flash,
gpt-4.1-mini, llama-3.3-70b-versatile, google/gemini-2.5-flash). For the three providers below the
card has a **Model** field; *Test* sends one tiny chat request, so a wrong model name shows at once.

| Provider | Address | Model (default) |
|----------|---------|-----------------|
| NVIDIA NIM | `https://integrate.api.nvidia.com/v1` | `meta/llama-3.3-70b-instruct`; any model of build.nvidia.com, e.g. `qwen/qwen3-235b-a22b` |
| Qwen (Alibaba Cloud Model Studio) | international `https://dashscope-intl.aliyuncs.com/compatible-mode/v1` (default); mainland China `https://dashscope.aliyuncs.com/compatible-mode/v1` | `qwen-plus` |
| OpenAI-compatible | your address ending in `/v1`, e.g. `https://api.deepseek.com/v1`, `https://api.mistral.ai/v1`, `http://10.0.0.5:11434/v1` (Ollama on another server) | required; the key is optional |

### Use more than one provider (business continuity)

A free tier can run out of tokens, a key can be revoked and a provider can have an outage. Two
different configured language-model providers are a continuity pair, not two keys for the same
failure.
The Assistant and Greg (with the System AI brain) try the configured providers in the order the
page lists them: quota, timeout, bad key and provider 5xx move to the next account, and only the
provider that answers is counted in *this month's use*. If every account fails, the error says so
instead of exposing one provider's message as the whole story.

The prompt is sent to whichever configured provider answers, so add only accounts the business is
willing to send that data to. The choice of speech and speech-to-text engine is still manual; keep
a local model or a second configured provider ready for those jobs.

### Several engineers, one account (temporal multiplexing)

Each engineer has their own Greg conversation, so two people can work at the same time without
sharing a transcript or answering each other's questions. The limit is the provider's contract, not
the portal: a free plan enforces both a rate limit and a concurrency limit, and a second
simultaneous request can be refused even when tokens remain.

Free-tier providers are therefore serialized per provider. The second request waits for the first
to finish, up to 15 seconds, and then gets a clear "still answering another engineer" message. This
is temporal multiplexing — one account serving several engineers by taking turns. It keeps two
requests from racing into the same 429, but it is **not parallel work**.

Providers with concurrent sessions are not serialized: OpenAI, a paid OpenAI-compatible endpoint, or a
local OpenAI-compatible endpoint. Configure one when engineers must ask at the same time;
*AI → Cloud services* shows **Parallel sessions** as soon as one is configured. The portal cannot
tell a free key from a paid one, so that badge describes the provider's published plan, not the
account's actual billing.

Shared PBX changes are a separate matter: every applied change still gets an audit row and an undo
button, and the Dialplan editor takes a short per-target lock (one extension, ring group, queue or
time condition) while it reads the before-value and writes the change. Two engineers changing the
same target therefore queue instead of both capturing the same old state; changes to different
targets never wait. One engineer's work stays reviewable and undoable even when another engineer
changes the same extension later.

The choice of speech and speech-to-text engine is still manual, and this queue does not apply to
them.

### Splitting a question between the two engineers

A multi-part question — a failing trunk and a queue that drops calls, say — can be split instead of
answered twice. The engineer on duty divides it into at most three sections and hands some to the
other engineer. Each investigates their own section only, and the engineer on duty writes one answer
from both sets of findings. The chat shows a quiet line naming the split, never two competing
answers; a simple question is not split at all. The feature is on by default under *AI → VoIP
Engineer → Relief engineer* and costs up to three extra model calls on a question that is actually
split. If the colleague finds nothing or the split cannot be parsed, the ordinary single-engineer
answer is used instead.

## Voicemail as text

*AI → Cloud services → Voicemail as text*: a new voicemail is written down with the
**speech-to-text** engine of the PBX's prompt language (a small local model such as Vosk or
Whisper, or a cloud provider) and, if *summary* is ticked, summarised in one sentence by the
**language model** of that language ("John Smith wants to move tomorrow's 10:00 meeting to Friday").

- The **voicemail e-mail** gets both under the list of details: template variable `{ai_text}` of
  the *voicemail* template (*E-mail → Templates*; a changed template needs `{ai_text}` added).
- The **app notification** shows the summary (or the start of the transcript) under the caller
  (Android 1.0.66).
- *My Phone → Voicemail* and the mobile apps' voicemail list show it next to the message.

Each message is processed once (table `voicemail_ai`); the e-mail comes first and the push uses the
result. Voicemail is handled by Asterisk's own user, which cannot read the AI keys, so the work is
done by the portal on the same server (`api/voicemail_ai.php`, only from 127.0.0.1, signed).
Nothing here can hold back the e-mail or the notification: when the engine fails, they go out
without the text. With a cloud engine the recording (speech to text) or its text (summary) is sent
to that provider.
