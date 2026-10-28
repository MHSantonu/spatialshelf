# SpatialShelf — Build Spec

Oct 26, 2026 · @Mahadi

## What we are building

SpatialShelf turns a 10-second phone video of a shop shelf into stock a customer can order on WhatsApp by photo of a handwritten list or a voice note. The whole product is one closed loop: **scan → verify → order → pick → stock goes down → next scan is faster.** The demo must show that loop end to end at a real shop in Tangail; every feature below exists to make that loop work.

**The demo story (3 minutes):**

1. A shopkeeper pans the phone across a shelf for 10 seconds.
2. Under 60 seconds later, the app shows the products it found, each with name, size, price and an estimated count.
3. The shopkeeper swipes to confirm or fix each item. Stock goes live.
4. A customer sends a photo of a handwritten grocery list to the shop's WhatsApp number.
5. The bot replies with a matched invoice: items, prices, total, and what is out of stock. The customer taps Confirm.
6. The shopkeeper's screen shows the order with a picking route in shelf order. Stock counts drop.
7. A second customer sends a Banglish voice note; same flow.
8. The shopkeeper rescans the shelf. Known products are matched instantly from memory, with no model call.

**In scope (must ship):**

- Merchant web app (mobile browser): record or upload video, review screen, stock list, orders list with picking route
- Scan pipeline: SAM 3.1 finds and tracks products, Muse Spark reads each one
- Visual memory: confirmed products embedded and matched on rescans
- WhatsApp bot: text, photo and voice orders, interactive invoice, confirm/cancel
- Stock decrement on confirmed orders
- One shop, one merchant account, seeded

**Planned additions (Nov 2, after the core loop works):** customers can order by sending a photo of a single product, recognised from visual memory first, Muse Spark as backup; and shopkeepers set prices and sizes by voice on the review cards ("chhoy sho ponchash" → ৳650).

**Out of scope (do not build):**

- Payments, delivery, couriers
- Multi-shop signup, auth flows beyond one seeded login
- WhatsApp native catalog / Commerce Manager sync
- Native mobile app, offline mode
- Analytics dashboards, reorder suggestions, supplier features

Anything not in the demo story is cut first when time runs short.

## User flows

There are two users and two flows. The merchant uses a mobile web app; the customer only ever uses WhatsApp.

**Merchant: scan a shelf**

1. Open the app, tap **Scan shelf**, record a 10–15 second left-to-right pan (or upload a video).
2. Upload starts immediately; screen shows a progress bar with live steps ("Finding products… Reading labels…").
3. Pipeline runs: SAM 3.1 finds and tracks each product across the video, the best frame of each is cropped, known products are matched from memory, unknown ones are read by Muse Spark.
4. Review screen: one card per product, crop image on top, then product name, size, price, estimated count, and a confidence badge. Matched-from-memory items show a "Known" badge and are pre-confirmed.
5. Merchant swipes right to confirm, left to reject, or taps to edit any field. Price is required before an item goes live.
6. Confirmed items go live in stock. Their crops and embeddings are saved to visual memory.

**Customer: order on WhatsApp**

1. Customer messages the shop number with any of: typed text, a photo of a handwritten list, or a voice note.
2. Bot sends a quick "Got it, checking the shelf…" reply.
3. Voice notes are transcribed to text. Photos and text go straight to Muse Spark.
4. Muse Spark turns the message into a list of wanted items with quantities, then matches each against live stock.
5. Bot replies with an invoice: matched items, quantities, unit prices, total, plus a short list of anything unavailable or unclear. Two buttons: **Confirm** and **Cancel**.
6. If an item is ambiguous ("tel" could be two oils), the bot asks one question with a list of options before the invoice.
7. On **Confirm**: order is saved, stock is decremented, customer gets an order number.
8. Merchant app shows the new order with a picking route: items sorted in the order they sit on the shelf, left to right.
9. Merchant taps **Packed** and the customer gets a "Ready for pickup" message.

## What to collect

Get every account and key working by the end of Oct 27; the test assets matter as much as the keys, because day 1 spikes need real shelf video and real handwriting. Bangladesh is not on Meta Model API's restricted list, and Muse Voice Transcribe handles English with Bengali mixed in, which covers customers who say English product names inside a Bangla sentence.

**Accounts and keys**

| Item | Where | Notes |
| --- | --- | --- |
| Meta Model API key | [dev.meta.ai](https://dev.meta.ai) dashboard | Add card, set $150 spend alert, claim hackathon credits when the link drops |
| Meta developer app with WhatsApp product | developers.facebook.com → Create app → WhatsApp → Set up | Gives a free test business number, WABA ID, phone number ID |
| Permanent access token | Business settings → System user → generate token with `whatsapp_business_messaging` | The temporary token expires in 24 h; don't demo on it |
| Up to 5 verified recipient numbers | WhatsApp → API Setup → manage list | Test number can only message these 5; add your phone + 2 friends who will act as customers |
| Webhook verify token | You choose a string | Set in app dashboard, subscribe to `messages` field |
| Baseten / Modal credits | Forms in the participant guide | Backup only; apply today |
| VPS or host with public HTTPS | Your usual provider | Webhook needs a stable URL; Cloudflare tunnel from your machine is the fallback |
| Public GitHub repo `spatialshelf` | github.com/mhsantonu | Create on Oct 27, first commit that day |

**Test assets to capture (by Oct 28)**

- [ ] 5 shelf videos from a real Tangail shop: 10–15 s, left-to-right, steady, landscape, good light. Mix of packets (chips, biscuits), bottles (oil, soft drinks), jars, soap
- [ ] 3 still photos of the same shelves, for image-mode fallback
- [ ] Shop's price list for those shelves (handwritten is fine; you need real prices)
- [ ] 6 handwritten grocery lists: 4 in English, 1 with English names and Bangla quantities, 1 messy; photographed on a phone
- [ ] 6 voice notes recorded on WhatsApp: English, Banglish (English product names in a Bangla sentence), one with background noise, one asking for an item not in stock
- [ ] Written permission from the shopkeeper to film and show the shop in a public video

**Tools on your machine**

- ffmpeg (video downsampling, voice note conversion)
- PHP 8.3 + Composer, Laravel 12
- Python 3.11 (scan worker)
- MySQL 8 (no Docker; everything runs directly on your machine)
- Screen recorder + phone screen mirror for the demo video

## Architecture

Two services and one database: a Laravel app that owns everything users touch, and a small Python scan worker that does the pixel work. Models never do arithmetic or write to the database; they return JSON, and PHP validates it, prices it and saves it.

&#91;embedded content: SpatialShelf architecture · 2 services, 3 Meta models, 1 database\]

The merchant's scans flow through the worker to SAM 3.1; customer orders come in by webhook and go to Muse Spark or Voice Transcribe. Both loops read and write the same catalog.

**Components**

| Component | Stack | Job |
| --- | --- | --- |
| Merchant web app | Laravel 12 + Livewire, Tailwind, mobile-first | Scan upload, review/swipe, stock, orders, picking route |
| Core API + queues | Laravel, database queue driver | Scan orchestration, Muse Spark calls, WhatsApp webhook, order logic |
| Scan worker | Python 3.11, FastAPI, ffmpeg, OpenCV, DINOv2 | Video prep, SAM 3.1 call + parsing, mask crops, image embeddings |
| Database | MySQL 8 (embeddings as JSON, cosine in PHP) | Products, stock, scans, embeddings, orders, conversations |
| File storage | Local disk / S3-compatible | Videos, crops, voice notes |
| WhatsApp | Cloud API, test number | Inbound messages via webhook, outbound text + interactive |

**Which model does what**

| Step | Model | Input | Output |
| --- | --- | --- | --- |
| Find and track products | `sam-3.1` | Shelf video (downsampled) + noun prompts | Per-frame boxes + masks with stable object ids |
| Read each product | `muse-spark-1.3` | Batch of crop images | JSON: name, brand, size, unit, category, price tag text |
| Read a handwritten list | `muse-spark-1.3` | Photo + shop catalog | JSON order lines matched to product ids |
| Transcribe a voice note | `muse-voice-transcribe-1.0` | WAV, `languageBias: [Bengali, English]`, `keywords` = shop's product names | Transcript text |
| Understand a text / transcript order | `muse-spark-1.3` | Text + shop catalog | JSON order lines matched to product ids |
| Visual memory | DINOv2 (Meta open weights, runs in worker) | Crop image | 384-d vector stored in MySQL |

Meta Model API has no embedding endpoint, so visual memory uses DINOv2, Meta's open image model, on CPU in the scan worker. Feeding the shop's product names to Voice Transcribe as `keywords` is a cheap accuracy win: the model is biased toward the exact brands on that shelf.

**Scan pipeline, step by step**

**Oct 27 spike result:** SAM 3.1 video mode stops at 16 tracked objects, while image mode returned up to 83 bottles on one frame. Detection therefore runs in image mode on 6–8 keyframes, and duplicates across frames are merged with DINOv2 embeddings plus position. Video mode is used only for the demo's mask-overlay player. Steps 2–3 below change accordingly.

1. Laravel stores the upload and dispatches `ProcessScan`.
2. Worker downsamples with ffmpeg to 720p at 4 fps (a 15 s pan ≈ 60 frames).
3. Worker uploads the clip to the Meta Files API and calls SAM 3.1 once per prompt in parallel: `product package`, `bottle`, `jar`. Boxes overlapping >60% across prompts are merged.
4. For each object id, pick its best frame: largest box with the object fully inside the frame. Crop with the mask on a white background.
5. Record position: frame index + box centre x gives left-to-right shelf order; box centre y gives the row.
6. Embed every crop with DINOv2.
7. Laravel groups crops whose vectors are >0.90 similar into one candidate SKU. Group size = facing count.
8. Each group's best crop is compared by cosine similarity, in PHP, with the stored embeddings of confirmed products. One shop has at most a few thousand product images, so a brute-force search takes milliseconds. Distance under threshold → "Known", reuse the product, no model call.
9. Unknown groups go to Muse Spark in batches of 8 crops per request, with a JSON schema. One call reads all 8.
10. Estimated stock = facings × category depth default (chips 3, bottles 4, soap 6), clearly labelled as an estimate.
11. Scan marked `ready`; review screen polls and renders.

**Cost check:** SAM video is $0.20 per 1,000 frames, so 3 prompts × 60 frames costs about $0.04 per scan. Muse Spark 1.3 is $1.25 / $4.25 per million input/output tokens. Voice is $0.18 per hour. The $150 credit covers hundreds of test runs ([pricing](https://dev.meta.ai/docs/pricing-rate-limits)).

## Data model

Nine tables. `products` is the live catalog; `product_images` is the visual memory; `scan_items` holds unconfirmed detections until the merchant swipes them.

| Table | Key columns | Purpose |
| --- | --- | --- |
| `shops` | id, name, wa\_phone\_number\_id, depth\_defaults (json) | One seeded shop |
| `products` | id, shop\_id, name, brand, size\_value, size\_unit, category, price, stock\_qty, stock\_is\_estimate, shelf\_row, shelf\_order, aliases (json), active | Live catalog |
| `product_images` | id, product\_id, crop\_path, embedding (JSON, 384 floats), source\_scan\_id | Visual memory; matched by cosine in PHP |
| `scans` | id, shop\_id, video\_path, status, frames\_processed, started\_at, finished\_at, stats (json) | One per video |
| `scan_items` | id, scan\_id, crop\_path, embedding (JSON), facings, est\_qty, shelf\_row, shelf\_order, extracted (json), matched\_product\_id, match\_distance, decision | Review queue; decision = pending / confirmed / rejected |
| `customers` | id, shop\_id, wa\_id, name | WhatsApp senders |
| `conversations` | id, customer\_id, state, pending\_order\_id, context (json), last\_inbound\_at | Simple state machine per customer |
| `orders` | id, shop\_id, customer\_id, status, source\_type, raw\_input\_path, transcript, total, confirmed\_at | status = draft / confirmed / packed / cancelled |
| `order_lines` | id, order\_id, product\_id, requested\_text, qty, unit\_price, line\_total, match\_confidence | Priced by PHP, not the model |

**Rules that keep the demo honest**

- Stock only changes inside one DB transaction on order confirm: lock product rows, check `stock_qty >= qty`, decrement.
- `stock_is_estimate` stays true until the merchant edits the count; the UI shows "\~" before estimated numbers.
- `aliases` collects what customers actually wrote or said ("ACI lobon", "lal chips") each time an order line is confirmed. That's the second learning loop, on the text side.
- Every model response is saved raw in `scans.stats` or order `context`, so you can show it in the demo and debug misreads.

## Model contracts

All Muse Spark calls go through the OpenAI-compatible Chat Completions endpoint (`https://api.meta.ai/v1`) with `response_format: {type: "json_schema"}`. Decoding is constrained to the schema, so there's no JSON repair code ([structured output](https://dev.meta.ai/docs/structured-output)). In Laravel, use `openai-php/client` with the base URL swapped.

The key trick for ordering: put the shop's live product ids into the schema as an `enum`. The model then cannot invent a product; it can only pick a real id or `"none"`. The limit is 1,000 enum values, far above one shop's catalog.

**1. Read shelf crops** (`muse-spark-1.3`, up to 8 images per call)

System prompt: *You read products on shelves in small Bangladeshi grocery shops. For each numbered image, read the package exactly as printed. Return the product name exactly as printed, in English. Never guess a price; only return price text if a price tag or MRP is visible. Use confidence below 0.5 if the label is unreadable.*

```json
{
  "type": "object",
  "properties": {
    "items": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "image_index": {"type": "integer"},
          "name": {"type": "string"},
          "brand": {"type": "string"},
          "size_value": {"type": "number"},
          "size_unit": {"type": "string", "enum": ["g", "kg", "ml", "l", "pcs", "unknown"]},
          "category": {"type": "string", "enum": ["snacks", "biscuits", "oil", "drinks", "spices", "rice_dal", "soap_detergent", "personal_care", "other"]},
          "mrp_text": {"type": "string"},
          "confidence": {"type": "number"}
        },
        "required": ["image_index", "name", "brand", "size_value", "size_unit", "category", "mrp_text", "confidence"]
      }
    }
  },
  "required": ["items"]
}
```

**2. Parse an order** (`muse-spark-1.3`; input is a list photo, typed text, or a voice transcript)

System prompt carries the catalog as a compact table (`id | name | size | aliases | in_stock`), placed first so prompt caching reuses it across messages. Instructions: *The customer is ordering from this shop. Read every item they want, in English or Banglish (English names, Bangla phrasing such as "2 ta"). Match each to a product id from the catalog, or `none`. Quantities default to 1. If two products fit equally, mark the line `ambiguous` and list both ids. Do not compute prices.*

```json
{
  "type": "object",
  "properties": {
    "lines": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "requested_text": {"type": "string"},
          "qty": {"type": "number"},
          "qty_unit": {"type": "string"},
          "product_id": {"type": "string", "enum": ["<live ids>", "none"]},
          "status": {"type": "string", "enum": ["matched", "ambiguous", "not_found"]},
          "alternative_ids": {"type": "array", "items": {"type": "string"}},
          "confidence": {"type": "number"}
        },
        "required": ["requested_text", "qty", "qty_unit", "product_id", "status", "alternative_ids", "confidence"]
      }
    },
    "intent": {"type": "string", "enum": ["new_order", "modify_order", "question", "other"]}
  },
  "required": ["lines", "intent"]
}
```

**3. Transcribe a voice note** (`muse-voice-transcribe-1.0`)

- WhatsApp sends OGG/Opus; the endpoint accepts only mono 16-bit WAV at 16 or 24 kHz. Convert first: `ffmpeg -i note.ogg -ac 1 -ar 24000 -c:a pcm_s16le note.wav` ([speech to text](https://dev.meta.ai/docs/speech-to-text)).
- `POST /v1/asr/transcribe`, multipart, `mode: PUSH_TO_TALK`, `languageBias: ["Bengali", "English"]`, `keywords`: top 50 product and brand names in stock.
- The transcript then goes through contract 2 like any text order.

**4. SAM 3.1** (`sam-3.1`, Responses API, streamed)

- One request per noun prompt; input is the uploaded clip's file id plus the prompt text.
- Output is one line per frame: `<Nf>` then records of `id <|box;x1..y2;w;h|> <|mask;…data=H,W,~base85|>`. Object ids are stable across frames while an object stays in view ([reading output](https://dev.meta.ai/docs/sam/reading-segmentation)).
- Parse by key, not position; frames can be skipped; masks are base85, not base64. Use Meta's client library to decode masks rather than writing a decoder.
- Ask for `include_confidence: "true"` and drop records under 0.5.

## Endpoints and WhatsApp messages

Keep the surface small: about a dozen Laravel routes, two worker routes, one webhook. The WhatsApp test number can only reach 5 verified phones, so judges can't order on it; a public **Try ordering** page runs the exact same order pipeline from the browser, so the live product is testable by anyone.

**Laravel routes**

| Method | Path | Does |
| --- | --- | --- |
| POST | `/scans` | Upload video, create scan, dispatch job |
| GET | `/scans/{id}` | Status + progress step (polled every 2 s) |
| GET | `/scans/{id}/items` | Review cards |
| POST | `/scan-items/{id}/decision` | Confirm / reject / edit; confirm writes product + memory |
| GET | `/products` | Stock list |
| PATCH | `/products/{id}` | Edit price, qty, names |
| GET | `/orders` | Order list, newest first |
| GET | `/orders/{id}` | Order + picking route |
| POST | `/orders/{id}/packed` | Mark packed, notify customer |
| GET, POST | `/try` | Public order simulator: text, photo or audio in, invoice out |
| GET | `/webhooks/whatsapp` | Meta verification handshake |
| POST | `/webhooks/whatsapp` | Inbound messages; verify `X-Hub-Signature-256`, return 200 fast, queue the work |

**Worker routes (internal only)**

| Method | Path | Does |
| --- | --- | --- |
| POST | `/scan` | Video path in; crops, boxes, positions, embeddings out |
| POST | `/embed` | One image in, 384-d vector out (for edits and the simulator) |

**WhatsApp message design**

WhatsApp limits reply buttons to 3 and list messages to 10 rows, and the bot may only send free-form messages within 24 hours of the customer's last message. Every bot message here is a reply, so templates aren't needed.

1. **Ack** (text, sent immediately): "Got it, checking the shelf…"
2. **Clarify** (list message, only if a line is ambiguous): "Which oil do you want?" with up to 10 product rows.
3. **Invoice** (reply buttons): body lists each line as `2 × Rupchanda Soybean Oil 1L — ৳380`, then total, then "Not available: …". Buttons: **Confirm**, **Cancel**, **Change**.
4. **Confirmed** (text): order number and pickup note.
5. **Ready** (text, when merchant taps Packed).

All bot messages are in English. Prices show with ৳ so the invoice still reads local.

**Conversation states:** `idle → awaiting_clarification → awaiting_confirmation → idle`. A new list photo while awaiting confirmation replaces the draft. Button taps arrive as `interactive.button_reply.id`; encode the action and order id in that id (`confirm:123`).

## Repo, risks and build order

One public monorepo, started fresh on Oct 27, with nothing copied from Dolaaa or Sellventory. Day 1 is a go/no-go on the three model calls; if any fails, switch to its fallback that day, not later.

**Repo layout**

```text
spatialshelf/
  app/                 Laravel 12 (web app, API, webhook, jobs)
    app/Services/Meta/   MuseSpark.php, VoiceTranscribe.php
    app/Services/WhatsApp/
    app/Jobs/            ProcessScan, HandleInboundMessage
  worker/              Python FastAPI (ffmpeg, SAM 3.1, crops, DINOv2)
  prompts/             System prompts + JSON schemas, versioned
  spikes/              Day 1 test scripts and their outputs
  samples/             Demo videos, list photos, voice notes (with permission)
  Makefile/dev.ps1     starts app, queue worker, Vite and the Python worker
  README.md            What it does, setup, "Built during the hackathon" section
```

**Risks and fallbacks**

| Risk | Check on | Fallback |
| --- | --- | --- |
| SAM 3.1 misses or merges tightly packed packets | Oct 27 spike | Prompt per category (`chips packet`, `biscuit packet`); or run SAM on 5 still keyframes instead of video and dedupe by embedding |
| Muse Spark misreads small or curved labels | Oct 27 spike | Show the crop on the review card; merchant edits name; that edit becomes the memory |
| Handwriting unreadable | Oct 27 spike | Demo with clearer handwriting; keep messy list as the "asks a question" case |
| Voice Transcribe weak on Banglish | Oct 27 spike | Pass transcript plus raw audio to `muse-spark-1.2`, which accepts audio input |
| Scan takes over 60 s | Oct 30 | Process prompts in parallel; show items progressively as groups finish |
| WhatsApp token or webhook breaks on demo day | Nov 3 | Permanent system-user token; record WhatsApp clips on Nov 3, not Nov 4 |
| Stock estimates look wrong | Always | Label as estimate with "\~"; merchant edits count on the card |

**Build order**

- [ ] **Oct 27 — spikes:** API key works; SAM 3.1 on one real shelf video, overlay saved as image; Muse Spark reads 8 crops; one handwritten list photo parsed; one voice note transcribed; WhatsApp test number echoes a message through your webhook. Repo created, spikes committed.
- [ ] **Oct 28 — scan pipeline:** worker `/scan` end to end, crops on disk, positions, embeddings. Shop footage captured.
- [ ] **Oct 29 — catalog:** grouping, visual memory matching, Muse Spark batch read, `scan_items` written. Migrations + seeders.
- [ ] **Oct 30 — review UI:** upload screen with progress, swipe cards, edit, confirm into `products` + memory. Stock list.
- [ ] **Oct 31 — orders core:** contract 2 with catalog enum, PHP pricing, order + lines, `/try` simulator page.
- [ ] **Nov 1 — WhatsApp:** inbound text, photo, voice; ack, invoice buttons, confirm, stock decrement in a transaction.
- [ ] **Nov 2 — loop closers:** clarification list, picking route, Packed notification, rescan shows "Known" items, alias learning.
- [ ] **Nov 3 — ship:** deploy, record WhatsApp clips at the shop, README with "Built during the hackathon", fix the worst bugs.
- [ ] **Nov 4 — submit:** cut the 3-minute video following the demo story, check repo is public and video link opens, submit in the evening (deadline is Nov 5, 08:00 Dhaka).

## Rubric check

The four criteria are equally weighted, 0–10 each. The plan is strongest on Impact and weakest on Demo risk, so the extra work below goes into the SAM moment and making the live product hold up for judges.

| Criterion | Where we stand | What raises the score |
| --- | --- | --- |
| **Impact** | Strong: real shops, real problem, firsthand knowledge | Shopkeeper on camera using it; one real before/after ("2 days of data entry → 1 minute"); only quote statistics you can source |
| **Use of Meta AI Tooling** | Good: SAM 3.1 on video, Muse Spark, Voice Transcribe, DINOv2 | Muse Spark picks SAM's prompts (below); SAM tracking reused for shelf map and picking route; catalog ids as schema `enum`; live catalog fed to Voice Transcribe `keywords` |
| **Technical Depth & Execution** | Good on paper; depends on finishing | Measured accuracy on 5 labelled videos; stock changes in DB transactions; prompts and schemas versioned in repo; clean README |
| **Demo** | Highest risk | Mask overlay video; second-scan "Known" moment; public `/try` page and a read-only demo login so the product holds up live |

**Addition: Muse Spark chooses what SAM looks for.** Before segmenting, send one keyframe to Muse Spark and ask which kinds of packages are on this shelf ("chips packet", "oil bottle", "soap bar"). Those noun phrases become SAM 3.1's prompts. This is the pairing Meta's own docs suggest, and it turns a fixed pipeline into a two-model loop that adapts to each shelf. Show it in the video as one line on the progress screen: "Looking for: chips packets, oil bottles, soap".

**Video checklist (3 min)**

- [ ] 0:00–0:20 — the shop, the paper notebook, the problem in one sentence
- [ ] 0:20–1:00 — pan the shelf; mask overlay tracking products; review cards appear
- [ ] 1:00–1:50 — list photo on WhatsApp → invoice → confirm → stock drops; voice note order
- [ ] 1:50–2:20 — rescan: everything "Known" in seconds; picking route
- [ ] 2:20–2:45 — accuracy numbers; which Meta model does what (one screen)
- [ ] 2:45–3:00 — "Built during the hackathon" list; next step: supplier receipts for exact restock
