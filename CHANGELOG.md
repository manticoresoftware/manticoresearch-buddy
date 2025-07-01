# Changelog

## Upcoming version

### Bugfixes

* [7920](https://github.com/manticoresoftware/manticoresearch-buddy/commit/7920d9ac9ebaf956b372b40381352bb1b7bb9538) Fix issue with getting project root directory when we run as Phar archive
* [08e49a4](https://github.com/manticoresoftware/manticoresearch-buddy/commit/08e49a4c499055eab0fc6a0852fcc3bad47a8019) Fix APP_VERSION missing from macOS package

### Major new features

* Pluggable architecture (#107)

### Minor changes

* [a154](https://github.com/manticoresoftware/manticoresearch-buddy/commit/a154fc6f4321b0ee41af3aa8fa22cb53f3ba07a1) Implement automatic composer autload reload on new plugin install via hooks
* [382e](https://github.com/manticoresoftware/manticoresearch-buddy/commit/382ed1d36b4cb080238487628bd68dcc0d36aa21) Display loaded plugins on Buddy start: core, local and extra
* [c522](https://github.com/manticoresoftware/buddy-core/commit/c52246a1cd9889a82e7c8f8e43fb9b0a7730f95f) [Core] Improve TaskResult to be struct built with chaining
* [5743](https://github.com/manticoresoftware/buddy-core/commit/57438ea9a64e66d77afbbbf8543eb676ec60b8e8) [Core] Add Docs generation with Doctum
* [4389](https://github.com/manticoresoftware/buddy-core/commit/4389a6ff6dcc0eb3998dff2e5b8e96311d581534) [Core] Updated the handling of the Request-id header
* [c5b4](https://github.com/manticoresoftware/buddy-core/commit/c5b446e7f219025f0e96c48f1c3e6dffb120374d) [Core] Use urlencode for all requests sending to the Manticore daemon



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
