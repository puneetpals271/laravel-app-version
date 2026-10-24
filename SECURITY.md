# Security policy

## Supported versions

Only the latest 0.x release receives security fixes. Upgrade before reporting
if you are on an older release.

## Reporting a vulnerability

Please do not open a public issue for a security problem. Email
[puneet@wedevelopers.us](mailto:puneet@wedevelopers.us) with:

- the package version and the Laravel and PHP versions you run;
- a description of the issue and the impact you believe it has;
- steps or a minimal project that reproduce it.

You will receive an acknowledgement within five working days. Once a fix is
ready it is released with a note in the changelog, and you are credited there
unless you ask not to be.

## Scope

This package reads three request headers and shapes responses from a policy you
configure. Reports about the following are in scope:

- a malformed header value that causes anything other than a `400` response;
- a way for a client to read or serve a policy other than the one configured;
- any case where the middleware lets a version below `minimum` through, or
  blocks a version at or above it.

Operational misconfiguration (for example an out-of-order policy, which the
package rejects with an exception on purpose) is not a vulnerability.
