<?php
namespace APP\plugins\generic\responsiveImages\classes;

class ResponsiveImageService
{
    private object $plugin;
    private object $request;
    private int $contextId;
    private array $settings;

    public function __construct(object $plugin, object $request, int $contextId, array $settings)
    {
        $this->plugin = $plugin;
        $this->request = $request;
        $this->contextId = $contextId;
        $this->settings = $settings;
    }

    public function readManifest(): array
    {
        $path = $this->plugin->getManifestPath($this->contextId);
        if (!is_file($path)) return [];
        $json = file_get_contents($path);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    public function writeManifest(array $manifest): void
    {
        $path = $this->plugin->getManifestPath($this->contextId);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create manifest directory: ' . $dir);
        }
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function decorateHtml(string $html): string
    {
        if (stripos($html, '<img') === false || stripos($html, '<html') === false || stripos($html, '</html>') === false) return $html;
        $manifest = $this->readManifest();
        if (!$manifest) return $html;

        return preg_replace_callback('/<img\b[^>]*>/i', function (array $m) use ($manifest): string {
            $tag = $m[0];
            $src = $this->getImgAttribute($tag, 'src');
            if (!$src) return $tag;
            $isHeaderLogo = !empty($this->settings['skipHeaderAndLogoImages']) && $this->isHeaderOrLogoTag($src, $tag);
            $key = $this->normalizeUrl($src);
            $entry = $manifest[$key] ?? null;
            if (!$entry) $entry = $this->findManifestEntryByBasename($manifest, $key);
            if (!$entry) $entry = $this->buildEntryFromExistingVariants($key);
            if (!$entry || empty($entry['variants']) || !is_array($entry['variants'])) return $tag;

            $existingSrcset = $this->getImgAttribute($tag, 'srcset');
            if ($existingSrcset && $this->isRealResponsiveSrcset($existingSrcset)) {
                if (stripos($tag, 'cover_issue_') !== false || stripos($tag, 'cover') !== false) {
                    $tag = $this->setImgAttribute($tag, 'sizes', '(max-width: 767px) 480px, 200px');
                }
                return $tag;
            }

            $variantsByFormat = $this->variantsByFormat($entry['variants']);
            if (empty($variantsByFormat['webp']) && empty($variantsByFormat['avif'])) return $tag;
            $preferred = $variantsByFormat['webp'] ?? ($variantsByFormat['avif'] ?? []);
            $declaredWidth = $this->getDeclaredDisplayWidthFromTag($tag);
            $targetFallbackWidth = !empty($entry['isCover']) ? $this->preferredFallbackWidth($entry, $isHeaderLogo) : ($declaredWidth ?: $this->preferredFallbackWidth($entry, $isHeaderLogo));
            $fallback = $this->chooseFallbackVariant($preferred, $targetFallbackWidth);
            if (!empty($fallback['url'])) $tag = $this->setImgAttribute($tag, 'src', (string) $fallback['url']);
            if (!empty($variantsByFormat['webp'])) $tag = $this->setImgAttribute($tag, 'srcset', $this->buildSrcset($variantsByFormat['webp']));
            elseif (!empty($variantsByFormat['avif'])) $tag = $this->setImgAttribute($tag, 'srcset', $this->buildSrcset($variantsByFormat['avif']));
            $tag = $this->setImgAttribute($tag, 'sizes', $isHeaderLogo ? (($declaredWidth ?: 150) . 'px') : $this->getSizes($entry));
            if (!$this->hasImgAttribute($tag, 'width') && !empty($entry['width'])) $tag = $this->setImgAttribute($tag, 'width', (string) (int) $entry['width']);
            if (!$this->hasImgAttribute($tag, 'height') && !empty($entry['height'])) $tag = $this->setImgAttribute($tag, 'height', (string) (int) $entry['height']);
            if (!$this->hasImgAttribute($tag, 'decoding')) $tag = $this->setImgAttribute($tag, 'decoding', 'async');
            if (!$this->hasImgAttribute($tag, 'loading') && empty($entry['isCover'])) $tag = $this->setImgAttribute($tag, 'loading', 'lazy');
            if (!empty($entry['isCover'])) {
                $tag = $this->setImgAttribute($tag, 'fetchpriority', 'high');
                $tag = str_replace('(max-width: 767px) 560px, 200px', '(max-width: 767px) 480px, 200px', $tag);
            }
            return $tag;
        }, $html) ?? $html;
    }

    private function getImgAttribute(string $tag, string $name): string
    {
        if (!preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'<>`=]+))/i', $tag, $m)) return '';
        return html_entity_decode($m[2] ?? $m[3] ?? $m[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function hasImgAttribute(string $tag, string $name): bool
    {
        return (bool) preg_match('/\s' . preg_quote($name, '/') . '(\s*=|\s|\/?>)/i', $tag);
    }

    private function setImgAttribute(string $tag, string $name, string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $attribute = $name . '="' . $escaped . '"';
        if ($this->hasImgAttribute($tag, $name)) {
            return preg_replace('/\s' . preg_quote($name, '/') . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'<>`=]+)/i', ' ' . $attribute, $tag, 1) ?? $tag;
        }
        return preg_replace('/\s*\/?>$/', ' ' . $attribute . '>', $tag, 1) ?? $tag;
    }

    private function getDeclaredDisplayWidthFromTag(string $tag): int
    {
        $width = (int) $this->getImgAttribute($tag, 'width');
        if ($width > 0 && $width <= 2048) return $width;
        $style = $this->getImgAttribute($tag, 'style');
        if ($style && preg_match('/(?:^|;)\s*width\s*:\s*(\d+)px/i', $style, $m)) return (int) $m[1];
        return 0;
    }

    private function isHeaderOrLogoTag(string $src, string $tag): bool
    {
        $haystack = strtolower($src . ' ' . $this->getImgAttribute($tag, 'class') . ' ' . $this->getImgAttribute($tag, 'alt'));
        return str_contains($haystack, 'logo') || str_contains($haystack, 'templom') || str_contains($haystack, 'site_logo') || str_contains($haystack, 'header');
    }

    private function normalizeUrl(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($url);
        $path = $parts['path'] ?? $url;
        $path = rawurldecode($path);
        $path = preg_replace('#/+#', '/', $path);
        return preg_replace('/\.pagespeed\.[^.]+(?:\.[^.]+)*\.(jpe?g|png|webp|avif)$/i', '', $path);
    }

    private function findManifestEntryByBasename(array $manifest, string $key): ?array
    {
        $wanted = $this->cleanBasename($key);
        foreach ($manifest as $manifestKey => $entry) {
            $candidates = [$manifestKey];
            if (!empty($entry['sourceUrl'])) $candidates[] = (string) $entry['sourceUrl'];
            foreach ($candidates as $candidate) if ($this->cleanBasename($candidate) === $wanted) return is_array($entry) ? $entry : null;
        }
        return null;
    }

    private function cleanBasename(string $url): string { return strtolower(basename($this->normalizeUrl($url))); }

    private function buildEntryFromExistingVariants(string $key): ?array
    {
        $path = $this->urlToPath($key);
        if (!$path || !is_file($path)) return null;
        $info = @getimagesize($path);
        if (!$info) return null;
        $dir = dirname($path) . DIRECTORY_SEPARATOR . 'responsive';
        if (!is_dir($dir)) return null;
        $basename = pathinfo($path, PATHINFO_FILENAME);
        $variants = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . $basename . '-*.{webp,avif}', GLOB_BRACE) ?: [] as $variantPath) {
            if (!preg_match('/-(\d+)\.(webp|avif)$/i', basename($variantPath), $m)) continue;
            $variants[] = ['url' => $this->pathToUrl($variantPath), 'width' => (int) $m[1], 'format' => strtolower($m[2]), 'mime' => 'image/' . strtolower($m[2])];
        }
        return $variants ? ['sourceUrl' => $key, 'width' => (int) $info[0], 'height' => (int) $info[1], 'isCover' => (bool) preg_match('/cover|borito|issue/i', basename($path)), 'variants' => $variants] : null;
    }

    private function urlToPath(string $url): ?string
    {
        $path = $this->normalizeUrl($url);
        $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        return ($docRoot && is_file($docRoot . $path)) ? $docRoot . $path : null;
    }

    private function pathToUrl(string $path): string
    {
        $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = str_replace('\\', '/', $docRoot);
        if ($normalizedRoot && str_starts_with($normalizedPath, $normalizedRoot)) return substr($normalizedPath, strlen($normalizedRoot));
        $base = rtrim((string) $this->request->getBasePath(), '/');
        $pos = strpos($normalizedPath, '/public/');
        return $pos !== false ? $base . substr($normalizedPath, $pos) : $normalizedPath;
    }

    private function variantsByFormat(array $variants): array
    {
        $out = [];
        foreach ($variants as $variant) {
            if (empty($variant['url']) || empty($variant['width'])) continue;
            $format = strtolower((string) ($variant['format'] ?? pathinfo((string) $variant['url'], PATHINFO_EXTENSION)));
            if (!in_array($format, ['webp', 'avif'], true)) continue;
            $variant['width'] = (int) $variant['width'];
            $out[$format][] = $variant;
        }
        foreach ($out as &$items) usort($items, static fn($a, $b) => ((int) $a['width']) <=> ((int) $b['width']));
        return $out;
    }

    private function buildSrcset(array $variants): string
    {
        $parts = []; $seen = [];
        foreach ($variants as $variant) {
            $width = (int) ($variant['width'] ?? 0); $url = (string) ($variant['url'] ?? '');
            if ($width <= 0 || $url === '' || isset($seen[$width])) continue;
            $seen[$width] = true; $parts[] = $url . ' ' . $width . 'w';
        }
        return implode(', ', $parts);
    }

    private function isRealResponsiveSrcset(string $srcset): bool
    {
        if (stripos($srcset, 'pagespeed.') !== false) return false;
        preg_match_all('/\s(\d+)w(?:\s|,|$)/', $srcset, $m);
        return count(array_unique($m[1] ?? [])) >= 2;
    }

    private function preferredFallbackWidth(array $entry, bool $isHeaderLogo): int
    {
        if ($isHeaderLogo) return 150;
        return 480;
    }

    private function chooseFallbackVariant(array $variants, int $targetWidth): array
    {
        if (!$variants) return [];
        usort($variants, static fn($a, $b) => ((int) $a['width']) <=> ((int) $b['width']));
        $best = []; $bestDiff = PHP_INT_MAX;
        foreach ($variants as $variant) {
            $width = (int) ($variant['width'] ?? 0); if ($width <= 0) continue;
            $diff = abs($width - $targetWidth); if ($diff < $bestDiff) { $best = $variant; $bestDiff = $diff; }
        }
        return $best ?: (end($variants) ?: []);
    }

    private function getSizes(array $entry): string
    {
        if (!empty($entry['isCover'])) return '(max-width: 767px) 480px, 200px';
        return (string) ($this->settings['sizesDefault'] ?? '(max-width: 768px) 100vw, 360px');
    }
}
