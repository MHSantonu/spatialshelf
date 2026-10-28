"""Unit tests for services.phrases sanitizing (no API calls)."""

from services.phrases import sanitize_phrases


def test_strips_brand_words_keeping_generic_type():
    assert sanitize_phrases(["Horlicks jar", "Tang box"]) == ["jar", "box"]


def test_drops_phrases_with_nothing_generic_left():
    assert sanitize_phrases(["Tang", "Vita Malt"]) == []


def test_leaves_packaging_phrases_alone():
    phrases = ["plastic jar", "cardboard box", "drink packet"]
    assert sanitize_phrases(phrases) == phrases


def test_catches_mid_phrase_and_all_caps_brands():
    assert sanitize_phrases(["mango TANG box", "GlucoMaxD box"]) == [
        "mango box",
        "box",
    ]


def test_dedupes_after_stripping():
    assert sanitize_phrases(["Horlicks jar", "Vita jar", "jar"]) == ["jar"]
