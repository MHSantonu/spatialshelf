# Spike results

Throwaway day-1 probes against the Meta Model API. One row per run.

## 1.7 build decisions

| area | decision |
| ---- | -------- |
| Detector | SAM image mode on keyframes is the main detector; video mode only for the overlay |
| Phrase picker | Muse Spark picks SAM phrases — keep |
| Crops | Crop from full-res frames; size comes from the merchant |
| Order parsing | id-enum plus ambiguity plus fuzzy rules — as designed |
| WhatsApp | as designed |

## Spike runs

| spike | clip | phrase | unique objects | max per frame | confidence | seconds | frames billed | verdict | notes |
| ----- | ---- | ------ | -------------- | ------------- | ---------- | ------- | ------------- | ------- | ----- |
| 1.1 | samples/shelf_bottles.mp4 | bottle | 16 | 16 | 0.927 | 19.7 | 120 | works, but video mode caps at 16 objects — need image mode for detection | It marked some bottole twice once, marked same product with different color bottle many times. Otherwise it looks good. |
| 1.2 | samples/shelf_bottles.mp4 | bottle | 295 total (83 best frame) | 83 | 0.766 weighted | 16.3 sum of frame calls | n/a (usage not captured) | image mode returns every match — best frame 83 vs video-mode 16; use image mode for detection | per-frame instances 29/73/83/71/31/8; last frame panned to jars section (8 @ 0.589); "bottle" also matches jars and cans |
| 1.4 | frame_144.jpg, 8 crops from 720p frame | n/a (crop read) | 8 crops, B names all 8 | n/a | B 0.59 vs A 0.52 (4/8 at 0.75 or above) | n/a (not printed) | n/a | works with merchant review — crop from full-res original frames, film the real shop in 4K close up; size comes from the merchant on the review card (or a learned product default), not from the model | 8 crops about 100x240 px; variant B (25% padding, no white mask, 2x upscale) better; sizes unknown on all 16 reads, mostly not printed on the front face |
| 1.4b | frame_144.jpg, same 8, A vs B | n/a (crop read) | B names 7 of 8, A names 5 of 8 | n/a | A 0.52 vs B 0.59 mean conf | n/a (not printed) | n/a | B wins: context plus upscale reads 2 more labels; sizes missed in all 16 reads, needs non-visual fallback | B files 4-5x larger; printed B px wrongly duplicated A px (aliased lists, fixed); both response.json saved |
| 1.5 | list_1.jpg, list_2_rotated.jpg, voice_1.m4a, voice_2.m4a | n/a (Contract 2 order parse, 15-product catalog) | 15 lines: 7 matched, 7 ambiguous, 0 not_found | n/a | fuzzy matches 0.6-0.7 as instructed (not printed on invoice) | n/a | n/a | works — both 1.5b prompt fixes behave as specified | Totals: list_1 ৳3100 (2 matched), list_2_rotated ৳55 (2 matched), voice_1 ৳1800 (1 matched), voice_2 ৳60 (2 matched). Fix 1: "Tomato Sauce" now ambiguous (p06 Ketchup vs p07 Hot Sauce) instead of silently matched; "Tang"/"Horlicks"/"mango" ambiguous with all fitting ids. Fix 2: "Phato"->p08 Frooto matched at conf 0.6, "Frutica"->p09 matched at 0.7. EXIF transpose reads sideways list_2; voice_2 repeats "one bottle Frutika" parsed as 2 lines; all raw responses in spikes/out/orders/ |
| 1.4c | samples/newvideos/VID_20261027_181256.mp4 (Horlicks shelf, 4K portrait, 62 frames) | product package | 67 total (27 best frame 61) | 27 | 0.561/0.573/0.594 mean per frame; only 0/1/4 boxes >= 0.7 | 4.4/5.0/5.3 per frame | n/a (usage not captured) | 4K crops read better, detection doesn't — "product package" too generic for this shelf | 1280px copy made upright (1280x2276, rotation baked in); boxes scaled 1.6875x into full 2160x3840 frame_61; 4 crops 470-840 px, 4/4 named (Horlicks x2, Mother's PLUS 0.92, Kishowan 0.40), mean conf 0.71 vs 0.59 at 720p; sizes still unknown on all reads → size comes from merchant; 27 SAM instances but only 4 usable → 1.3 phrase picker |
| 1.3 | frame_61.jpg (Horlicks shelf, upright 1280px) | spark: plastic jar, juice carton, foil packet, cardboard box vs product package | merged 88 (53 dupes dropped at IoU>0.6) | 49 (plastic jar) | jar 0.772 / carton 0.770 / box 0.714 / baseline 0.594 | 3.2-5.2 per phrase call | n/a (usage not captured) | phrase picker works — keep it | best phrase 40 boxes >= 0.7 vs baseline 4; foil packet correctly 0. Overlay: Horlicks/Junior Horlicks/Vita Malt jars well covered by plastic jar; Tang/Glucomax-D/Sajeeb cartons caught by juice carton + cardboard box; Mother's PLUS carton and Kishowan pack detected. Same jar/carton often keeps 2-5 nested boxes across phrases (Vita Malt jars x4-5 at 0.88, Tang carton with juice-carton 0.85 + cardboard-box 0.61) — IoU>0.6 misses containment. Phase 3 dedupe should also merge boxes where one is >= 70% inside another (containment), not only IoU, before the DINOv2 grouping. |
| 1.6 | n/a (live webhook via cloudflared tunnel, spikes/wa_echo.py on port 8001) | n/a | text, image, audio replies; status updates ignored | n/a | n/a | n/a (not measured) | n/a | works end to end — text echo, image and audio download | Fixes needed: link the WABA to the app with POST /{waba}/subscribed_apps; Graph v26.0; log Meta's error body. GET /webhook verifies hub.challenge; POST verifies X-Hub-Signature-256, appends to spikes/out/wa_log.jsonl, saves media to spikes/out/media/ |
