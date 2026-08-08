<?php
namespace APP\plugins\generic\responsiveImages\classes;

use PKP\core\PKPRequest;

class ImageScanner
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

    public function scan(): array
    {
        $roots = [];
        if (!empty($this->settings['scanJournalPublicFiles'])) {
            $roots[] = $this->plugin->getPublicFilesDir($this->contextId);
        }
        if (!empty($this->settings['scanSiteImages'])) {
            $siteRoot = $this->getSiteImagesDir();
            if ($siteRoot) {
                $roots[] = $siteRoot;
            }
        }
        $roots = array_values(array_unique($roots));

        $images = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (count($images) >= (int) $this->settings['maxImagesPerRun']) {
                    break 2;
                }
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if ($this->isGeneratedVariant($path)) {
                    continue;
                }
                if (!$this->isSupportedImage($path)) {
                    continue;
                }
                $size = @getimagesize($path);
                if (!$size || $size[0] < (int) $this->settings['minWidth']) {
                    continue;
                }
                $images[] = [
                    'path' => $path,
                    'url' => $this->pathToUrl($path),
                    'width' => (int) $size[0],
                    'height' => (int) $size[1],
                    'isCover' => $this->looksLikeCover($path),
                ];
            }
        }
        return $images;
    }


    private function getSiteImagesDir(): ?string
    {
        $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $base = rtrim((string) $this->request->getBasePath(), '/');
        if (!$docRoot) {
            return null;
        }
        $candidates = [];
        if ($base) {
            $candidates[] = $docRoot . $base . '/public/site/images';
        }
        $candidates[] = $docRoot . '/public/site/images';
        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }
        return $candidates[0] ?? null;
    }

    private function isSupportedImage(string $path): bool
    {
        return (bool) preg_match('/\.(jpe?g|png|webp|avif)$/i', $path);
    }

    private function isGeneratedVariant(string $path): bool
    {
        if (strpos($path, DIRECTORY_SEPARATOR . 'responsive' . DIRECTORY_SEPARATOR) !== false) {
            return true;
        }
        return (bool) preg_match('/-\d+\.(webp|avif)$/i', $path);
    }

    private function looksLikeCover(string $path): bool
    {
        return (bool) preg_match('/cover|borito|issue/i', basename($path));
    }

    private function pathToUrl(string $path): string
    {
        $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $normalizedPath = str_replace('\\', '/', $path);
        if ($docRoot && str_starts_with($normalizedPath, str_replace('\\', '/', $docRoot))) {
            return substr($normalizedPath, strlen(str_replace('\\', '/', $docRoot)));
        }
        $base = rtrim((string) $this->request->getBasePath(), '/');
        $needle = $base . '/public/';
        $pos = strpos($normalizedPath, '/public/');
        return $pos !== false ? $base . substr($normalizedPath, $pos) : $normalizedPath;
    }
}
