<?php
namespace APP\plugins\generic\responsiveImages\classes;

use APP\plugins\generic\responsiveImages\ResponsiveImagesPlugin;
use PKP\core\PKPRequest;

class ImageVariantGenerator
{
    private object $plugin;
    private PKPRequest $request;
    private int $contextId;
    private array $settings;

    public function __construct(object $plugin, PKPRequest $request, int $contextId, array $settings)
    {
        $this->plugin = $plugin;
        $this->request = $request;
        $this->contextId = $contextId;
        $this->settings = $settings;
    }

    public function generate(array $image): ?array
    {
        $source = $image['path'];
        if (!is_readable($source)) {
            return null;
        }
        $variantDir = dirname($source) . '/' . ResponsiveImagesPlugin::VARIANT_DIRNAME;
        if (!is_dir($variantDir) && !mkdir($variantDir, 0755, true) && !is_dir($variantDir)) {
            throw new \RuntimeException('Could not create variant directory: ' . $variantDir);
        }

        $basename = pathinfo($source, PATHINFO_FILENAME);
        $variants = [];
        $sourceMTime = (int) filemtime($source);
        $formats = $this->getTargetFormats();

        foreach ($this->settings['widths'] as $width) {
            $width = (int) $width;
            if ($width >= (int) $image['width']) {
                continue;
            }
            foreach ($formats as $format) {
                $target = $variantDir . '/' . $basename . '-' . $width . '.' . $format;
                if (!is_file($target) || (int) filemtime($target) < $sourceMTime) {
                    $this->resizeToFormat($source, $target, $width, (int) $this->settings['quality'], $format);
                }
                $variants[] = [
                    'url' => $this->pathToUrl($target),
                    'width' => $width,
                    'format' => $format,
                    'mime' => 'image/' . $format,
                ];
            }
        }

        if (!$variants) {
            return null;
        }

        return [
            'sourceUrl' => $image['url'],
            'width' => (int) $image['width'],
            'height' => (int) $image['height'],
            'sourceMTime' => $sourceMTime,
            'isCover' => (bool) $image['isCover'],
            'variants' => $variants,
            'formats' => array_values(array_unique(array_column($variants, 'format'))),
            'generatedAt' => date('c'),
        ];
    }

    private function getTargetFormats(): array
    {
        $formats = [];
        if (!empty($this->settings['generateAvif']) && $this->supportsAvif()) {
            $formats[] = 'avif';
        }
        if (!empty($this->settings['generateWebp']) && $this->supportsWebp()) {
            $formats[] = 'webp';
        }
        return $formats ?: ['webp'];
    }

    private function supportsWebp(): bool
    {
        return extension_loaded('imagick') || function_exists('imagewebp');
    }

    private function supportsAvif(): bool
    {
        if (extension_loaded('imagick')) {
            try {
                return in_array('AVIF', \Imagick::queryFormats('AVIF'), true);
            } catch (\Throwable $e) {
                return false;
            }
        }
        return function_exists('imageavif');
    }

    private function resizeToFormat(string $source, string $target, int $targetWidth, int $quality, string $format): void
    {
        if ($format === 'avif') {
            $this->resizeToAvif($source, $target, $targetWidth, $quality);
            return;
        }
        $this->resizeToWebp($source, $target, $targetWidth, $quality);
    }

    private function resizeToWebp(string $source, string $target, int $targetWidth, int $quality): void
    {
        if (extension_loaded('imagick')) {
            $img = new \Imagick($source);
            $img->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
            $img->resizeImage($targetWidth, 0, \Imagick::FILTER_LANCZOS, 1);
            $img->setImageFormat('webp');
            $img->setImageCompressionQuality($quality);
            $img->stripImage();
            $img->writeImage($target);
            $img->clear();
            return;
        }
        $this->resizeWithGd($source, $target, $targetWidth, $quality, 'webp');
    }

    private function resizeToAvif(string $source, string $target, int $targetWidth, int $quality): void
    {
        if (extension_loaded('imagick')) {
            $img = new \Imagick($source);
            $img->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
            $img->resizeImage($targetWidth, 0, \Imagick::FILTER_LANCZOS, 1);
            $img->setImageFormat('avif');
            $img->setImageCompressionQuality($quality);
            $img->stripImage();
            $img->writeImage($target);
            $img->clear();
            return;
        }
        if (!function_exists('imageavif')) {
            throw new \RuntimeException('AVIF support is not available.');
        }
        $this->resizeWithGd($source, $target, $targetWidth, $quality, 'avif');
    }

    private function resizeWithGd(string $source, string $target, int $targetWidth, int $quality, string $format): void
    {
        $info = getimagesize($source);
        $mime = $info['mime'] ?? '';
        if ($mime === 'image/jpeg') {
            $src = imagecreatefromjpeg($source);
        } elseif ($mime === 'image/png') {
            $src = imagecreatefrompng($source);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $src = imagecreatefromwebp($source);
        } elseif ($mime === 'image/avif' && function_exists('imagecreatefromavif')) {
            $src = imagecreatefromavif($source);
        } else {
            throw new \RuntimeException('Unsupported image type: ' . $mime);
        }
        if (!$src) {
            throw new \RuntimeException('Could not open image.');
        }
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $targetHeight = (int) round($srcH * ($targetWidth / $srcW));
        $dst = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcW, $srcH);
        if ($format === 'avif') {
            imageavif($dst, $target, $quality);
        } else {
            imagewebp($dst, $target, $quality);
        }
        imagedestroy($src);
        imagedestroy($dst);
    }

    private function pathToUrl(string $path): string
    {
        $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = str_replace('\\', '/', $docRoot);
        if ($normalizedRoot && str_starts_with($normalizedPath, $normalizedRoot)) {
            return substr($normalizedPath, strlen($normalizedRoot));
        }
        $base = rtrim((string) $this->request->getBasePath(), '/');
        $pos = strpos($normalizedPath, '/public/');
        return $pos !== false ? $base . substr($normalizedPath, $pos) : $normalizedPath;
    }
}
