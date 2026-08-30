# PKP Responsive Images

Responsive image optimization plugin for PKP applications. It generates responsive image variants and decorates public-facing markup with `srcset`/`picture` output while keeping generated assets in the context public-files area.

## Compatibility

| Application | Supported series |
| --- | --- |
| Open Journal Systems (OJS) | 3.5.x |
| Open Monograph Press (OMP) | 3.5.x |
| Open Preprint Systems (OPS) | 3.5.x |

The plugin follows the PKP generic-plugin layout (`index.php`, plugin class, `version.xml`, locale resources and templates) and is intended for PKP 3.5 installations running PHP 8.1–8.4.

## Features

- Responsive `srcset` generation
- WebP conversion
- AVIF conversion when supported by the server
- Manifest-based caching
- Incremental optimization
- Background optimization via cron
- Dry-run scan from the plugin settings interface
- Context-aware public-file paths for OJS, OMP and OPS

## Requirements

- OJS, OMP or OPS 3.5.x
- PHP 8.1 or newer
- GD or Imagick for image processing
- WebP/AVIF support depends on the installed GD/Imagick build

## Installation

Install the plugin through **Settings → Website → Plugins → Upload A New Plugin**, or extract the release archive into:

```text
plugins/generic/responsiveImages
```

The release archive must contain the plugin files at its top level (not an additional repository-name directory).

Enable **Responsive Images** in the Generic Plugins list after installation.

## Updating

Use a tagged release archive. Before updating a production installation, back up the application database and files and test the release against the same PKP version used in production.

## Background optimization

`tools/cronOptimize.php` can be used for scheduled/background generation. Run it with the same PHP runtime and permissions as the PKP installation. Test the command manually before adding it to cron/systemd scheduling.

## Development and quality checks

Pull requests run PHP syntax checks for PHP 8.1–8.4 and repository-level PKP packaging checks. Release tags build a PKP-installable archive whose root directory is `responsiveImages/`.

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidance and [SECURITY.md](SECURITY.md) for reporting security issues.

## Release checklist

1. Update `version.xml` and `CHANGELOG.md`.
2. Confirm PHP/PKP compatibility.
3. Confirm all locale files parse correctly.
4. Run CI checks.
5. Create a version tag matching the release.
6. Test the generated archive with PKP's plugin upload/install workflow.

## License

GNU General Public License v3.0 or later (`GPL-3.0-or-later`). See [LICENSE](LICENSE).
