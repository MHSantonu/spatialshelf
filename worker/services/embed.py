"""Image embeddings via DINOv2 (facebook/dinov2-small, torch CPU).

The model loads once (thread-safe), runs in eval mode on CPU under
torch.no_grad, and returns the L2-normalized CLS token (384 floats).

No spike covers this; written fresh against transformers + torch.
All paths are relative to SHARED_STORAGE_PATH unless absolute.
"""

from __future__ import annotations

import threading
import time
from pathlib import Path

import torch
from PIL import Image
from transformers import AutoImageProcessor, AutoModel

MODEL_ID = "facebook/dinov2-small"
EMBED_DIM = 384
BATCH_SIZE = 16

_model = None
_processor = None
_lock = threading.Lock()


def ensure_model():
    """Load the model once; return (model, processor). Thread-safe."""
    global _model, _processor
    if _model is None:
        with _lock:
            if _model is None:
                processor = AutoImageProcessor.from_pretrained(MODEL_ID)
                model = AutoModel.from_pretrained(MODEL_ID)
                model.eval()
                _processor, _model = processor, model
    return _model, _processor


def is_loaded() -> bool:
    return _model is not None


def _as_image(image) -> Image.Image:
    if isinstance(image, Image.Image):
        return image.convert("RGB")
    with Image.open(image) as handle:
        return handle.convert("RGB")


def embed_batch(images: list) -> list[list[float]]:
    """CLS embeddings for a batch of images/paths; logs wall time."""
    model, processor = ensure_model()
    pil_images = [_as_image(image) for image in images]
    started = time.perf_counter()
    inputs = processor(images=pil_images, return_tensors="pt")
    with torch.no_grad():
        outputs = model(**inputs)
    cls = outputs.last_hidden_state[:, 0, :]
    normed = cls / cls.norm(dim=1, keepdim=True).clamp(min=1e-12)
    seconds = time.perf_counter() - started
    print(
        f"[embed] batch of {len(pil_images)} in {seconds:.2f}s "
        f"({1000 * seconds / len(pil_images):.0f} ms/image)"
    )
    return normed.cpu().tolist()


def embed(image) -> list[float]:
    """CLS embedding of one image/path as 384 L2-normalized floats."""
    return embed_batch([image])[0]
