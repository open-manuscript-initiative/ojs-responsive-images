# Contributing

Contributions are welcome through GitHub issues and pull requests.

## Supported target

Changes must preserve compatibility with the supported PKP 3.5.x applications listed in the README: OJS, OMP and OPS.

## Development guidelines

- Follow the existing PKP plugin structure and namespaces.
- Prefer PKP APIs, services, hooks and request abstractions over application-specific workarounds.
- Keep user-facing strings in locale files.
- Escape output according to its HTML/attribute/JSON context.
- Validate authorization and CSRF protection for state-changing actions.
- Do not commit generated image variants, manifests, credentials, installation-specific paths or production data.
- Keep changes backward-compatible within the documented PKP release series whenever practical.

## Before opening a pull request

1. Run PHP syntax checks on all PHP files.
2. Test enable/disable and plugin settings in a supported PKP installation.
3. Test public frontend rendering and verify that backend/AJAX responses are not rewritten.
4. Test with GD or Imagick as applicable.
5. Update `CHANGELOG.md` for user-visible changes.
6. Update `version.xml` when preparing a release.

## Release packaging

PKP plugin archives must contain one top-level `responsiveImages/` directory with `version.xml`, `index.php`, the plugin class, locales, templates, classes and tools beneath it. Repository metadata that is not needed at runtime should not be required by the installed plugin.
