# Client portal for Moodle (local_clientportal)

A portal for your corporate clients. Each client (for example BHP) is a cohort. The client's managers and representatives sign in to Moodle and land straight in their own portal, where they see only their organisation's learners and the courses their organisation enrolled them in. They cannot open courses or any other part of the site.

## What client staff can do

- **Dashboard:** learners, course enrolments, completions, in progress, not started and completions in the last 30 days. Includes a summary per course and per group.
- **Learners:** search, filter by group, and bulk add learners to groups or take them out.
- **Learner detail:** every course with status, progress, enrolment and completion dates, grade, last access and certificates. Drill into a course for each activity's completion state, the date and time it was completed, and its grade.
- **Groups:** the client's own groups (for example Sales and Warehouse), with an optional cost centre. These are portal-only: they do not change Moodle groups or cohorts.
- **Reports:** a learners × courses matrix, or a detailed list filtered by group, course and status. Exports to CSV or Excel.
- **Certificates:** download one certificate, or all of them as a ZIP. Supports Custom certificate (mod_customcert) and Certificate manager (tool_certificate). If a learner can see a Custom certificate but has not opened it yet, downloading it from the portal issues it, just as the learner opening it would.
- **Team and activity log (managers only):** who has portal access, plus a log of portal actions such as exports, certificate downloads and group changes.

## Privacy rules

- A client's learners are the members of its cohort, minus its staff.
- A client sees a learner's progress only in courses it **owns**. A learner who also studies through another client, or privately, keeps those courses private.
- When a learner who belongs to exactly one client is enrolled, that client owns the enrolment automatically. Learners in two or more clients are left for an administrator to assign under *Owned enrolments*.
- Client staff need an account with no course enrolments. Site administrators are never locked into the portal.

## Set up

1. Install the plugin (*Site administration > Plugins > Install plugins*). When the upgrade finishes, run *Site administration > Development > Purge caches > Purge all caches*, so browsers load the theme with the portal's styles. Do the same after every update.
2. Go to *Site administration > Plugins > Local plugins > Client portal > Clients*.
3. Add a client. Pick its cohort, or let the plugin create one.
4. Add staff: an existing account, or a new account that is emailed a password. Choose *Client manager* or *Client representative*.
5. Optionally assign existing enrolments under *Owned enrolments*.
6. With Cohort Branding installed, the cohort's logo and colours brand the portal.

Site staff can open any client's portal from the Clients page (support view).

## Requirements

Moodle 4.4 to 5.3. Optional: local_cohortbranding, mod_customcert, tool_certificate.

## Coming next

Enrolling learners from the portal, priced per course; invoices (emailed to the site admin and sent to Xero); and card or invoice payment.
