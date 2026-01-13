# Image Format Comparison Tool

A web-based tool for comparing image compression formats (AVIF, WebP, PNG, JPEG) side-by-side with quality metrics.

## Features

- **Multiple formats**: AVIF, WebP, PNGquant, cjpeg (libjpeg-turbo), OptiPNG
- **Quality slider**: Adjust compression quality from 10-100
- **DSSIM metric**: Visual quality difference measurement
- **Side-by-side comparison**: Original vs compressed with synced zoom/pan
- **Hold-to-compare**: Quick toggle between original and compressed
- **URL persistence**: Share specific image/format/quality combinations
- **Keyboard shortcuts**: Arrow keys for navigation, R for random, Z for zoom reset, Space for compare

## Requirements

- PHP 7.4+ with exec() enabled
- Image tools:
  - `avifenc` / `avifdec` (libavif)
  - `cwebp` / `dwebp` (libwebp)
  - `pngquant`
  - `optipng`
  - `cjpeg` / `djpeg` (libjpeg-turbo)
  - `dssim`

### Alpine Linux

```bash
apk add libavif-apps libwebp-tools pngquant optipng libjpeg-turbo-utils dssim
```

### Ubuntu/Debian

```bash
apt install libavif-bin webp pngquant optipng libjpeg-turbo-progs
# dssim needs to be compiled from source or installed via cargo
```

## Installation

1. Clone the repository
2. Copy `config.sample.php` to `config.php`
3. Edit `config.php` to set `$uploadsDir` to your images directory
4. Ensure the `cache/` directory is writable by the web server
5. Access via web browser

## Usage

- Use arrow keys or buttons to navigate images
- Select format and adjust quality
- Scroll/pinch to zoom, drag to pan (synced on both sides)
- Hold "Compare" button or spacebar to see original on right side
- Double-click to reset zoom

## License

MIT
