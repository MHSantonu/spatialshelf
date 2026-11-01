# SpatialShelf

Turn a 10-second phone video of a shop shelf into stock customers can order on WhatsApp — by typed text, a photo of a handwritten list, or a voice note.

Built for the **Meta Global AI Developer Hackathon (Oct 27 – Nov 4, 2026)**. Everything in this repo is new work done during the hackathon. One shop, one merchant, no payments or delivery — just the closed loop: **scan → verify → order → pick → stock goes down → next scan is faster.**

## Live demo

- Shop app: `https://159-65-146-210.sslip.io` (login with the seeded merchant account)
- Try ordering (no login, runs the exact WhatsApp order pipeline): `https://159-65-146-210.sslip.io/try`
- Demo video (3 min): *link added Nov 4*

> The WhatsApp test number can only message 5 verified phones, so judges should use `/try` — same pipeline (photo / voice / text → invoice), testable by anyone.

## The demo story

1. Shopkeeper pans the phone across a shelf for 10 seconds.
2. Under 60 seconds later the app shows what it found: name, size, price, estimated count per product.
3. Shopkeeper swipes to confirm or fix each item. Stock goes live.
4. A customer sends a photo of a handwritten grocery list to the shop's WhatsApp number.
5. The bot replies with a matched invoice (items, prices, total, out-of-stock) with Confirm / Cancel buttons.
6. The merchant screen shows the order with a picking route in shelf order. Stock drops on confirm.
7. A second customer sends a Banglish voice note; same flow.
8. The merchant rescans the shelf. Known products match instantly from visual memory, with no model call.

Real shop: Mahadi Store, Tangail, Bangladesh. Prices in Taka (৳).

## How it works

Two services, one database. Laravel owns everything users touch; a small Python worker does the pixel work. Models never do arithmetic or write to the DB — they return JSON via `response_format: json_schema`, and PHP validates, prices, and saves.

```text
Merchant phone ──video──> Laravel ──job──> Python worker ──frames──> SAM 3.1 (segment)
                              │                │  crops + DINOv2 embeddings
                              │                └──────────────┬───────────────
                              │   known? match in PHP (cosine, no model call)
                              │   unknown? batch of 8 crops ──> Muse Spark (read labels)
                              │                review cards ──> merchant swipe ──> products + visual memory
Customer WhatsApp ──text/photo/voice──> webhook ──> Voice Transcribe (voice only)
                                                          │  Muse Spark (parse + match to catalog enum)
                                                          └── invoice buttons ──> confirm ──> stock decrements (locked transaction)
```

### Scan pipeline

1. Laravel stores the upload, dispatches `ProcessScan` on the `scans` queue.
2. Worker downsamples with ffmpeg (720p, 4 fps), picks 6–8 keyframes.
3. **Muse Spark picks what SAM looks for**: one keyframe in, noun phrases out (`plastic jar`, `juice carton`, …). Those become SAM 3.1 prompts — a two-model loop that adapts to each shelf.
4. SAM 3.1 runs in **image mode** per phrase (day-1 spike: video mode caps at 16 objects/frame, image mode returned 83 on one frame). Boxes overlapping >60% IoU — plus containment (one box ≥70% inside another) — are merged.
5. Best frame per object → mask crop on white background from the full-res frame → DINOv2 embedding (384-d).
6. PHP groups crops >0.90 similar into one candidate SKU; group size = facing count.
7. Best crop per group is cosine-matched against confirmed `product_images`. Under threshold → "Known", reuse the product, no model call.
8. Unknown groups go to Muse Spark in batches of 8 with a JSON schema. Estimated stock = facings × category depth default, always labelled `~`.
9. Review screen polls and renders cards with confidence badges; merchant confirms into `products` + memory. Video mode is used only for the mask-overlay watch player.

### Order pipeline

1. Webhook verifies `X-Hub-Signature-256`, returns 200 fast, queues on `whatsapp`.
2. Voice notes convert (`ffmpeg -i note.ogg -ac 1 -ar 24000 -c:a pcm_s16le note.wav`) → `muse-voice-transcribe-1.0` with `languageBias: [Bengali, English]` and `keywords` = top 50 in-stock product/brand names.
3. **Muse Spark parses the order** with the live catalog baked into the schema as an `enum` of product ids — the model can only pick a real id or `"none"`, never invent one. Ambiguous lines (`tel` → two oils) return `alternative_ids` and the bot asks one list question before the invoice.
4. PHP prices every line from `products`, totals, saves `orders` + `order_lines` as draft. Confirm decrements stock inside one transaction with `lockForUpdate`.
5. Merchant sees the order sorted in shelf order (left-to-right picking route); **Packed** notifies the customer. Confirmed customer phrasings are appended to `products.aliases` — the text-side learning loop.

### Which Meta model does what

| Step | Model | Input | Output |
|---|---|---|---|
| Pick SAM phrases | `muse-spark-1.3` | 1 keyframe | noun phrases per shelf |
| Find products | `sam-3.1` | keyframes + phrases | boxes + masks with stable ids |
| Read each product | `muse-spark-1.3` | batch of 8 crops | name, brand, size, category, MRP text |
| Parse list / text / transcript | `muse-spark-1.3` | message + catalog enum | order lines matched to product ids |
| Transcribe voice | `muse-voice-transcribe-1.0` | 24 kHz mono WAV | transcript |
| Visual memory | DINOv2 `facebook/dinov2-small` (worker CPU) | crop | 384-d vector in MySQL JSON |

Cost sanity: ~$0.04 SAM per scan, Spark $1.25/$4.25 per M tokens, voice $0.18/hr. The hackathon credit covers hundreds of runs.

### Key engineering decisions

- Prompts and JSON schemas live in `prompts/` as files, loaded at runtime — never inlined.
- All Meta calls go through `app/app/Services/Meta/*` (PHP) or `worker/services/*` (Python). No model calls in controllers.
- Every raw model response is stored (`scans.stats`, order `context`) for debugging and the demo.
- Embeddings are JSON arrays; similarity is brute-force cosine in PHP — fast enough for a few thousand images, no vector DB to operate.
- Stock only changes inside a locked transaction; `stock_is_estimate` stays true until the merchant edits the count (UI shows `~`).

## Repo layout

```text
spatialshelf/
  app/                  Laravel 13 (web app, API, webhook, jobs)
    app/Services/Meta/  MuseSpark, VoiceTranscribe, MetaClient
    app/Services/WhatsApp/  WhatsAppClient, InvoiceFormatter
    app/Services/Catalog/   SkuGrouper, MemoryMatcher, VectorIndex
    app/Services/Orders/    OrderIntake, DraftOrderBuilder, OrderService
    app/Jobs/           ProcessScan, HandleInboundMessage
  worker/               Python FastAPI (ffmpeg, SAM 3.1, crops, DINOv2)
  prompts/              system prompts + JSON schemas, versioned
  spikes/               day-1 probes + RESULTS.md (kept, not used at runtime)
  samples/              demo videos, list photos, voice notes
  eval/                 labelled accuracy sets (in progress)
  storage-shared/       videos, crops, audio — read/written by both services
  deploy/               server-setup.sh, deploy.sh, nginx + supervisor configs
  docs/spec.md          build spec · docs/deploy.md  live runbook
  dev.ps1               Windows dev runner (serve + queue + vite + worker)
```

## Run it locally

Prereqs: PHP 8.3+, Composer, Node 20, Python 3.11, MySQL 8, ffmpeg. No Docker.

```powershell
# 1. env files (never commit these)
Copy-Item .env.example .env
Copy-Item app/.env.example app/.env
# Fill in: MODEL_API_KEY, DB_*, SHARED_STORAGE_PATH, WORKER_URL,
# WA_* (WhatsApp), DEMO_EMAIL + DEMO_PASSWORD (seeded merchant login)

# 2. Laravel
cd app
composer install
php artisan key:generate
php artisan migrate --seed
npm install

# 3. worker
cd ../worker
python -m venv .venv
.venv\Scripts\pip install -r requirements.txt

# 4. start everything (serve :8000, queue, vite, worker :8080)
cd ..
.\dev.ps1
```

Then: `http://127.0.0.1:8000/scan` (login first), `http://127.0.0.1:8000/try` (public simulator). Queues must run on `scans,whatsapp,default` with `--timeout=300`.

Linux/macOS: same steps; replace `dev.ps1` with `php artisan serve`, `php artisan queue:work --queue=scans,whatsapp,default`, `npm run dev`, and `uvicorn main:app --host 127.0.0.1 --port 8080` from `worker/`.

### Tests

```powershell
cd app
php artisan test            # Pest suite (services that parse or price are covered)
.\vendor\bin\pint --test    # style
.\vendor\bin\phpstan analyse  # types (level per phpstan.neon)
```

HTTP to Meta/WhatsApp is faked in tests (`Http::fake`) — no keys needed.

## Deploy

Live runbook: [docs/deploy.md](docs/deploy.md). Short version: Ubuntu 24.04 droplet, nginx → php-fpm, supervisor for `queue:work` + uvicorn worker, MySQL 8, certbot for HTTPS (webhook needs a stable URL). Redeploys are `git pull --ff-only && bash deploy/deploy.sh`.

## Spike results (day 1, kept honest)

Full log: [spikes/RESULTS.md](spikes/RESULTS.md).

- SAM video mode caps at 16 tracked objects; image mode hit 83 on one frame → image mode detects, video mode only renders the overlay.
- Spark-picked phrases beat the generic `product package` baseline (40 boxes ≥0.7 vs 4 on a Horlicks shelf) → phrase picker kept.
- 4K crops read better than 720p (mean conf 0.71 vs 0.59) but sizes are rarely printed on the front face → size comes from the merchant, not the model.
- Order parsing on 4 samples: 7 matched, 7 ambiguous, 0 silently wrong after the ambiguity + fuzzy fixes.
- WhatsApp echo works end to end (text, image, audio) via tunnel; fixes logged (subscribed_apps, Graph v26, error-body logging).

## Built during the hackathon

- Oct 27: repo created; day-1 spikes on all three model calls (SAM video+image, Spark crop read, order parse, voice, WhatsApp echo). Decisions committed same day.
- Oct 28–29: worker `/scan` end to end; grouping, visual memory, Spark batch read.
- Oct 30: review UI (upload, progress, swipe cards, stock list, SAM overlay player).
- Oct 31: orders core (catalog-enum contract, PHP pricing, `/try` simulator); **live on the droplet**.
- Nov 1: WhatsApp inbound (text/photo/voice), invoice buttons, confirm → stock decrement.
- Nov 2–3: clarification lists, picking route, Packed notification, rescan "Known", alias learning; record at the shop.
- Nov 4: cut the video, submit.

Nothing copied from earlier projects; git history starts Oct 27, 2026.

## Out of scope (deliberately)

Payments, delivery, multi-shop auth, WhatsApp native catalog sync, native app / offline mode, analytics dashboards. Next step after the hackathon: supplier receipts for exact restock.
