# MyTube app icon

`app-icon.png` is the original, opaque 1254 × 1254 master generated with the
built-in ImageGen tool on November 19, 2026. It extends the existing coral-red
and white play-mark identity. Keep the master here; deployable sizes live in
`public/` and are committed so production needs no image tooling.

Regenerate with ImageMagick:

```sh
sh scripts/generate-icons.sh
```

Exports:

| Asset | Use |
| --- | --- |
| `public/favicon.ico` | Browser fallback, 16/32/48 px |
| `public/favicon-32x32.png` | Browser tabs and bookmarks |
| `public/apple-touch-icon.png` | iPhone/iPad home screens, 180 px |
| `public/icons/icon-192.png` | App header and web app manifest |
| `public/icons/icon-512.png` | Android and desktop web app installation |

The 512 px icon serves both `any` and `maskable` purposes: its background is
opaque and full bleed, and the entire white play mark stays inside the centered
circle of radius 40% of the image width. Let the operating system apply its
own rounded-square, circle, or other mask. Do not bake rounded corners into
these exports. `public/safari-pinned-tab.svg` is a separate monochrome vector
play silhouette for Safari pinned tabs.

The manifest uses origin-relative URLs so each self-hosted installation keeps
its own identity and launches `/`. Browser chrome follows the app's chosen
light/dark appearance. No offline media caching is configured.

## Generation prompt

```text
Use case: logo-brand
Asset type: production master app icon for MyTube, a personal video library web app; used on iPhone, Android, macOS, Windows, browser tabs, and installed web apps.
Primary request: create one beautifully precise, minimal app icon that develops the app's existing identity: a white right-pointing play triangle on a warm coral-red field.
Scene/backdrop: square 1024x1024 canvas, completely opaque edge-to-edge warm red background, subtle smooth diagonal gradient from coral #ff6b5e at top-left to deep red #e0241b at bottom-right. Full bleed, no rounded outer corners, no outside margin, no surrounding page or mockup.
Subject: one bold, solid warm-white right-facing play triangle, with gently rounded vertices, optically centered. Triangle fits entirely inside the central 56% of the square in both directions for Android maskable safe area, strong readable silhouette at 16px. Tasteful exceptionally subtle soft shadow under the triangle to add depth; otherwise flat and clean. The triangle itself is the entire mark.
Style/medium: refined geometric app identity with crisp vector-like edges and restrained gradient depth, contemporary video app.
Text: no lettering.
Constraints: exactly one finished icon, square head-on view; background fills every pixel, including corners; no frame, no rounded square tile within the image, no extra symbols, no borders, no device, no watermark, no text, no elaborate texture, no YouTube wordmark.
```
