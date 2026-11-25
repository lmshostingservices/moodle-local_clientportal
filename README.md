# Client portal for Moodle (local_clientportal)

A portal for your corporate clients. Each client (for example BHP) is a cohort. The client's managers and representatives sign in to Moodle and land straight in their own portal, where they see only their organisation's learners and the courses their organisation enrolled them in. They cannot open courses or any other part of the site.

## What client staff can do

- **Dashboard:** learners, course enrolments, completions, in progress, not started and completions in the last 30 days. Includes a summary per course and per group.
- **Learners:** search, filter by group, and bulk add learners to groups or take them out.
- **Add a learner (managers; representatives when allowed):** add one person, or paste many from a spreadsheet. No emails are sent unless "Send emails" is ticked: the manager gets a sign-in card (the client's sign-in link, username and a set or generated password) to copy or print, shown once and never stored. An email that already has an account adds that account, with its password unchanged. Optionally put them in groups and the client's own courses straight away.
- **Learner detail:** every course with status, progress, enrolment and completion dates, grade, last access and certificates. Drill into a course for each activity's completion state, the date and time it was completed, and its grade.
- **Groups:** the client's own groups (for example Sales and Warehouse), with an optional cost centre. These are portal-only: they do not change Moodle groups or cohorts.
- **Reports:** a learners × courses matrix, or a detailed list filtered by group, course and status. Exports to CSV or Excel.
- **Certificates:** download one certificate, or all of them as a ZIP. Supports Custom certificate (mod_customcert) and Certificate manager (tool_certificate). If a learner can see a Custom certificate but has not opened it yet, downloading it from the portal issues it, just as the learner opening it would.
- **Onboarding (managers, with Training Matrix or Training Pathways):** give each learner a role in your organisation (position, department, start date, direct manager, employee ID) and put them on pathways with a finish-by date. Saving enrols them in every course their role and pathways need (manual enrolment, owned by the client). Courses that can't take the enrolment are listed, never forced.
- **Required training (Compliance > Rules, managers):** make a course required for all learners, a group, a department or a position. Set the due date (days after being assigned, or a fixed date), how often it repeats (every so many months or days after completion, or the same date every year), reminders before the due date and while overdue, and when managers are told. Learners are enrolled automatically. When a course repeats, the renewal opens early and that learner's progress in the course is cleared so they can do it again; a summary of what was cleared is kept. Rules run every hour.
- **Due training (Compliance):** on track, overdue, due soon and completed, most urgent first; filter by group, course and status; Remind now; CSV export for audits. Learners see "Training you need to complete" at the top of their Dashboard and My courses page.
- **Compliance by position (with Training Matrix):** compliance rate, expired, expiring soon, in progress and not started; a people × competencies grid filtered by group, department, position and status; CSV export for audits.
- **Pathways (with Training Pathways):** each onboarding pathway with who is working through it, finished and overdue; each person's current stage and progress; manager sign-offs waiting. Client managers sign off steps and add people from the portal.
- **Everywhere:** the learners list shows each person's role and compliance; a learner's page shows their role, competencies (with expiry dates) and pathways; the dashboard adds compliance and onboarding cards.
- **Courses (when switched on for the client):** the client's own hidden category; managers create categories and courses, open them in Moodle to add content, add teachers and enrol learners (one by one, a group, or everyone automatically).
- **Trainers and assessors (when switched on):** managers add their own trainers, who see only the courses they teach; assessors mark in every course of the client.
- **Marking:** work waiting to be marked in the client's courses.
- **Build your structure with AI (managers, with Training Matrix):** describe the organisation; review and edit the suggested departments, positions and competencies; create them in one go.
- **Ask about this report:** plain-language questions about the progress report, answered from the report's own rows. Learner names are never sent to the AI.
- **Your sign-in link (Team page):** a link to a sign-in page with the client's logo and colours.
- **Team and activity log (managers only):** who has portal access, plus a log of portal actions such as exports, certificate downloads and group changes.

## Privacy rules

- A client's learners are the members of its cohort, minus its staff.
- A client sees a learner's progress only in courses it **owns**. A learner who also studies through another client, or privately, keeps those courses private.
- When a learner who belongs to exactly one client is enrolled, that client owns the enrolment automatically. Learners in two or more clients are left for an administrator to assign under *Owned enrolments*.
- Onboarding, compliance and pathways show only the client's own learners, and only the client's own or shared (client "0") departments, positions, competencies and pathways. Client staff never open the Training Matrix or Training Pathways pages; everything happens inside the portal.
- One person keeps one account across clients: a learner with one client can be staff with another. Staff who are also learners can open their own courses and learner pages; everything else stays inside the portal. Turn off *One account across organisations* to require separate staff accounts with no enrolments.
- Each person has a main organisation, which is billed when usage can't be tied to a client's course. Other clients see only that a person is "Also with another organisation", never which one.
- The course catalogue lists a learner's own organisations' courses and the site's open courses, never another client's courses.
- AI features run through LMS Labs AI credits, send learner codes, not names, and bill the credits to the client.
- In a **shared course** (one of your courses with learners from more than one client), *Shared courses* gives each client a Moodle group, keeps each learner in their client's group, and sets the group mode, so learners only see their own organisation inside the course. Portal reports never use groups: they stay scoped by owned enrolments.
- Site administrators are never locked into the portal.

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
3. Open *Client portal > Shared courses* to keep clients apart inside courses that several clients' learners take (Settings: *Shared courses* = Off, Suggest or Automatic; group mode; apply to existing activities; lock). Every set-up can be undone, back to the course's own group settings.
4. For billing, set the client's prices and tick *Bill this client*. Connect Xero on *Client portal > Xero* (create a Web app at developer.xero.com with the redirect URI shown there). Usage is added to each client's draft invoice every Monday; check *Usage and billing* at any time.

The site owner's **Clients report** (*Client portal > Clients report*, or *Reports > Clients report* in the Premium SaaS theme) shows every client's courses, trainers, learners and LMS Labs credits, with the amount to invoice.

LMS Labs AI plugins report their credits to the portal by themselves. Another plugin can report a call to the LMS Labs service with:

    \local_clientportal\api::record_lmslabs_call($userid, $contextid, 'mod_yourplugin', $reply, $price, 'What it was', $jobid);

or any other usage with:

    \local_clientportal\api::record_usage($userid, $contextid, 'mod_yourplugin', $credits, 'What it was');

### Onboarding, compliance and pathways

Install AI Training Matrix 1.1.1 or later and/or Training Pathways 1.1.0 or later. Set each department, position, competency and pathway's client there (or leave it shared). The Onboarding, Compliance and Pathways tabs appear in the portal on their own.

Settings (*Client portal > Settings*):
- *One account across organisations* (on).
- *Onboarding enrols learners* (on): onboarding enrols learners in the courses they need. Turn off if your team enrols learners itself.
- *Client managers can sign off pathway steps* (on).
- *Representatives can add learners* (off). Client managers can always add learners.

## Requirements

Moodle 4.4 to 5.3. The AI features use LMS Labs AI credits through the site's LMS Labs connection (AI Grader Central Config). Optional: local_cohortbranding, mod_customcert, tool_certificate, local_trainingmatrix (1.1.1+; 1.1.7+ so it leaves reminders for required courses to the portal), local_trainingpathways (1.1.0+).

## Coming next

Enrolling learners from the portal, priced per course; invoices (emailed to the site admin and sent to Xero); and card or invoice payment.
