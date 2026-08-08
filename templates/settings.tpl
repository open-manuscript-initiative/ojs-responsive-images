<div id="responsiveImagesSettings" class="pkp_form">
    <h3>{translate key="plugins.generic.responsiveImages.displayName"}</h3>
    <p>{translate key="plugins.generic.responsiveImages.settings.description"}</p>

    <h4>{translate key="plugins.generic.responsiveImages.environment"}</h4>
    <ul>
        <li>Imagick: {if $environment.imagick}OK{else}NINCS{/if}</li>
        <li>GD: {if $environment.gd}OK{else}NINCS{/if}</li>
        <li>WebP: {if $environment.webp}OK{else}NINCS{/if}</li>
        <li>AVIF: {if $environment.avif}OK{else}NINCS / fallback WebP-re{/if}</li>
        <li>Manifest: {if $manifestExists}létezik{else}még nincs létrehozva{/if}</li>
    </ul>

    <p><strong>Manifest útvonal:</strong><br>{$manifestPath|escape}</p>

    <form method="post" action="{$pluginBaseUrl|escape}">
        <input type="hidden" name="verb" value="dryRun">
        <button class="pkp_button" type="submit">{translate key="plugins.generic.responsiveImages.dryRun"}</button>
    </form>

    <form method="post" action="{$pluginBaseUrl|escape}">
        <input type="hidden" name="verb" value="scan">
        <button class="pkp_button" type="submit">{translate key="plugins.generic.responsiveImages.generate"}</button>
    </form>

    <form method="post" action="{$pluginBaseUrl|escape}">
        <input type="hidden" name="verb" value="clearManifest">
        <button class="pkp_button" type="submit">{translate key="plugins.generic.responsiveImages.clearManifest"}</button>
    </form>

    <h4>Időzített optimalizálás</h4>
    <p class="description">Pleskben az Ütemezett feladatoknál ezt érdemes 15–30 percenként futtatni. Csak az új vagy megváltozott képeket dolgozza fel.</p>
    <pre style="white-space:pre-wrap">php plugins/generic/responsiveImages/tools/cronOptimize.php --context=1 --base-path=/ojs --max=150 --mode=smart --formats=avif,webp</pre>

    <p class="description">{translate key="plugins.generic.responsiveImages.warning"}</p>
</div>
