# Changelog

All notable changes to the package will be documented in this file.

## Unreleased

- Require PHP 8.3; support PHP 8.3, 8.4 and 8.5
- Require Laravel 13 (`illuminate/support` ^13.0)
- Require `upmind/provision-provider-base` v5
- Update dev dependency to `phpstan/phpstan` ^2.0
- Update RankingCoach error handling for Guzzle 8 `ResponseException`
- Remove composer audit ignore list for PHP 7.4

## [v1.4.2](https://github.com/upmind-automation/provision-provider-seo/releases/tag/v1.4.2) - 2026-07-30

- Remove incorrect validation of `customer_email` in RankingCoach create()
- Fix RankingCoach login()

## [v1.4.1](https://github.com/upmind-automation/provision-provider-seo/releases/tag/v1.4.1) - 2025-11-25

- Widen illuminate/support version requirement

## [v1.4](https://github.com/upmind-automation/provision-provider-seo/releases/tag/v1.4) - 2025-11-25

- Add optional `service_id` to CreateParams
- Use `service_id` as external_id during RankingCoach create()
- Fix RankingCoach create() usage of package_identifier
- Fix RankingCoach changePackage() for suspended accounts

## [v1.3](https://github.com/upmind-automation/provision-provider-seo/releases/tag/v1.3) - 2025-11-18

- Add Ranking Coach provider

## [v1.2](https://github.com/upmind-automation/provision-provider-seo/releases/tag/v1.2) - 2024-05-09

- Update for PHP 8.1+ and base library v4
- Add static analyser and docker environment

## [v1.1](https://github.com/upmind-automation/provision-provider-seo/releases/tag/v1.1) - 2023-09-28

- Add changePackage() function

## [v1.0](https://github.com/upmind-automation/provision-provider-seo/releases/tag/v1.0) - 2023-09-19

Forked from [upmind/provision-provider-auto-login](https://github.com/upmind-automation/provision-provider-auto-login/releases/tag/v4.0)
