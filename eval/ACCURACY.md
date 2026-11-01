# SpatialShelf accuracy notes

Small-n day-1 probe numbers, transcribed from [spikes/RESULTS.md](../spikes/RESULTS.md)
(Oct 27, 2026). This is not a formal benchmark: no hand-labelled ground truth,
no held-out set. Every number below traces to one spike row; the raw runs live
in `spikes/out/`. `eval/labels/` and `eval/orders/` are reserved for labelled
sets if we build them post-hackathon.

## Detection (SAM 3.1)

Clip: `samples/shelf_bottles.mp4`, phrase `bottle`.

| Mode | Total instances | Best frame | Mean conf | Time | Verdict |
|---|---|---|---|---|---|
| Video (row 1.1) | 16 unique | 16 | 0.927 | 19.7 s (120 frames billed) | works, but caps at 16 tracked objects |
| Image, 6 keyframes (row 1.2) | 295 | 83 | 0.766 weighted | 16.3 s summed | best frame 83 vs video-mode 16 |

Per-frame image counts: 29 / 73 / 83 / 71 / 31 / 8. The last frame panned to
the jars section (8 @ 0.589); `bottle` also matches jars and cans, and one
bottle was double-marked. Decision: image mode detects, video mode only
renders the overlay player.

Second shelf: `samples/newvideos/VID_20261027_181256.mp4` (Horlicks, 4K
portrait, row 1.4c), phrase `product package`: 67 total, 27 best frame,
means 0.561 / 0.573 / 0.594, only 0 / 1 / 4 boxes ≥ 0.7. Too generic —
this shelf motivated the phrase picker.

## Phrase picker (Muse Spark → SAM)

Frame 61 of the Horlicks shelf (row 1.3). Spark-proposed phrases vs the
`product package` baseline:

| Phrase set | Boxes ≥ 0.7 (best phrase) | Mean conf |
|---|---|---|
| `plastic jar, juice carton, foil packet, cardboard box` | 40 | jar 0.772 / carton 0.770 / box 0.714 |
| `product package` baseline | 4 | 0.594 |

Merged 88 instances after dropping 53 IoU>0.6 duplicates. Known weakness:
nested boxes across phrases (one jar keeps 4–5 boxes); the pipeline also
merges containment (one box ≥70% inside another), not just IoU.

## Crop reading (Muse Spark, contract 1)

| Run | Crops | Named | Mean conf | Sizes read |
|---|---|---|---|---|
| 720p, variant B, 8 crops (row 1.4) | ~100×240 px | 8/8 | 0.59 (4/8 ≥ 0.75) | 0/16 across both variants |
| 720p A-vs-B rerun (row 1.4b) | same 8 | B 7/8, A 5/8 | B 0.59 vs A 0.52 | 0/16 |
| 4K, variant B, 4 crops (row 1.4c) | 470–840 px | 4/4 | 0.71 | 0/4 |

Variant B (25% padding, no white mask, 2× upscale) beats A twice. 4K crops
read better than 720p (0.71 vs 0.59) but sizes are rarely printed on the
front face — size comes from the merchant on the review card, never from
the model. Pipeline decision: crop from full-res frames, merchant owns size.

## Order parsing (Muse Spark, contract 2)

15-product catalog, 4 inputs (row 1.5): `list_1.jpg`,
`list_2_rotated.jpg` (sideways, EXIF-transposed), `voice_1.m4a`,
`voice_2.m4a`. Totals: 7 matched, 7 ambiguous, 0 silently wrong.

| Input | Invoice total | Matched |
|---|---|---|
| list_1 | ৳3100 | 2 |
| list_2_rotated | ৳55 | 2 |
| voice_1 | ৳1800 | 1 |
| voice_2 | ৳60 | 2 (+1 repeat line: "one bottle Frutika" parsed as 2) |

Behaviour checks that passed: `Tomato Sauce` goes ambiguous (Ketchup vs
Hot Sauce) instead of silently matching; `Tang` / `Horlicks` / `mango`
list all fitting ids; fuzzy `Phato`→Frooto at 0.6 and `Frutica`→Frutica
at 0.7. Raw responses in `spikes/out/orders/`.

## WhatsApp echo (row 1.6)

Live webhook via tunnel: text echo, image + audio download, status updates
ignored. Functionality check only, no accuracy metric. Fixes logged:
WABA→app link via `subscribed_apps`, Graph v26.0, log Meta's error body.

## Gaps (honest)

- No precision/recall against hand-drawn boxes; detection "counts" are
  SAM's own instances, not scored matches.
- Order parsing is n=4 inputs on a 15-product catalog — direction, not proof.
- Voice 1.5 rows used transcripts through contract 2; end-to-end
  audio→invoice accuracy on noisy shop audio is unmeasured.
