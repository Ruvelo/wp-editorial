# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-10-09

First release.

### Added

- A review queue (Reviews) with the three queues that matter (waiting for me, changes requested on my posts, approved and ready to publish), filters, sorting and due dates.
- A Review panel in the block editor and a full-page review screen that works without JavaScript: ask for a review, approve, request changes (optionally as a core note on the selected block), change reviewers, withdraw, and a complete history.
- Built on core Notes: open notes are counted and can be required to be resolved before publishing.
- An optional publish gate per post type (a number of approvals), applied from every screen, the REST API and WP-CLI, with an administrator override that needs a reason and is logged.
- A Review column and filter on the posts list, and a Reviews dashboard widget.
- Email notifications grouped through WP-Cron, a per-person opt-out, and optional delivery through Ruvelo Inbox.
- Settings, personal data export and erasure, and an uninstall that deletes data only when asked.
- REST API (`ruvelo-editorial/v1`), WP-CLI (`wp ruvelo-editorial request|approve|request-changes|withdraw|status|history|queue|notify|demo`), Abilities API registrations, actions and filters.
- Halyard's demo (`wp ruvelo-editorial demo`) and a WordPress Playground blueprint.

[Unreleased]: https://github.com/Ruvelo/wp-editorial/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Ruvelo/wp-editorial/releases/tag/v1.0.0
