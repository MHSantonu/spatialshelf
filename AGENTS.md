# SpatialShelf — agent context

SpatialShelf turns a phone video of a shop shelf into a live catalog that customers order from on WhatsApp
(typed text, a photo of a handwritten list, or a voice note). Built for the Meta Global AI Developer Hackathon
(Oct 27 – Nov 4, 2026). Everything in this repo is new work done during the hackathon.

## Stack (no Docker)
- app/: Laravel 13, PHP 8.3+, Livewire 4, Tailwind, Vite, Pest. Runs with `php artisan serve`.
  The Laravel project lives in app/, so its own code is in app/app/. "app/Services/..." in a prompt
  means inside the Laravel project.
- Queues: database driver. Run `php artisan queue:work --queue=scans,whatsapp,default`.
- worker/: Python 3.11 FastAPI in worker/.venv, run with uvicorn on 127.0.0.1:8080. ffmpeg, OpenCV,
  meta-sam-parser, DINOv2 (torch CPU, facebook/dinov2-small).
- MySQL 8, utf8mb4. Embeddings are stored as JSON arrays of 384 floats; similarity is computed in PHP
  (one shop has at most a few thousand product images, so brute-force cosine is fast enough).
- storage-shared/ at the repo root is read and written by both Laravel and the worker (videos, crops, audio).
  Both get its absolute path from SHARED_STORAGE_PATH in the root .env.
- Meta Model API, base URL https://api.meta.ai/v1, OpenAI-compatible. Models: sam-3.1, muse-spark-1.3,
  muse-voice-transcribe-1.0. WhatsApp Cloud API (Graph v21.0).
- The developer works on Windows with PhpStorm. Provide PowerShell equivalents for shell scripts.

## Rules
- Models never do arithmetic or write to the DB. They return JSON via response_format json_schema; PHP validates,
  prices and saves.
- All Meta calls go through app/Services/Meta/* (PHP) or worker/services/* (Python). No model calls in controllers.
- Prompts and JSON schemas live in prompts/ as files, loaded at runtime. Never inline them.
- Store every raw model response (scans.stats, orders.context) for debugging and the demo.
- Stock changes only inside a DB transaction with lockForUpdate.
- UI and bot text are English. Prices in Taka with the ৳ sign.
- Keep it simple: one shop, one merchant. No multi-tenant auth, payments, or delivery.
- Write Pest tests for services that parse or price. Mock HTTP with Http::fake.
- Never print, log or commit secrets from .env.
- Show commands before running anything that installs packages or changes the database.
- When docs/spec.md and the prompt you were given disagree, follow the prompt and tell me about the difference.

## SAM 3.1 facts that matter
- One noun phrase per request. Comma lists ("bottle, packet") return nothing.
- Video tracking follows at most 16 objects per frame; image requests return every match.
- Video input: MP4/MOV, H.264/H.265. Upload once with the Files API (purpose "user_data"), reuse the file_id.
- Metadata values are strings: {"include_confidence": "true", "mask_encoding": "one_bit"}.
- Parse output with meta_sam_parser (Python) or @meta-sam/parser (TypeScript). Masks are base85, not base64.

## Docs
- SAM: https://dev.meta.ai/docs/sam/segmenting , https://dev.meta.ai/docs/sam/reading-segmentation ,
  https://dev.meta.ai/docs/sam/client-libraries
- Structured output: https://dev.meta.ai/docs/structured-output
- Speech to text: https://dev.meta.ai/docs/speech-to-text
- Build spec: docs/spec.md
