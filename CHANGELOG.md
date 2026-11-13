# Changelog

All notable changes to the Client portal (local_clientportal) are listed here.

## [v2.0.2] - 2026-11-13

### Fixed
- The New course button showed "[[createcourse]]" instead of "Create course".
- The first column on *Keep clients apart* showed "[[setting]]" instead of "Setting".
- *Usage and billing* by plugin showed "core_ai" instead of "Moodle AI credits".
- Clients report cards no longer read "1 assessors" or "in 1 categories".

## [v2.0.1] - 2026-11-13

### Added
- **Clients report** for the site owner (*Site administration > Client portal > Clients report*, and under *Reports* in the Premium SaaS theme's menu and the Quick links block). Cards for clients, courses built, trainers, learners, active learners, enrolments, LMS Labs credits and the amount to invoice; charts of credits by client, by week and by plugin; a table of every client with a total row; this week, last week, this month, last month or any dates; CSV export.
- LMS Labs credits are counted per client from the LMS Labs AI plugins:
  - Read from the records the plugins already keep: AI Safety Hazards, AI Soft Skills, AI Visual Learning, AI Advent Speaking and Writing, AI Science, AI Anatomy, AI Slideshow, AI Video Conference, AI Virtual Classroom live classes (1 credit per 2 learner-hours, trainers not counted), AI Quiz Remedial, AI Course Builder, AI PDF feedback and RTO Compliance certificates. Finished records only; each record is counted once.
  - Reported by the plugins themselves from their next release (AI Content Creator, AI Course Format, AI Quiz, AI Quiz Maker, AI Knowledge Check, AI Video Activity, AI Interactive Video, AI Branched Scenario, AI Language Teacher, AI Course Information, AI Mapping, AI Slide Flow, AI Smart Workbook, AI Essay Grader, AI SCORM Voiceover, AI Training Matrix and the AI Virtual Classroom quiz).
- `\local_clientportal\api::record_lmslabs_call()`: plugins pass the LMS Labs reply as it came back. The credits it says were charged are used (`creditsCharged`, `creditsUsed`, `credits_used` and similar), otherwise the plugin's own price; failed calls are not counted, and a job id makes a polled job count once. When a plugin can only pass the system context, the page's course decides the client.

- Client representatives saw the "Mark work" help guide, though marking is only for managers, trainers and assessors.

## [v2.0.0] - 2026-11-13

### Added
- **Client courses.** Tick what each client may do (*Site administration > Client portal > Clients > Edit*):
  - *Build courses*: the client gets its own course category, hidden from everyone else. Its managers create categories and courses from the new **Courses** tab, choose the layout (including AI Course Format), edit content in Moodle, and enrol their own learners: one by one, a whole group, or everyone in the organisation (including people who join later).
  - *Trainers*: managers add their own trainers from the Team page (a new account, emailed a password) and make them editing or non-editing teachers in their courses. Trainers sign in to the portal and see only the courses they teach, the work to mark and help.
  - *Assessors*: managers mark staff as assessors, who mark work in every one of the client's courses without being enrolled.
- **Marking** tab: assignment submissions and quiz attempts waiting to be marked in the client's courses, oldest first, with a link to the marking screen.
- Client staff can now work inside their own client's courses (and only those). A "Back to the portal" bar shows at the top of those course pages.
- **Keep clients apart** (*Site administration > Client portal*): a checklist of the site settings that stop one client's people or courses showing to another (messaging, site search, the course list, profiles, blogs, tags, guest access, the site home page, the online users block), with Fix and Fix all.
- **Usage and billing** report per client and week or month: AI credits, AI actions, active learners, new enrolments, completions, and the amount at each client's prices; CSV export. Moodle AI actions are counted automatically (credits per action are settings), and other plugins can report usage with `\local_clientportal\api::record_usage()`.
- **Xero**: connect once, then every Monday the portal adds each billed client's usage to a draft invoice in Xero (one draft per client per month, a line per week); the contact is found by name or created. Supports Xero apps made before and after March 2026 (granular scopes).
- Six new help guides.

### Changed
- Every enrolment in a client's own course is owned by that client automatically.
- Staff accounts can be created with the Trainer role on the admin Staff page as well.

## [v1.1.0] - 2026-11-13

### Added
- Staff onboarding and monitoring inside the portal, aligned to the client's departments, positions, competencies and pathways (needs AI Training Matrix and/or Training Pathways):
  - **Onboarding** tab (managers): who has no role yet, new starters and anyone overdue; set a learner's position, department, start date, direct manager and employee ID; add pathways with a finish-by date; enrol them in every course their role and pathways need. Courses that can't take a manual enrolment are listed instead.
  - **Compliance** tab: compliance rate, expired, expiring in 30 days, in progress and not started; a people × competencies grid with group, department, position and status filters; CSV export.
  - **Pathways** tab: pathway cards, each person's current stage, progress, finish-by date and overdue flag; managers sign off "Manager sign-off" steps and add people.
  - Learner page: role, competencies with expiry dates, and pathways with sign-off buttons. Learners list: role and compliance columns. Dashboard: compliance and onboarding cards.
  - Six new help guides with screenshots.
- Settings: *Onboarding enrols learners* and *Client managers can sign off pathway steps* (both on).
- Everything is limited to the client: its own learners, and its own or shared definitions and pathways. Each action is in the activity log.

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
