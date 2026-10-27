# Spike results

Throwaway day-1 probes against the Meta Model API. One row per run.

| spike | clip | phrase | unique objects | max per frame | confidence | seconds | frames billed | verdict | notes |
| ----- | ---- | ------ | -------------- | ------------- | ---------- | ------- | ------------- | ------- | ----- |
| 1.1 | samples/shelf_bottles.mp4 | bottle | 16 | 16 | 0.927 | 19.7 | 120 | works, but video mode caps at 16 objects — need image mode for detection | It marked some bottole twice once, marked same product with different color bottle many times. Otherwise it looks good. |
| 1.2 | samples/shelf_bottles.mp4 | bottle | 295 total (83 best frame) | 83 | 0.766 weighted | 16.3 sum of frame calls | n/a (usage not captured) | image mode returns every match — best frame 83 vs video-mode 16; use image mode for detection | per-frame instances 29/73/83/71/31/8; last frame panned to jars section (8 @ 0.589); "bottle" also matches jars and cans |
| 1.4 | frame_144.jpg, 8 crops | n/a (crop read) | 8 crops read, 5 confidently named | n/a | 0.60 mean model conf | n/a (not printed) | n/a | names right on clear labels; size missed on all 8, needs size fallback | crops 1,2,7,8 correct incl brands; 4-6 low-conf on unreadable labels per contract; Olitalia twice equals two facings, dedup later |
