# Security Policy

## Supported Versions

Security fixes are provided for the latest major release of `indy2kro/php-iso` only. Please upgrade to the latest release before reporting.

## Scope

This library parses ISO images that may come from untrusted sources. The following are in scope:

* crashes, uncaught errors or infinite loops/hangs triggered by a crafted image
* unbounded memory or CPU consumption (memory exhaustion) while reading an image
* path traversal or writes outside the target directory during extraction
* any other way a crafted image can affect the host running the library or the CLI tools

Bugs that only affect correctness on valid images are regular bugs and can be filed as normal issues.

## Reporting a Vulnerability

Please do **not** open a public issue, pull request or discussion for a vulnerability.

Report it privately using GitHub private vulnerability reporting:
<https://github.com/indy2kro/php-iso/security/advisories/new>

Please include:

* the affected version and PHP version
* a description of the problem and its impact
* steps to reproduce, ideally with a minimal crafted image (or a script that generates it) and the code or CLI command used
* any suggested fix

You can expect an acknowledgement as soon as possible; we will keep you informed about the progress of the fix and coordinate disclosure with you.
