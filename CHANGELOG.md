# Changelog

## Upcoming version

### Major new features

* Pluggable architecture (#107)

### Minor changes

* [382e](https://github.com/manticoresoftware/manticoresearch-buddy/commit/382ed1d36b4cb080238487628bd68dcc0d36aa21) Display loaded plugins on Buddy start: core, local and extra

## Version 0.4.2

### Bugfixes

* [0537](https://github.com/manticoresoftware/manticoresearch-buddy/commit/053768a) Fix unreachable arm PHPStan issue in match statement
* [8e73](https://github.com/manticoresoftware/manticoresearch-buddy/commit/8e7353c) Fix issue with bin/query script that was implemented since latest core changes with cli tables feature

### Major new features

* [dbee](https://github.com/manticoresoftware/manticoresearch-buddy/commit/dbeec0c) Added the processing of Elastic-like queries (#109)
* [6a91](https://github.com/manticoresoftware/manticoresearch-buddy/commit/6a91fea) Add all logic to support mysqldump
* [ed09](https://github.com/manticoresoftware/manticoresearch-buddy/commit/ed09f8f) Implemented a cli table formatting feature (#94)
* [3297](https://github.com/manticoresoftware/manticoresearch-buddy/commit/32971ea) Migrate to external build system from repo phar_builder


### Minor changes

* [9c9d](https://github.com/manticoresoftware/manticoresearch-buddy/commit/9c9d55b) Add crash detection logic and send metric on it
* [e2ac](https://github.com/manticoresoftware/manticoresearch-buddy/commit/e2ac00b) Remove deprecations of set-output in GitHub actions
* [5f95](https://github.com/manticoresoftware/manticoresearch-buddy/commit/5f95bcd) Make output of bin/query a little bit better with tables returned
* [162b](https://github.com/manticoresoftware/manticoresearch-buddy/commit/162b79a) Update readme and add development instructions and building phar archive
* [522b](https://github.com/manticoresoftware/manticoresearch-buddy/commit/522b446) use PHP_OS_FAMILY instead PHP_OS
* [fba9](https://github.com/manticoresoftware/manticoresearch-buddy/commit/fba9c8c) Proxy original error on invalid request received
* [db95](https://github.com/manticoresoftware/manticoresearch-buddy/commit/db9532c) Allow spaces in backup path and add some magic to regexp to support single quotes also
* [2276](https://github.com/manticoresoftware/manticoresearch-buddy/commit/2276e89) Load variables into settings and increase post size for request body

### Breaking changes

* [ce90](https://github.com/manticoresoftware/manticoresearch-buddy/commit/ce907ea) Added Buddy version to started output

## Version 0.3.4

Initial release of the Buddy with basic functionality that was included into Manticore 6.0.0 release
