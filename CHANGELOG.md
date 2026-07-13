# Changelog

All notable changes to `nylo/smalljson` are documented here.

## 1.0.0 — 2026-07-06

Initial release.

- SmallJson wire format v1: key-table structural packing (`p`) and
  deflate (`z`) modes.
- `response()->smallJson()` macro mirroring `response()->json()` semantics.
- `smalljson` route middleware that re-encodes any `JsonResponse` for
  advertising clients with zero controller changes.
- Content negotiation via the `X-Small-Json` request header, with automatic
  plain-JSON fallback (including when encoding would not shrink the payload)
  and `Vary` handling for shared caches.
- `SmallJson` facade with `encode()` / `decode()` for manual use; the codec is
  framework-free.
- Optional `X-Small-Json-Stats` response header.
- Cross-implementation test vectors shared with the `smalljson` Dart package.
