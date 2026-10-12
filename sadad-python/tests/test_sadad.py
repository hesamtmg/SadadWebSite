from datetime import datetime

import pytest

from app.sadad import SadadError, decode_key, local_datetime, sign_data

# Vectors produced by Sadad's C# MakeSign sample (TripleDES, ECB, PKCS7, UTF8).
KEY = "prK/jNrK/zPp/lASBVyCRFMcx8y5dFUi"


@pytest.mark.parametrize("text, expected", [
    ("24095674;1001;1250000", "6/5zC6mPoyX0bTyaKyBo28AbIERIj9oP"),
    ("24095674;987654321;124950000", "6/5zC6mPoyU9i1EwGfOWDTPWTvjpo53NWyj345RzM1E="),
    ("8D2F1C77A4E09B3C5D6E7F80", "2/S4taCdoFSFV9wGlJn0MHPcZ7xCMJ5L78k++F3Hk7E="),
    ("3F9A2B7C1D0E4F5A6B7C8D9E0F1A2B3C", "B1IHgP/LaMyGI+aSY1U/mxNiV9DV732E4odbh0Z4ccfvyT74XceTsQ=="),
])
def test_sign_data_matches_csharp_makesign(text, expected):
    assert sign_data(text, KEY) == expected


def test_rejects_bad_keys():
    with pytest.raises(SadadError):
        decode_key("not base64!")
    with pytest.raises(SadadError):
        decode_key("AAAAAAAAAAAAAAAAAAAAAA==")  # 16 bytes
    assert len(decode_key(f" {KEY}\n")) == 24


def test_local_datetime_tehran_format():
    assert local_datetime(datetime.fromisoformat("2026-03-20T20:30:00+00:00")) == "03/21/2026 12:00:00 AM"
    assert local_datetime(datetime.fromisoformat("2026-07-01T10:15:05+00:00")) == "07/01/2026 01:45:05 PM"
