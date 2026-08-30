# Security Policy

## Supported versions

Security fixes are provided for the latest release of this plugin targeting the PKP 3.5.x series.

## Reporting a vulnerability

Please do **not** disclose suspected vulnerabilities in a public issue.

Use GitHub's private vulnerability reporting/security advisory feature for this repository when available. Include:

- affected plugin and PKP versions;
- OJS, OMP or OPS as applicable;
- reproduction steps;
- expected and observed behavior;
- impact assessment;
- any proposed mitigation.

Please avoid including credentials, private manuscripts, personal data, server configuration secrets or other sensitive production data in reports.

## Security expectations

State-changing plugin operations must be restricted to authorized PKP users and protected against cross-site request forgery. Generated files must remain inside the appropriate PKP public-files context and untrusted values must not be used to construct arbitrary filesystem paths.
