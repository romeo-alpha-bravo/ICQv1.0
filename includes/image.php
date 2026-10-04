<?php
declare(strict_types=1);

// Profile photo processing: validate the upload, convert it to JPEG (quality 90),
// resize to a fixed width of 800 px keeping proportions, save under a random name.
// Uses Imagick when available (required for TIFF), otherwise GD.
// Errors:
//   InvalidArgumentException - user mistake, the message is safe to show;
//   RuntimeException('Internal error') - server problem, details go to the log.
// Include with require_once.

const PHOTO_MAX_BYTES  = 10 * 1024 * 1024; // 10 MB upload limit
const PHOTO_MAX_PIXELS = 20_000_000;       // guard against decompression bombs
const PHOTO_MAX_SIDE   = 10000;            // px

// Accepted input types (detected from file content, not from name) => decoder name.
const PHOTO_TYPES = [
    IMAGETYPE_JPEG    => 'jpeg',
    IMAGETYPE_PNG     => 'png',
    IMAGETYPE_GIF     => 'gif',
    IMAGETYPE_BMP     => 'bmp',
    IMAGETYPE_TIFF_II => 'tiff',
    IMAGETYPE_TIFF_MM => 'tiff',
];

// ---------- Internal helpers (not part of the module contract) ----------

// Setting from config.php (loaded once per request).
function photo_config(string $name): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config.php';
    }
    return $cfg[$name];
}

// Logs the reason, gives the user only a generic error.
function photo_fail(string $reason): never
{
    error_log('UIN-Mail photo: ' . $reason);
    throw new RuntimeException('Internal error');
}

function photo_dir(): string
{
    return rtrim((string)photo_config('upload_dir'), '/\\');
}

// Checks the minimum width and returns the proportional height for the target width.
function photo_target_height(int $w, int $h): int
{
    $width = (int)photo_config('photo_width');
    if ($w < $width) {
        throw new InvalidArgumentException("The photo must be at least {$width} px wide.");
    }
    return max(1, (int)round($h * $width / $w));
}

// Conversion with the GD extension (JPEG, PNG, GIF, BMP).
function photo_convert_gd(string $src, string $format, string $dest): void
{
    $img = match ($format) {
        'jpeg' => @imagecreatefromjpeg($src),
        'png'  => @imagecreatefrompng($src),
        'gif'  => @imagecreatefromgif($src),
        'bmp'  => @imagecreatefrombmp($src),
    };
    if ($img === false) {
        throw new InvalidArgumentException('The image file is damaged.');
    }

    // Rotate phone photos according to EXIF orientation (GD angles are counterclockwise).
    if ($format === 'jpeg' && function_exists('exif_read_data')) {
        $exif   = @exif_read_data($src);
        $orient = is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
        $angle  = [3 => 180, 6 => 270, 8 => 90][$orient] ?? 0;
        if ($angle !== 0) {
            $rotated = imagerotate($img, $angle, 0);
            if ($rotated !== false) {
                $img = $rotated;
            }
        }
    }

    $w     = imagesx($img);
    $h     = imagesy($img);
    $newH  = photo_target_height($w, $h);
    $width = (int)photo_config('photo_width');

    $out = imagecreatetruecolor($width, $newH);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // white under transparency
    imagecopyresampled($out, $img, 0, 0, 0, 0, $width, $newH, $w, $h);

    // Re-encoding also drops all metadata (EXIF, GPS) and any hidden payload.
    if (!imagejpeg($out, $dest, (int)photo_config('photo_quality'))) {
        throw new RuntimeException('imagejpeg failed');
    }
}

// Conversion with the Imagick extension (all formats incl. TIFF).
function photo_convert_imagick(string $src, string $format, string $dest): void
{
    $im = new Imagick();
    try {
        // Force the decoder by the detected type; read only the first frame/page.
        $im->readImage($format . ':' . $src . '[0]');
    } catch (ImagickException) {
        throw new InvalidArgumentException('The image file is damaged.');
    }

    // Rotate phone photos according to EXIF orientation (Imagick angles are clockwise).
    $angle = [
        Imagick::ORIENTATION_BOTTOMRIGHT => 180,
        Imagick::ORIENTATION_RIGHTTOP    => 90,
        Imagick::ORIENTATION_LEFTBOTTOM  => 270,
    ][$im->getImageOrientation()] ?? 0;
    if ($angle !== 0) {
        $im->rotateImage('white', $angle);
    }
    $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);

    $newH = photo_target_height($im->getImageWidth(), $im->getImageHeight());

    if ($im->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
        $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
    }
    $im->setImageBackgroundColor('white');
    $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE); // transparency -> white
    $im->resizeImage((int)photo_config('photo_width'), $newH, Imagick::FILTER_LANCZOS, 1);
    $im->stripImage(); // drop EXIF, GPS and other metadata
    $im->setImageFormat('jpeg');
    $im->setImageCompressionQuality((int)photo_config('photo_quality'));
    $im->writeImage($dest);
    $im->clear();
}

// ---------- Public functions ----------

// Validates and converts an uploaded photo ($_FILES['photo']).
// Returns the stored file name (relative to upload_dir) for users.photo_path.
function process_photo(array $file): string
{
    // 1. Upload status
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Please choose a profile photo.');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('The photo is too large (max 10 MB).');
    }
    if ($error !== UPLOAD_ERR_OK) {
        photo_fail("upload error code $error");
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) {
        photo_fail('not an uploaded file');
    }
    if (filesize($tmp) > PHOTO_MAX_BYTES) {
        throw new InvalidArgumentException('The photo is too large (max 10 MB).');
    }

    // 2. Real type and resolution from the file content
    //    (the name and MIME type sent by the browser are not trusted).
    $info = @getimagesize($tmp);
    if ($info === false || !isset(PHOTO_TYPES[$info[2]])) {
        throw new InvalidArgumentException('Allowed formats: JPEG, PNG, GIF, BMP, TIFF.');
    }
    [$w, $h, $type] = $info;
    if ($w > PHOTO_MAX_SIDE || $h > PHOTO_MAX_SIDE || $w * $h > PHOTO_MAX_PIXELS) {
        throw new InvalidArgumentException('The photo resolution is too large.');
    }

    $format     = PHOTO_TYPES[$type];
    $useImagick = extension_loaded('imagick');
    if ($format === 'tiff' && !$useImagick) {
        throw new InvalidArgumentException('TIFF is not supported on this server.');
    }

    // 3. Destination with a random, unguessable name
    $dir = photo_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        photo_fail('cannot create upload directory');
    }
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $dest = $dir . DIRECTORY_SEPARATOR . $name;

    // 4. Convert; never leave a half-written file behind
    try {
        if ($useImagick) {
            photo_convert_imagick($tmp, $format, $dest);
        } else {
            photo_convert_gd($tmp, $format, $dest);
        }
    } catch (InvalidArgumentException $e) {
        if (is_file($dest)) {
            unlink($dest);
        }
        throw $e;
    } catch (Throwable $e) {
        if (is_file($dest)) {
            unlink($dest);
        }
        photo_fail('conversion failed: ' . $e->getMessage());
    }

    return $name;
}

// Deletes a stored photo (e.g. after a photo change or a failed registration).
// Only names created by process_photo() are accepted, so default.jpg
// and path traversal like "../config.php" are ignored.
function delete_photo(string $name): void
{
    if (!preg_match('/^[0-9a-f]{32}\.jpg$/', $name)) {
        return;
    }
    $path = photo_dir() . DIRECTORY_SEPARATOR . $name;
    if (is_file($path)) {
        unlink($path);
    }
}
