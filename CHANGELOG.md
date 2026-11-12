# Changelog

All notable changes to the Client portal (local_clientportal) are listed here.

## [v1.0.6] - 2026-11-12

### Fixed
- Client staff who open one of the portal's site-admin pages (for example by an old bookmark to Clients) are now sent to their portal. Before, they saw Moodle's "Access denied" page in the full site layout. No data was exposed.

## [v1.0.5] - 2026-11-12

### Fixed
- Passes the full GitHub CI (moodle-plugin-ci) on every branch in the matrix:
  - Code checker: no warnings (inline comments start with a capital letter; tests declare coverage in the doc block as well as attributes).
  - Mustache lint: the header template no longer leaves the page wrappers open; ui::header() opens them and ui::footer() closes them.
  - Grunt/stylelint: no `!important` except where the portal must beat the theme's layout or Cohort Branding's heading colours (marked with stylelint comments), and blank lines before `@media`.
- Now installs on Moodle 4.4 (`requires` 2024042200, supported 4.4 to 5.3). Every API it uses exists in 4.4.

## [v1.0.4] - 2026-11-11
### Changed
- The new-staff email field uses PARAM_EMAIL and the "Extra allowed pages" setting uses PARAM_TEXT (no PARAM_RAW anywhere).
- Added an empty thirdpartylibs.xml (the plugin bundles no third-party libraries).

## [v1.0.3] - 2026-11-11
### Fixed
- The portal tabs no longer show a tiny scroll bar in the Premium SaaS theme.

## [v1.0.2] - 2026-11-11
### Changed
- All portal CSS classes and variables now start with `cport-` (were `lcp-`), so styles from other plugins that also use `lcp-` (for example local_custompage) can't change the portal.
- Install notes: purge all caches after installing an update from a ZIP.

## [v1.0.1] - 2026-11-11
### Fixed
- Restored the full text of 13 longer help and intro strings (for example the staff page intro and "Create a new user" note) that 1.0.0 cut short.

## [v1.0.0] - 2026-11-11
### Added
- Help tab for client staff: 17 short guides to every portal page (getting started, dashboard, learners, groups, reports, certificates, team, privacy), each with a screenshot, numbered pins and steps. The team guide shows only to client managers.
### Changed
- PHPUnit metadata uses attributes (ready for PHPUnit 12).

## [v0.1.1] - 2026-11-11

### Fixed
- Certificate downloads work with Custom certificate 5.2 and later (Moodle 5.2 and 5.3), which changed how certificates are made.

### Changed
- Opening a course the client did not enrol the learner in shows a calm "This course is private" notice instead of an error.
- Course totals show as a percentage, and activity grades as "85 / 100".
- Links use the client's brand colour.

### Tested
- Moodle 4.5 and Moodle 5.3 (Premium SaaS theme, Cohort Branding, Custom certificate).

## [v0.1.0] - 2026-11-11

### Added
- Clients (one per cohort), with client managers and client representatives who sign in straight to the portal and are kept out of the rest of the site.
- Dashboard, learners, learner detail with activity drill-down, client groups, reports with CSV and Excel export, certificates with ZIP download, team page and activity log.
- Enrolment ownership: clients see only the enrolments they own. New enrolments are assigned automatically when the learner belongs to one client.
- Cohort Branding support for the portal's logo and colours.
- Privacy provider, and a "Client portal user" role created on install.
