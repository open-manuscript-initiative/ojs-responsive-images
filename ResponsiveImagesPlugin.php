<?php
/**
 * @file plugins/generic/responsiveImages/ResponsiveImagesPlugin.php
 *
 * PKP Responsive Images
 * Version: 1.4.0
 */

namespace APP\plugins\generic\responsiveImages;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\core\PKPRequest;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use APP\plugins\generic\responsiveImages\classes\ImageScanner;
use APP\plugins\generic\responsiveImages\classes\ImageVariantGenerator;
use APP\plugins\generic\responsiveImages\classes\ResponsiveImageService;

class ResponsiveImagesPlugin extends GenericPlugin
{
    public const VERSION = '1.4.0';
    public const MANIFEST_FILENAME = 'responsive-images-manifest.json';
    public const VARIANT_DIRNAME = 'responsive';

    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('TemplateManager::display', [$this, 'handleTemplateDisplay']);
        }

        return $success;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.responsiveImages.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.responsiveImages.description');
    }

    public function getActions($request, $verb): array
    {
        $router = $request->getRouter();
        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, [
                            'verb' => 'settings',
                            'plugin' => $this->getName(),
                            'category' => 'generic',
                        ]),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ] : [],
            parent::getActions($request, $verb)
        );
    }

    public function manage($args, $request)
    {
        try {
            switch ((string) $request->getUserVar('verb')) {
                case 'settings':
                    return $this->showSettings($request);
                case 'scan':
                    return $this->runScan($request, false);
                case 'dryRun':
                    return $this->runScan($request, true);
                case 'clearManifest':
                    return $this->clearManifest($request);
            }
        } catch (\Throwable $e) {
            error_log('ResponsiveImages manage failed: ' . $e->getMessage());
            return new JSONMessage(false, '<pre style="white-space:pre-wrap">Responsive Images plugin hiba: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>');
        }

        return parent::manage($args, $request);
    }

    private function showSettings(PKPRequest $request): JSONMessage
    {
        $context = $request->getContext();
        $contextId = $context ? (int) $context->getId() : 0;
        $router = $request->getRouter();
        $baseUrl = $router->url($request, null, null, 'manage', null, [
            'plugin' => $this->getName(),
            'category' => 'generic',
        ]);

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginBaseUrl' => $baseUrl,
            'manifestPath' => $this->getManifestPath($contextId),
            'manifestExists' => is_file($this->getManifestPath($contextId)),
            'environment' => [
                'imagick' => extension_loaded('imagick'),
                'gd' => extension_loaded('gd'),
                'webp' => function_exists('imagewebp') || extension_loaded('imagick'),
                'avif' => $this->supportsAvif(),
            ],
        ]);

        return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('settings.tpl')));
    }

    private function runScan(PKPRequest $request, bool $dryRun): JSONMessage
    {
        $this->validateCsrf($request);
        $context = $request->getContext();
        $contextId = $context ? (int) $context->getId() : 0;
        $settings = $this->getPluginSettings($contextId);

        $scanner = new ImageScanner($this, $request, $contextId, $settings);
        $generator = new ImageVariantGenerator($this, $request, $contextId, $settings);
        $service = new ResponsiveImageService($this, $request, $contextId, $settings);

        $images = $scanner->scan();
        $result = [
            'dryRun' => $dryRun,
            'found' => count($images),
            'processed' => 0,
            'skipped' => 0,
            'errors' => [],
            'manifestPath' => $this->getManifestPath($contextId),
        ];

        $manifest = $service->readManifest();
        foreach ($images as $image) {
            try {
                if ($dryRun) {
                    $result['processed']++;
                    continue;
                }
                $entry = $generator->generate($image);
                if ($entry === null) {
                    $result['skipped']++;
                    continue;
                }
                $manifest[$image['url']] = $entry;
                $result['processed']++;
            } catch (\Throwable $e) {
                $result['errors'][] = $image['path'] . ': ' . $e->getMessage();
            }
        }

        if (!$dryRun) {
            $service->writeManifest($manifest);
        }

        return new JSONMessage(true, '<pre style="white-space:pre-wrap">' . htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '</pre>');
    }

    private function clearManifest(PKPRequest $request): JSONMessage
    {
        $this->validateCsrf($request);
        $context = $request->getContext();
        $contextId = $context ? (int) $context->getId() : 0;
        $path = $this->getManifestPath($contextId);
        if (is_file($path)) {
            unlink($path);
        }
        return new JSONMessage(true, __('plugins.generic.responsiveImages.clearManifest.done'));
    }

    private function validateCsrf(PKPRequest $request): void
    {
        return;
    }

    public function handleTemplateDisplay($hookName, $args): bool
    {
        $templateMgr =& $args[0];
        if (is_object($templateMgr) && method_exists($templateMgr, 'registerFilter')) {
            $templateMgr->registerFilter('output', [$this, 'smartyOutputFilter']);
        }
        return false;
    }

    public function smartyOutputFilter(string $html, $template): string
    {
        try {
            $request = Application::get()->getRequest();
            return $this->filterHtml($html, $request);
        } catch (\Throwable $e) {
            error_log('ResponsiveImages output filter failed: ' . $e->getMessage());
            return $html;
        }
    }

    public function filterHtml(string $html, PKPRequest $request): string
    {
        if ($this->isBackendOrAjaxRequest($request, $html)) {
            return $html;
        }

        $context = $request->getContext();
        $contextId = $context ? (int) $context->getId() : 0;
        $manifest = $this->getManifestPath($contextId);
        if (!is_file($manifest)) {
            return $html;
        }

        $service = new ResponsiveImageService($this, $request, $contextId, $this->getPluginSettings($contextId));
        return $service->decorateHtml($html);
    }

    private function isBackendOrAjaxRequest(PKPRequest $request, string $html): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $xrw = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');

        if (preg_match('#/(management|admin|user|login|logout|notification|api)/#i', $uri . '/')) {
            return true;
        }
        if (strpos($uri, '$$$call$$$') !== false || strpos($uri, '/grid/') !== false) {
            return true;
        }
        if (stripos($xrw, 'XMLHttpRequest') !== false || stripos($accept, 'application/json') !== false) {
            return true;
        }

        $backendMarkers = [
            'pkp_page_management',
            'pkp_page_admin',
            'pkp_page_user',
            'pkp_controllers_grid',
            'app__page',
            'id="pkpForm"',
            'data-vue-root',
        ];
        foreach ($backendMarkers as $marker) {
            if (stripos($html, $marker) !== false) {
                return true;
            }
        }

        return false;
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

    public function getPluginSettings(int $contextId): array
    {
        return [
            'widths' => [150, 200, 320, 480, 560, 562, 600, 640, 721, 768, 1024, 1536],
            'quality' => 82,
            'minWidth' => 321,
            'maxImagesPerRun' => 500,
            'scanIssueCovers' => true,
            'scanJournalPublicFiles' => true,
            'scanSiteImages' => true,
            'sizesDefault' => '(max-width: 768px) 100vw, 360px',
            'sizesCover' => '(max-width: 767px) 480px, 200px',
            'skipHeaderAndLogoImages' => true,
            'generateAvif' => true,
            'generateWebp' => true,
            'wrapPicture' => true,
        ];
    }

    public function getManifestPath(int $contextId): string
    {
        $publicFilesDir = rtrim($this->getPublicFilesDir($contextId), '/');
        return $publicFilesDir . '/' . self::VARIANT_DIRNAME . '/' . self::MANIFEST_FILENAME;
    }

    public function getPublicFilesDir(int $contextId): string
    {
        $request = Application::get()->getRequest();
        $base = rtrim((string) $request->getBasePath(), '/');
        $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? getcwd()), '/');
        $publicRoot = $docRoot . $base . '/public';

        $opsContextDir = $publicRoot . '/context/' . $contextId;
        $ompPressDir = $publicRoot . '/presses/' . $contextId;
        $ojsJournalDir = $publicRoot . '/journals/' . $contextId;

        $baseLower = strtolower($base);
        $appDir = strtolower(basename($base));

        if ($appDir === 'ops' || str_contains($baseLower, '/ops')) {
            return $opsContextDir;
        }
        if ($appDir === 'omp' || str_contains($baseLower, '/omp')) {
            return $ompPressDir;
        }
        if (is_dir($opsContextDir)) {
            return $opsContextDir;
        }
        if (is_dir($ompPressDir)) {
            return $ompPressDir;
        }
        return $ojsJournalDir;
    }
}
