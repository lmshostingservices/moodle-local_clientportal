# Client portal for Moodle (local_clientportal)

A portal for your corporate clients. Each client (for example BHP) is a cohort. The client's managers and representatives sign in to Moodle and land straight in their own portal, where they see only their organisation's learners and the courses their organisation enrolled them in. They cannot open courses or any other part of the site.

## What client staff can do

- **Dashboard:** learners, course enrolments, completions, in progress, not started and completions in the last 30 days. Includes a summary per course and per group.
- **Learners:** search, filter by group, and bulk add learners to groups or take them out.
- **Learner detail:** every course with status, progress, enrolment and completion dates, grade, last access and certificates. Drill into a course for each activity's completion state, the date and time it was completed, and its grade.
- **Groups:** the client's own groups (for example Sales and Warehouse), with an optional cost centre. These are portal-only: they do not change Moodle groups or cohorts.
- **Reports:** a learners × courses matrix, or a detailed list filtered by group, course and status. Exports to CSV or Excel.
- **Certificates:** download one certificate, or all of them as a ZIP. Supports Custom certificate (mod_customcert) and Certificate manager (tool_certificate). If a learner can see a Custom certificate but has not opened it yet, downloading it from the portal issues it, just as the learner opening it would.
- **Onboarding (managers, with Training Matrix or Training Pathways):** give each learner a role in your organisation (position, department, start date, direct manager, employee ID) and put them on pathways with a finish-by date. Saving enrols them in every course their role and pathways need (manual enrolment, owned by the client). Courses that can't take the enrolment are listed, never forced.
- **Compliance (with Training Matrix):** compliance rate, expired, expiring in the next 30 days, in progress and not started; a people × competencies grid filtered by group, department, position and status; CSV export for audits.
- **Pathways (with Training Pathways):** each onboarding pathway with who is working through it, finished and overdue; each person's current stage and progress; manager sign-offs waiting. Client managers sign off steps and add people from the portal.
- **Everywhere:** the learners list shows each person's role and compliance; a learner's page shows their role, competencies (with expiry dates) and pathways; the dashboard adds compliance and onboarding cards.
- **Courses (when switched on for the client):** the client's own hidden category; managers create categories and courses, open them in Moodle to add content, add teachers and enrol learners (one by one, a group, or everyone automatically).
- **Trainers and assessors (when switched on):** managers add their own trainers, who see only the courses they teach; assessors mark in every course of the client.
- **Marking:** work waiting to be marked in the client's courses.
- **Team and activity log (managers only):** who has portal access, plus a log of portal actions such as exports, certificate downloads and group changes.

## Privacy rules

- A client's learners are the members of its cohort, minus its staff.
- A client sees a learner's progress only in courses it **owns**. A learner who also studies through another client, or privately, keeps those courses private.
- When a learner who belongs to exactly one client is enrolled, that client owns the enrolment automatically. Learners in two or more clients are left for an administrator to assign under *Owned enrolments*.
- Onboarding, compliance and pathways show only the client's own learners, and only the client's own or shared (client "0") departments, positions, competencies and pathways. Client staff never open the Training Matrix or Training Pathways pages; everything happens inside the portal.
- Client staff need an account with no course enrolments. Site administrators are never locked into the portal.

## Set up

1. Install the plugin (*Site administration > Plugins > Install plugins*). When the upgrade finishes, run *Site administration > Development > Purge caches > Purge all caches*, so browsers load the theme with the portal's styles. Do the same after every update.
2. Go to *Site administration > Plugins > Local plugins > Client portal > Clients*.
3. Add a client. Pick its cohort, or let the plugin create one.
4. Add staff: an existing account, or a new account that is emailed a password. Choose *Client manager* or *Client representative*.
5. Optionally assign existing enrolments under *Owned enrolments*.
6. With Cohort Branding installed, the cohort's logo and colours brand the portal.

Site staff can open any client's portal from the Clients page (support view).

### Client courses, trainers and billing

1. Edit a client and tick *Build courses*, *Trainers* and/or *Assessors*. The client's category is created under the category set in *Client portal > Settings* (top level by default), hidden from everyone else.
2. Open *Client portal > Keep clients apart* and select *Fix all*. This turns off the shared site pages where one client could see another's people or courses.
3. For billing, set the client's prices and tick *Bill this client*. Connect Xero on *Client portal > Xero* (create a Web app at developer.xero.com with the redirect URI shown there). Usage is added to each client's draft invoice every Monday; check *Usage and billing* at any time.

Other plugins can report AI credits with:

    \local_clientportal\api::record_usage($userid, $contextid, 'mod_yourplugin', $credits, 'What it was');

### Onboarding, compliance and pathways

Install AI Training Matrix 1.1.1 or later and/or Training Pathways 1.1.0 or later. Set each department, position, competency and pathway's client there (or leave it shared). The Onboarding, Compliance and Pathways tabs appear in the portal on their own.

Two settings (*Client portal > Settings*):
- *Onboarding enrols learners* (on): onboarding enrols learners in the courses they need. Turn off if your team enrols learners itself.
- *Client managers can sign off pathway steps* (on).

## Requirements

Moodle 4.4 to 5.3. Optional: local_cohortbranding, mod_customcert, tool_certificate, local_trainingmatrix (1.1.1+), local_trainingpathways (1.1.0+).

## Coming next

Enrolling learners from the portal, priced per course; invoices (emailed to the site admin and sent to Xero); and card or invoice payment.
