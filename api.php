<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ignore_user_abort(false); // Stop script if client disconnects
header('Content-Type: application/json');

// Load config
if (!file_exists(__DIR__ . '/config.php')) {
    echo json_encode(['error' => 'config.php not found. Copy config.sample.php to config.php']);
    exit;
}
require_once __DIR__ . '/config.php';

$cacheDir = __DIR__ . '/cache';

if (!is_dir($cacheDir)) mkdir($cacheDir, 0775, true);

// Get image list (cached)
function getImageList() {
    global $uploadsDir;
    $listFile = __DIR__ . '/image_list.json';
    $lockFile = __DIR__ . '/image_list.lock';

    if (file_exists($listFile) && filemtime($listFile) > time() - 3600) {
        $raw = file_get_contents($listFile);
        if ($raw !== false) {
            $cached = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }
    }

    $lockHandle = fopen($lockFile, 'c');
    if ($lockHandle === false) {
        throw new RuntimeException('Unable to open image list lock file');
    }

    try {
        if (!flock($lockHandle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock image list cache');
        }

        clearstatcache(true, $listFile);
        if (file_exists($listFile) && filemtime($listFile) > time() - 3600) {
            $raw = file_get_contents($listFile);
            if ($raw !== false) {
                $cached = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($cached) && !empty($cached)) {
                    return $cached;
                }
            }
        }

        $images = [];
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iter as $file) {
            if ($file->isFile() && $file->getSize() > 20000) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                    $images[] = $file->getPathname();
                }
            }
        }

        shuffle($images);
        $images = array_slice($images, 0, 500);

        $json = json_encode($images);
        if ($json === false) {
            throw new RuntimeException('Unable to encode image list cache');
        }

        $tmpFile = tempnam(__DIR__, 'image_list.');
        if ($tmpFile === false) {
            throw new RuntimeException('Unable to create temp cache file');
        }

        $written = file_put_contents($tmpFile, $json, LOCK_EX);
        if ($written !== strlen($json)) {
            @unlink($tmpFile);
            throw new RuntimeException('Unable to write complete image list cache');
        }

        if (!rename($tmpFile, $listFile)) {
            @unlink($tmpFile);
            throw new RuntimeException('Unable to replace image list cache');
        }

        return $images;
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function formatSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 2) . ' MB';
}

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $images = getImageList();
    echo json_encode(['total' => count($images)]);
    exit;
}

if ($action === 'get') {
    $index = max(1, min(500, intval($_GET['i'] ?? 1)));
    $images = getImageList();

    if ($index > count($images)) {
        echo json_encode(['error' => 'Invalid index']);
        exit;
    }

    $path = $images[$index - 1];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    // Copy original to cache if needed
    $origCached = $cacheDir . '/img_' . $index . '_orig.' . $ext;
    if (!file_exists($origCached)) {
        copy($path, $origCached);
    }

    echo json_encode([
        'index' => $index,
        'path' => $path,
        'name' => basename($path),
        'ext' => $ext,
        'size' => filesize($path),
        'orig_url' => 'cache/img_' . $index . '_orig.' . $ext
    ]);
    exit;
}

if ($action === 'convert') {
    $index = max(1, min(500, intval($_GET['i'] ?? 1)));
    $format = $_GET['format'] ?? 'avif';
    $quality = max(10, min(100, intval($_GET['q'] ?? 50)));

    $images = getImageList();
    if ($index > count($images)) {
        echo json_encode(['error' => 'Invalid index']);
        exit;
    }

    $srcPath = $images[$index - 1];
    $srcExt = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    $origSize = filesize($srcPath);

    // Determine output extension and cache key
    $cacheKey = "img_{$index}_{$format}_q{$quality}";

    switch ($format) {
        case 'avif':
            $outExt = 'avif';
            $outFile = $cacheDir . '/' . $cacheKey . '.avif';
            $cmd = "/usr/bin/avifenc -q {$quality} -s 6 " . escapeshellarg($srcPath) . " " . escapeshellarg($outFile) . " 2>&1";
            break;

        case 'webp':
            $outExt = 'webp';
            $outFile = $cacheDir . '/' . $cacheKey . '.webp';
            $cmd = "/usr/bin/cwebp -q {$quality} " . escapeshellarg($srcPath) . " -o " . escapeshellarg($outFile) . " 2>&1";
            break;

        case 'pngquant':
            if (!in_array($srcExt, ['png'])) {
                echo json_encode(['error' => 'pngquant only works on PNG files']);
                exit;
            }
            $outExt = 'png';
            $outFile = $cacheDir . '/' . $cacheKey . '.png';
            $minQ = max(0, $quality - 10);
            $cmd = "/usr/bin/pngquant --quality={$minQ}-{$quality} --force --output " . escapeshellarg($outFile) . " " . escapeshellarg($srcPath) . " 2>&1";
            break;

        case 'jpegoptim':
            if (!in_array($srcExt, ['jpg', 'jpeg'])) {
                echo json_encode(['error' => 'jpegoptim only works on JPEG files']);
                exit;
            }
            $outExt = 'jpg';
            $outFile = $cacheDir . '/' . $cacheKey . '.jpg';
            // Copy first, then optimize in place
            copy($srcPath, $outFile);
            $cmd = "/usr/bin/jpegoptim -m{$quality} --strip-all " . escapeshellarg($outFile) . " 2>&1";
            break;

        case 'cjpeg':
            if (!in_array($srcExt, ['jpg', 'jpeg'])) {
                echo json_encode(['error' => 'cjpeg only works on JPEG files']);
                exit;
            }
            $outExt = 'jpg';
            $outFile = $cacheDir . '/' . $cacheKey . '.jpg';
            // Decode with djpeg, re-encode with cjpeg at target quality
            $cmd = "/usr/bin/djpeg " . escapeshellarg($srcPath) . " | /usr/bin/cjpeg -quality {$quality} -optimize -outfile " . escapeshellarg($outFile) . " 2>&1";
            break;

        case 'optipng':
            if (!in_array($srcExt, ['png'])) {
                echo json_encode(['error' => 'optipng only works on PNG files']);
                exit;
            }
            $outExt = 'png';
            $outFile = $cacheDir . '/' . $cacheKey . '.png';
            copy($srcPath, $outFile);
            $cmd = "/usr/bin/optipng -o2 " . escapeshellarg($outFile) . " 2>&1";
            break;

        default:
            echo json_encode(['error' => 'Unknown format']);
            exit;
    }

    // Convert if not cached
    if (!file_exists($outFile) || filesize($outFile) == 0) {
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        if (!file_exists($outFile) || filesize($outFile) == 0) {
            echo json_encode([
                'error' => 'Conversion failed',
                'cmd' => $cmd,
                'output' => implode("\n", $output),
                'code' => $code
            ]);
            exit;
        }
    }

    $outSize = filesize($outFile);
    $savings = round(100 - ($outSize * 100 / $origSize));

    // Calculate DSSIM if possible
    $dssim = null;
    $dssimFile = $cacheDir . '/' . $cacheKey . '.dssim';

    if (!file_exists($dssimFile)) {
        // Decode to PNG for comparison if needed
        $cmpFile = $cacheDir . '/' . $cacheKey . '_cmp.png';

        if ($outExt === 'avif') {
            exec("/usr/bin/avifdec " . escapeshellarg($outFile) . " " . escapeshellarg($cmpFile) . " 2>&1");
        } elseif ($outExt === 'webp') {
            exec("/usr/bin/dwebp " . escapeshellarg($outFile) . " -o " . escapeshellarg($cmpFile) . " 2>&1");
        } else {
            $cmpFile = $outFile;
        }

        if (file_exists($cmpFile)) {
            $dssimOut = shell_exec("/usr/bin/dssim " . escapeshellarg($srcPath) . " " . escapeshellarg($cmpFile) . " 2>&1");
            $dssim = floatval(preg_replace('/\s.*/', '', trim($dssimOut)));
            file_put_contents($dssimFile, $dssim);

            if ($cmpFile !== $outFile) {
                @unlink($cmpFile);
            }
        }
    } else {
        $dssim = floatval(file_get_contents($dssimFile));
    }

    echo json_encode([
        'format' => $format,
        'quality' => $quality,
        'url' => 'cache/' . $cacheKey . '.' . $outExt,
        'size' => $outSize,
        'orig_size' => $origSize,
        'savings' => $savings,
        'dssim' => $dssim
    ]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
