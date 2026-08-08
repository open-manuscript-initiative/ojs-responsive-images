#!/usr/bin/env php
<?php
/**
 * Responsive Images cron optimizer for OJS.
 *
 * Usage from OJS root:
 * php plugins/generic/responsiveImages/tools/cronOptimize.php --context=1 --base-path=/ojs --max=150 --mode=smart
 */
$options = getopt('', [
    'context:', 'base-path::', 'public-root::', 'max::', 'quality::', 'widths::', 'formats::',
    'site::', 'journal::', 'dry-run::', 'mode::', 'lock::', 'log::', 'help::',
]);
if (isset($options['help']) || empty($options['context'])) {
    fwrite(STDOUT, "Responsive Images cron optimizer\n\nRequired: --context=1\nRecommended: --base-path=/ojs --max=150 --mode=smart\n");
    exit(empty($options['context']) ? 1 : 0);
}
$contextId = (int) $options['context'];
$basePath = rtrim((string) ($options['base-path'] ?? ''), '/');
$max = max(1, (int) ($options['max'] ?? 200));
$quality = max(1, min(100, (int) ($options['quality'] ?? 82)));
$widths = array_values(array_filter(array_map('intval', explode(',', (string) ($options['widths'] ?? '150,200,320,480,560,562,600,640,721,768,1024,1536')))));
$formats = normalizeFormats((string) ($options['formats'] ?? 'avif,webp'));
$scanSite = ((string) ($options['site'] ?? '1')) !== '0';
$scanJournal = ((string) ($options['journal'] ?? '1')) !== '0';
$dryRun = ((string) ($options['dry-run'] ?? '0')) === '1';
$mode = (string) ($options['mode'] ?? 'smart');
$start = microtime(true);
$script = str_replace('\\', '/', __FILE__);
$ojsRoot = realpath(dirname($script) . '/../../../..');
if (!$ojsRoot) { fwrite(STDERR, "Could not resolve OJS root.\n"); exit(1); }
$publicRoot = isset($options['public-root']) ? rtrim((string) $options['public-root'], '/') : $ojsRoot . '/public';
if (!is_dir($publicRoot)) { fwrite(STDERR, "Public root does not exist: {$publicRoot}\n"); exit(1); }
$lockPath = (string) ($options['lock'] ?? sys_get_temp_dir() . '/responsive-images-' . $contextId . '.lock');
$lockHandle = acquireLock($lockPath);
if (!$lockHandle) {
    fwrite(STDOUT, json_encode(['status' => 'locked', 'lockPath' => $lockPath, 'message' => 'Another responsive image optimization is already running.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
}
$roots = [];
if ($scanJournal) $roots[] = $publicRoot . '/journals/' . $contextId;
if ($scanSite) $roots[] = $publicRoot . '/site/images';
$roots = array_values(array_unique(array_filter($roots, 'is_dir')));
$manifestPath = $publicRoot . '/journals/' . $contextId . '/responsive/responsive-images-manifest.json';
$manifest = readManifest($manifestPath);
$result = ['dryRun'=>$dryRun,'mode'=>$mode,'context'=>$contextId,'basePath'=>$basePath,'publicRoot'=>$publicRoot,'manifestPath'=>$manifestPath,'lockPath'=>$lockPath,'formats'=>$formats,'roots'=>$roots,'found'=>0,'processed'=>0,'skipped'=>0,'errors'=>[]];
try {
    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($result['processed'] >= $max) break 2;
            if (!$file->isFile()) continue;
            $path = str_replace('\\', '/', $file->getPathname());
            if (isGeneratedVariant($path) || !isSupportedImage($path)) continue;
            $info = @getimagesize($path);
            if (!$info || (int) $info[0] < 321) continue;
            $result['found']++;
            $url = pathToUrl($path, $publicRoot, $basePath);
            $existing = $manifest[$url] ?? null;
            if (!$dryRun && $mode === 'smart' && manifestEntryFresh($existing, $path, $formats, $widths)) { $result['skipped']++; continue; }
            if ($dryRun) { $result['processed']++; continue; }
            try {
                $entry = generateEntry($path, $url, $info, $widths, $quality, $publicRoot, $basePath, $formats);
                if (!$entry) { $result['skipped']++; continue; }
                $manifest[$url] = $entry;
                $result['processed']++;
            } catch (Throwable $e) { $result['errors'][] = $path . ': ' . $e->getMessage(); }
        }
    }
    if (!$dryRun) writeManifest($manifestPath, $manifest);
} finally { releaseLock($lockHandle, $lockPath); }
$result['durationSeconds'] = round(microtime(true) - $start, 4);
if (!empty($options['log'])) appendLog((string) $options['log'], $result);
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit(empty($result['errors']) ? 0 : 2);

function normalizeFormats(string $formats): array {
    $requested = array_values(array_filter(array_map('trim', explode(',', strtolower($formats))))); $out=[];
    foreach ($requested as $format) { if ($format === 'avif' && supportsAvif()) $out[]='avif'; if ($format === 'webp' && supportsWebp()) $out[]='webp'; }
    return array_values(array_unique($out ?: ['webp']));
}
function supportsWebp(): bool { return extension_loaded('imagick') || function_exists('imagewebp'); }
function supportsAvif(): bool { if (extension_loaded('imagick')) { try { return in_array('AVIF', Imagick::queryFormats('AVIF'), true); } catch (Throwable $e) { return false; } } return function_exists('imageavif'); }
function acquireLock(string $path) { $dir=dirname($path); if (!is_dir($dir)) @mkdir($dir,0755,true); $handle=fopen($path,'c'); if (!$handle) return null; if (!flock($handle,LOCK_EX|LOCK_NB)) { fclose($handle); return null; } ftruncate($handle,0); fwrite($handle,(string)getmypid()); return $handle; }
function releaseLock($handle,string $path): void { if ($handle) { flock($handle,LOCK_UN); fclose($handle); } if (is_file($path)) @unlink($path); }
function appendLog(string $path,array $result): void { $dir=dirname($path); if (!is_dir($dir)) @mkdir($dir,0755,true); $line=sprintf("[%s] found=%d processed=%d skipped=%d errors=%d duration=%ss formats=%s\n",date('c'),(int)$result['found'],(int)$result['processed'],(int)$result['skipped'],count($result['errors']),(string)$result['durationSeconds'],implode(',',$result['formats'])); @file_put_contents($path,$line,FILE_APPEND); }
function readManifest(string $path): array { if (!is_file($path)) return []; $data=json_decode((string)file_get_contents($path),true); return is_array($data)?$data:[]; }
function writeManifest(string $path,array $manifest): void { $dir=dirname($path); if (!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Could not create manifest directory: '.$dir); file_put_contents($path,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); }
function isSupportedImage(string $path): bool { return (bool)preg_match('/\.(jpe?g|png|webp|avif)$/i',$path); }
function isGeneratedVariant(string $path): bool { return strpos($path,'/responsive/')!==false || (bool)preg_match('/-\d+\.(webp|avif)$/i',$path); }
function manifestEntryFresh($entry,string $path,array $formats,array $widths): bool {
    if (!is_array($entry) || (int)($entry['sourceMTime']??0)!==(int)filemtime($path) || empty($entry['variants'])) return false;
    $sourceWidth=(int)($entry['width']??0);
    foreach ($widths as $width) { if ($width >= $sourceWidth) continue; foreach ($formats as $format) { $has=false; foreach ($entry['variants'] as $variant) { $variantFormat=strtolower((string)($variant['format']??pathinfo((string)($variant['url']??''),PATHINFO_EXTENSION))); if ((int)($variant['width']??0)===(int)$width && $variantFormat===$format && !empty($variant['url'])) { $has=true; break; } } if (!$has) return false; } }
    return true;
}
function generateEntry(string $source,string $url,array $info,array $widths,int $quality,string $publicRoot,string $basePath,array $formats): ?array {
    $variantDir=dirname($source).'/responsive'; if (!is_dir($variantDir) && !mkdir($variantDir,0755,true) && !is_dir($variantDir)) throw new RuntimeException('Could not create variant directory: '.$variantDir);
    $basename=pathinfo($source,PATHINFO_FILENAME); $variants=[]; $sourceMTime=(int)filemtime($source);
    foreach ($widths as $width) { if ($width >= (int)$info[0]) continue; foreach ($formats as $format) { $target=$variantDir.'/'.$basename.'-'.$width.'.'.$format; if (!is_file($target)||(int)filemtime($target)<$sourceMTime) resizeToFormat($source,$target,$width,$quality,$format); $variants[]=['url'=>pathToUrl($target,$publicRoot,$basePath),'width'=>$width,'format'=>$format,'mime'=>'image/'.$format]; } }
    if (!$variants) return null;
    return ['sourceUrl'=>$url,'width'=>(int)$info[0],'height'=>(int)$info[1],'sourceMTime'=>$sourceMTime,'isCover'=>(bool)preg_match('/cover|borito|issue/i',basename($source)),'variants'=>$variants,'formats'=>array_values(array_unique(array_column($variants,'format'))),'generatedAt'=>date('c')];
}
function resizeToFormat(string $source,string $target,int $targetWidth,int $quality,string $format): void {
    if (extension_loaded('imagick')) { $img=new Imagick($source); $img->setImageOrientation(Imagick::ORIENTATION_TOPLEFT); $img->resizeImage($targetWidth,0,Imagick::FILTER_LANCZOS,1); $img->setImageFormat($format); $img->setImageCompressionQuality($quality); $img->stripImage(); $img->writeImage($target); $img->clear(); return; }
    resizeWithGd($source,$target,$targetWidth,$quality,$format);
}
function resizeWithGd(string $source,string $target,int $targetWidth,int $quality,string $format): void {
    $info=getimagesize($source); $mime=$info['mime']??'';
    if ($mime==='image/jpeg') $src=imagecreatefromjpeg($source); elseif ($mime==='image/png') $src=imagecreatefrompng($source); elseif ($mime==='image/webp'&&function_exists('imagecreatefromwebp')) $src=imagecreatefromwebp($source); elseif ($mime==='image/avif'&&function_exists('imagecreatefromavif')) $src=imagecreatefromavif($source); else throw new RuntimeException('Unsupported image type: '.$mime);
    if (!$src) throw new RuntimeException('Could not open image.');
    $srcW=imagesx($src); $srcH=imagesy($src); $targetHeight=(int)round($srcH*($targetWidth/$srcW)); $dst=imagecreatetruecolor($targetWidth,$targetHeight); imagealphablending($dst,false); imagesavealpha($dst,true); imagecopyresampled($dst,$src,0,0,0,0,$targetWidth,$targetHeight,$srcW,$srcH);
    if ($format==='avif') { if (!function_exists('imageavif')) throw new RuntimeException('GD AVIF support is not available.'); imageavif($dst,$target,$quality); } else { if (!function_exists('imagewebp')) throw new RuntimeException('GD WebP support is not available.'); imagewebp($dst,$target,$quality); }
    imagedestroy($src); imagedestroy($dst);
}
function pathToUrl(string $path,string $publicRoot,string $basePath): string { $path=str_replace('\\','/',$path); $publicRoot=rtrim(str_replace('\\','/',$publicRoot),'/'); if (str_starts_with($path,$publicRoot)) return $basePath.'/public'.substr($path,strlen($publicRoot)); $pos=strpos($path,'/public/'); return $pos!==false?$basePath.substr($path,$pos):$path; }
