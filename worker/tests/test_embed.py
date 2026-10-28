"""Unit tests for services.embed (local model, no network)."""

import math

import numpy as np
import pytest
from PIL import Image

from services.embed import EMBED_DIM, embed, embed_batch, is_loaded


def gradient(seed: int) -> Image.Image:
    rng = np.random.default_rng(seed)
    return Image.fromarray(
        (rng.random((224, 224, 3)) * 255).astype("uint8"), mode="RGB"
    )


def norm(vector: list[float]) -> float:
    return math.sqrt(sum(v * v for v in vector))


def test_embed_returns_384_normalized_floats():
    vector = embed(gradient(1))
    assert len(vector) == EMBED_DIM == 384
    assert all(isinstance(v, float) for v in vector)
    assert norm(vector) == pytest.approx(1.0, abs=1e-5)
    assert is_loaded()


def test_embed_batch_matches_singles():
    images = [gradient(1), gradient(2), gradient(3)]
    batched = embed_batch(images)
    singles = [embed(image) for image in images]
    assert len(batched) == 3
    for row, single in zip(batched, singles):
        assert row == pytest.approx(single, abs=1e-6)


def test_distinct_images_give_distinct_vectors():
    first, second = embed_batch([gradient(1), gradient(2)])
    assert first != pytest.approx(second, abs=1e-3)


def test_embed_accepts_paths(tmp_path):
    path = tmp_path / "crop.jpg"
    gradient(4).save(path)
    vector = embed(path)
    assert len(vector) == EMBED_DIM
    assert norm(vector) == pytest.approx(1.0, abs=1e-5)
