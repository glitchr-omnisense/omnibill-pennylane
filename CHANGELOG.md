# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release. Omnibill Pennylane: Pennylane's accounting for omnisense/omnibill's journal-entries and contacts contracts - ledger entries posted to a journal (found by code) on ledger accounts (found by number and VAT rate), read back and listed; company and individual customers and suppliers created, updated, read, listed - through Pennylane's Company API v2 with a company token or a partner app's OAuth token (OAuth helper included). Pennylane's API contract terms (internal use unless API partner) carried in its capabilities.

### Changed

- Requires `omnisense/contracts: ^1.0`, `omnisense/omnibill: ^1.1`: stable versions, so `composer require omnibill/pennylane` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).
