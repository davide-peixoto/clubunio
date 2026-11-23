<?php

return [
    'access' => [
        'title' => 'Manage users, roles and module profiles',
        'intro' => 'Account assignment and module profile determine club access.',
        'steps' => [
            [
                'title' => 'Choose the user',
                'body' => 'Open Settings and the permitted user area. Find the personal account and check club and account status.',
            ],
            [
                'title' => 'Review role and profile',
                'body' => 'Check the assigned club role and any module profile. Review viewing, editing, approving and downloading separately.',
            ],
            [
                'title' => 'Review member links',
                'body' => 'For personal member or parent access also check the relevant account link. Grant only the permissions needed for the task.',
            ],
            [
                'title' => 'Check access',
                'body' => 'Save the authorised change and ask the person to reopen the affected area. For an access error, check role and specific action separately.',
            ],
        ],
        'notes' => [
            'API accounts are not personal user accounts. Platform administration and club administration are separate.',
            'Do not grant extra administrator rights merely to expose one missing menu item.',
        ],
    ],
    'season' => [
        'title' => 'Plan and complete a season',
        'intro' => 'The season-planning wizard separates saved drafts from final completion.',
        'steps' => [
            [
                'title' => 'Open a draft',
                'body' => 'Under Sport, open the season area and season planning. Choose a saved draft or the offered new-plan action.',
            ],
            [
                'title' => 'Check teams and contributions',
                'body' => 'Work through team and contribution steps. Check template, season and team labels before applying them.',
            ],
            [
                'title' => 'Assign people and training times',
                'body' => 'Assign players, staff and technical leads. Add training times with summer or winter plan, facility, start and end.',
            ],
            [
                'title' => 'Review completion',
                'body' => 'Check all steps and the offered completion overview. Finalise a real club plan only after business review.',
            ],
        ],
        'notes' => [
            'A saved draft is not a completed season. Discarding is a separate action.',
            'The current club season also affects visibility in the coach web portal.',
        ],
    ],
    'training' => [
        'title' => 'Create and apply training plans',
        'intro' => 'Training plans are separate from calendar events and pitch reservations.',
        'steps' => [
            [
                'title' => 'Open planning',
                'body' => 'Open Plan training or the offered training-plan area and select a team you may manage.',
            ],
            [
                'title' => 'Prepare the plan',
                'body' => 'Use an existing plan or the offered create action. Complete the required training details and check team and period.',
            ],
            [
                'title' => 'Review content',
                'body' => 'Read generated or edited content and adapt exercises to age, ability and available equipment. Review guidance before applying it.',
            ],
            [
                'title' => 'Save and apply',
                'body' => 'Save the plan and use the offered review, apply or PDF action. Check the target assignment when applying.',
            ],
        ],
        'notes' => [
            'AI suggestions do not replace sporting or health-related professional judgement.',
            'A training description does not book a pitch or approve a reservation.',
        ],
    ],
    'contributions' => [
        'title' => 'Review membership contributions before invoicing',
        'intro' => 'Contribution prices and rules provide the basis for the contribution preview.',
        'steps' => [
            [
                'title' => 'Check reference data',
                'body' => 'Open permitted contribution prices and rules. Check season, validity and assignment before creating a preview.',
            ],
            [
                'title' => 'Load an individual preview',
                'body' => 'Open Contribution preview and choose member and season. Compare calculated lines, assignments and exceptions.',
            ],
            [
                'title' => 'Review the season run',
                'body' => 'Use Review season run if needed and check the preview and notes for each member. Resolve unclear assignments first.',
            ],
            [
                'title' => 'Apply the result deliberately',
                'body' => 'Only after review, use the offered draft-creation or processing action. Check the resulting invoice references.',
            ],
        ],
        'notes' => [
            'A preview is not a sent invoice and does not trigger a bank payment.',
            'Changes to contribution rules can affect many members; check the impact before a batch run.',
        ],
    ],
    'billing' => [
        'title' => 'Distinguish invoices, credits and payments',
        'intro' => 'Use the finance area and the action released for your task.',
        'steps' => [
            [
                'title' => 'Find the invoice',
                'body' => 'Open Invoices and search by member, status or invoice reference. Check billing recipient and season.',
            ],
            [
                'title' => 'Review lines and documents',
                'body' => 'Open the record and check lines, amount and available PDF or provider references.',
            ],
            [
                'title' => 'Use the appropriate correction',
                'body' => 'For corrections, use the offered credit-note or customer-credit workflow rather than overwriting settled payment details without review.',
            ],
            [
                'title' => 'Check processing status',
                'body' => 'After an allowed processing action, check the stored status and any feedback from the configured accounting service.',
            ],
        ],
        'notes' => [
            'Creating, sending, receiving payment and refunding an invoice are separate processes.',
            'A guide does not authorise real bank payments or a production finance run.',
        ],
    ],
    'announcements' => [
        'title' => 'Prepare announcements and circular messages',
        'intro' => 'Review content and recipients before publication or delivery.',
        'steps' => [
            [
                'title' => 'Open the area',
                'body' => 'Open Announcements and circular messages. Find an existing entry or use the offered create action.',
            ],
            [
                'title' => 'Prepare content',
                'body' => 'Complete subject, message and the offered time details. Use clear content without unnecessary personal data.',
            ],
            [
                'title' => 'Review recipients',
                'body' => 'Check the selected audience, teams and roles plus any offered recipient preview. Check the correct club scope.',
            ],
            [
                'title' => 'Check release and result',
                'body' => 'Only use the offered release or delivery action after review. Afterwards check stored status and available feedback.',
            ],
        ],
        'notes' => [
            'Read status is separate from successful push or email delivery.',
            'Later editing and redelivery can be different actions; do not send repeatedly without review.',
        ],
    ],
    'document_series' => [
        'title' => 'Create document series and review returns',
        'intro' => 'A series connects a template, recipients and personal documents.',
        'steps' => [
            [
                'title' => 'Choose template and series',
                'body' => 'Open Document series. Choose the appropriate template and check purpose, club and version.',
            ],
            [
                'title' => 'Review recipients',
                'body' => 'Check offered recipients and personal assignments. Review the preview before final creation.',
            ],
            [
                'title' => 'Provide originals',
                'body' => 'Use the designated issuance or publication action and check generated originals and status.',
            ],
            [
                'title' => 'Assign returns',
                'body' => 'Open the correct person and assign a checked signed return to its original. Check the recorded return status.',
            ],
        ],
        'notes' => [
            'Private originals and returns are not public files.',
            'An edited template draft does not replace an issued original; historical evidence remains available.',
        ],
    ],
    'social' => [
        'title' => 'Prepare social-media graphics and posts',
        'intro' => 'Approval and external delivery are separate steps.',
        'steps' => [
            [
                'title' => 'Open Social Media',
                'body' => 'Under Facilities and communication, open Social Media. Choose match overview, results or a free-form post.',
            ],
            [
                'title' => 'Review template and media',
                'body' => 'Choose a layout and edit text, fields and images. Use your own photos only with appropriate publication permission.',
            ],
            [
                'title' => 'Approve the preview',
                'body' => 'Check graphic, date, fixture and score. Review the JPEG preview before using the allowed approval action.',
            ],
            [
                'title' => 'Check delivery destination',
                'body' => 'If delivery is configured, check account, channel and offered delivery status. After edits, check whether the post has returned to draft.',
            ],
        ],
        'notes' => [
            'An approved draft is not yet a published post. Do not confuse test destinations with production channels.',
            'Private player details, call-ups and internal notes do not automatically belong in public graphics.',
        ],
    ],
    'campaigns' => [
        'title' => 'Understand campaign previews and feedback',
        'intro' => 'The extended campaign delivery workflow may be restricted to authorised test operation.',
        'steps' => [
            [
                'title' => 'Open the draft',
                'body' => 'Open Campaigns and the intended draft. Check type, club and available operating release.',
            ],
            [
                'title' => 'Review template and language',
                'body' => 'Choose the appropriate template and check German and English texts plus the offered output language for each recipient.',
            ],
            [
                'title' => 'Review recipients',
                'body' => 'Check preview and full recipient list. In designated test operation, use only the permitted synthetic addresses.',
            ],
            [
                'title' => 'Distinguish feedback',
                'body' => 'Review queued, accepted, delivery report, bounce and explicit confirmation separately. Retry uncertain attempts only using the reasoned restart action.',
            ],
        ],
        'notes' => [
            'Restricted test operation does not send real SMTP messages; a test status is not proof of production email delivery.',
            'Opening a link, printing and explicit confirmation are different events.',
        ],
    ],
    'integrations' => [
        'title' => 'Manage integrations and API access safely',
        'intro' => 'Technical access is managed separately from personal user accounts.',
        'steps' => [
            [
                'title' => 'Choose the area',
                'body' => 'Open Settings and the permitted API integrations or Parameters and integrations area. Check club and environment before changes.',
            ],
            [
                'title' => 'Define purpose and permissions',
                'body' => 'Choose the intended service and limit access to required functions. Review read, write and export permissions separately.',
            ],
            [
                'title' => 'Store access securely',
                'body' => 'Only use the designated protected input fields. Do not copy keys into notes, chat, screenshots or help articles.',
            ],
            [
                'title' => 'Check results',
                'body' => 'Use the offered connection or job check and review its result and reference. Revoke access that is no longer required.',
            ],
        ],
        'notes' => [
            'Saved configuration is not proof of a successful provider call.',
            'Production delivery, payments and provider changes require the designated business approvals.',
        ],
    ],
    'facilities' => [
        'title' => 'Maintain facility status and availability',
        'intro' => 'Facility status describes current usage conditions; pitch requests have a separate approval workflow.',
        'steps' => [
            [
                'title' => 'Find the facility',
                'body' => 'Open Facility status and find the affected facility. Check whether the club uses pitches or sport-neutral bookable facilities.',
            ],
            [
                'title' => 'Edit status',
                'body' => 'Choose an allowed status and add a factual note. Check whether that status blocks training.',
            ],
            [
                'title' => 'Check the saved result',
                'body' => 'Save and check facility, status and active state. If necessary, review the effect in the occupancy view.',
            ],
            [
                'title' => 'Review planning separately',
                'body' => 'Assess future requests separately in pitch occupancy. A current closure alone does not describe all future planning.',
            ],
        ],
        'notes' => [
            'Status reference values and the status of a specific facility are different records.',
            'Unauthorised users must not bypass a closure through unrelated reference data.',
        ],
    ],
    'material_returns' => [
        'title' => 'Track material issue and returns',
        'intro' => 'Material receipts and return bookings document physical issue and return.',
        'steps' => [
            [
                'title' => 'Open the receipt',
                'body' => 'Open Material receipts or Material returns. Choose the correct member and an issued or confirmed receipt.',
            ],
            [
                'title' => 'Check the line',
                'body' => 'Check issued quantity, already returned quantity and outstanding quantity for the specific line.',
            ],
            [
                'title' => 'Book the return',
                'body' => 'Record only physically returned material with quantity, date and condition. Check the line before confirming.',
            ],
            [
                'title' => 'Check the remainder',
                'body' => 'Review outstanding quantity and return history. If needed, maintain the offered due date and filter for overdue returns.',
            ],
        ],
        'notes' => [
            'Partial returns are possible. Total returns must not exceed the issued quantity.',
            'A return does not silently alter the original receipt and is not a general warehouse stock transaction.',
        ],
    ],
    'sponsor_runs' => [
        'title' => 'Organise a sponsor run',
        'intro' => 'Event, participants, pledges and billing are separate work steps.',
        'steps' => [
            [
                'title' => 'Open the event',
                'body' => 'Open Sponsor run and choose the correct event. Check season, date, place and registration deadline.',
            ],
            [
                'title' => 'Review participants',
                'body' => 'Check team and member assignments. Open authorised participant details and review existing pledges.',
            ],
            [
                'title' => 'Record performance',
                'body' => 'Enter the designated performance or lap data only after review. Check amounts and pledges in the offered overview.',
            ],
            [
                'title' => 'Prepare billing',
                'body' => 'Use the released billing preview and check billing recipients and results. Process external invoices only through the authorised workflow.',
            ],
        ],
        'notes' => [
            'Public sponsor links and internal member data are separate. Share only the designated public links.',
            'A pledge or created invoice is not a received payment.',
        ],
    ],
    'veo' => [
        'title' => 'Review a weekly Veo recording plan',
        'intro' => 'The plan uses SFV matches and confirmed pitch assignments.',
        'steps' => [
            [
                'title' => 'Choose the week',
                'body' => 'Open Veo recording planning and choose the start date or navigate to the required week.',
            ],
            [
                'title' => 'Check cameras',
                'body' => 'Review the camera name for each facility. Change it only to reflect the camera actually available there.',
            ],
            [
                'title' => 'Enable teams',
                'body' => 'Review which season teams are enabled for recording planning. Change this selection only within your responsibility.',
            ],
            [
                'title' => 'Review the plan',
                'body' => 'Compare proposed recording times with matches and confirmed pitch occupancy. Resolve missing or conflicting data before recording.',
            ],
        ],
        'notes' => [
            'A displayed plan is not proof that a camera has actually started or saved a recording.',
            'Planning does not replace informing the people involved or obtaining required recording permissions.',
        ],
    ],
    'challenge' => [
        'title' => 'Understand the club challenge and penalty game',
        'intro' => 'The penalty game is an app feature with a club-scoped leaderboard.',
        'steps' => [
            [
                'title' => 'Have release checked',
                'body' => 'If the challenge is missing in the app, ask administration to check club release and the app module profile.',
            ],
            [
                'title' => 'Open it in the app',
                'body' => 'Sign in to the correct club with an authorised account and open the offered club challenge. There is no equivalent standalone web game area.',
            ],
            [
                'title' => 'Finish a round',
                'body' => 'Choose an offered mode and complete the round in the app. Check the confirmed result and current leaderboard.',
            ],
            [
                'title' => 'Manage the leaderboard deliberately',
                'body' => 'Resetting is a special authorised action. Use only its designated confirmation workflow after understanding the effects.',
            ],
        ],
        'notes' => [
            'Challenge points are game scores, not expenses, payments or disciplinary penalties.',
            'An expired or incomplete round cannot be scored normally.',
        ],
    ],
    'staff_functions' => [
        'title' => 'Assign staff and club functions',
        'intro' => 'Personal records, team staff and club functions serve different purposes.',
        'steps' => [
            [
                'title' => 'Clarify the task',
                'body' => 'Check whether the person needs a team-staff assignment, technical-lead assignment or general club function.',
            ],
            [
                'title' => 'Open the appropriate area',
                'body' => 'Open team assignments in the season area or the authorised member record and its functions. Check season and person.',
            ],
            [
                'title' => 'Record the assignment',
                'body' => 'Choose the intended function and offered validity details. Avoid duplicate or historically incorrect assignments.',
            ],
            [
                'title' => 'Review its effect',
                'body' => 'Check the saved assignment and review technical access separately in the user area. A function must not be confused with unrestricted administrator rights.',
            ],
        ],
        'notes' => [
            'A standalone volunteer shift planner is not part of this described workflow.',
            'A club function and user role are different assignments.',
        ],
    ],
    'website' => [
        'title' => 'Provide public club data to a website',
        'intro' => 'Read-only website API access is separate from personal business access.',
        'steps' => [
            [
                'title' => 'Clarify publication needs',
                'body' => 'Identify which released club information the website needs. Do not publish personal dossiers through general website access.',
            ],
            [
                'title' => 'Check API access',
                'body' => 'Ask the responsible administrator to check the designated read-only website access and correct club.',
            ],
            [
                'title' => 'Review output',
                'body' => 'Compare visible website content with club data and release settings. Distinguish public information from internal notes.',
            ],
            [
                'title' => 'Track changes',
                'body' => 'Maintain data in its responsible ClubUnio module and check the website after updates. Report stale output with source and time.',
            ],
        ],
        'notes' => [
            'Public website output and editing internal club data are separate workflows.',
            'API keys must not be copied into public scripts, images or help articles.',
        ],
    ],
    'reports' => [
        'title' => 'Filter and export reports deliberately',
        'intro' => 'Reports use existing business and export permissions.',
        'steps' => [
            [
                'title' => 'Choose the report',
                'body' => 'Open Reports and the appropriate report, such as members, players, staff, open invoices or bank accounts.',
            ],
            [
                'title' => 'Set filters',
                'body' => 'Set the offered club, season and business filters. Check that the selection matches the intended purpose.',
            ],
            [
                'title' => 'Review results',
                'body' => 'Check samples and status details. An empty list can result from filters or missing permission.',
            ],
            [
                'title' => 'Use export deliberately',
                'body' => 'Use an offered export only when needed and permitted. Share the file only with intended recipients.',
            ],
        ],
        'notes' => [
            'View permission does not automatically include download or export permission.',
            'Reports can contain personal data. Avoid public storage and unchecked sharing.',
        ],
    ],
    'families' => [
        'title' => 'Review family assignments',
        'intro' => 'Family grouping and verified parent login are separate matters.',
        'steps' => [
            [
                'title' => 'Find the family',
                'body' => 'Open the permitted Families area and find the correct group. Check name and address details.',
            ],
            [
                'title' => 'Review members',
                'body' => 'Check assigned people and compare the grouping with the intended family relationships.',
            ],
            [
                'title' => 'Edit assignments',
                'body' => 'Use the offered edit actions and check each person before saving. Change personal contacts in the relevant member record.',
            ],
            [
                'title' => 'Check parent login separately',
                'body' => 'If a parent account cannot see a child, review the verified account link and age-based consent in the designated area.',
            ],
        ],
        'notes' => [
            'A family group alone does not grant parent access to protected member data.',
            'Adults require explicit consent independently of a shared address.',
        ],
    ],
    'competitions' => [
        'title' => 'Import SFV matches carefully',
        'intro' => 'External match information and internal team events are separate records.',
        'steps' => [
            [
                'title' => 'Choose the period',
                'body' => 'Open SFV matches or the SFV area of Team planning. Choose period and intended match.',
            ],
            [
                'title' => 'Check assignment',
                'body' => 'Review fixture, date, venue and target team. Check whether an internal event or link already exists.',
            ],
            [
                'title' => 'Review the preview',
                'body' => 'Use the offered preview for individual or multiple matches. Resolve missing teams and conflicts before import.',
            ],
            [
                'title' => 'Check results',
                'body' => 'Perform the allowed import and check resulting internal events. Review later external changes separately.',
            ],
        ],
        'notes' => [
            'External match information is not evidence of internal pitch approval.',
            'Avoid importing the same match twice.',
        ],
    ],
    'signage' => [
        'title' => 'Prepare signage messages',
        'intro' => 'Signage content is intended for the designated display surfaces.',
        'steps' => [
            [
                'title' => 'Create the message',
                'body' => 'Open Signage messages and create an entry using the offered action.',
            ],
            [
                'title' => 'Review date and content',
                'body' => 'Complete display date, subject and message. Check readability and omit confidential member data.',
            ],
            [
                'title' => 'Check active state',
                'body' => 'Save the intended active state. Use search and filters to review messages for the correct period.',
            ],
            [
                'title' => 'Check the preview',
                'body' => 'Open the offered signage preview and review output. Also check the actual display on site.',
            ],
        ],
        'notes' => [
            'A saved entry does not prove that a physical screen is online.',
            'Past messages may be hidden by the From today filter.',
        ],
    ],
    'sponsors' => [
        'title' => 'Maintain sponsorship in the member dossier',
        'intro' => 'Sponsorship entries record agreement, amount and term.',
        'steps' => [
            [
                'title' => 'Choose the dossier',
                'body' => 'Open the authorised member record and Sponsorship area. Check that you are editing the correct person or organisation.',
            ],
            [
                'title' => 'Create an entry',
                'body' => 'Use the offered create action and complete sponsor, amount in CHF, expiry date and necessary notes.',
            ],
            [
                'title' => 'Review details',
                'body' => 'Compare amount and term with the approved agreement. Save only factually necessary notes.',
            ],
            [
                'title' => 'Maintain history',
                'body' => 'Review existing entries before adding another. Record changes using the available edit action.',
            ],
        ],
        'notes' => [
            'A dossier entry is not an automatically sent sponsor invoice or a received payment.',
            'Dossier sponsorship and sponsor-run pledges are different areas.',
        ],
    ],
    'synchronisation' => [
        'title' => 'Review synchronisation jobs and errors',
        'intro' => 'The log describes individual jobs and does not replace checking the destination system.',
        'steps' => [
            [
                'title' => 'Filter jobs',
                'body' => 'Open Synchronisation and choose status or provider. Find the job associated with the incident.',
            ],
            [
                'title' => 'Review details',
                'body' => 'Check object, operation, time and available history. Distinguish open, successful and attention-required states.',
            ],
            [
                'title' => 'Resolve the cause',
                'body' => 'Review the displayed guidance and business data. Ask an authorised administrator to correct missing provider configuration.',
            ],
            [
                'title' => 'Restart deliberately',
                'body' => 'Use only the offered restart action with the required reason. Check result and target record rather than repeatedly triggering the same processing.',
            ],
        ],
        'notes' => [
            'For uncertain external results, check whether the action already happened in the destination before restarting.',
            'Logs can contain confidential references; do not attach credentials to support text.',
        ],
    ],
    'work_inbox' => [
        'title' => 'Handle pending work in the work inbox',
        'intro' => 'Overview counters link to business areas with their own permission checks.',
        'steps' => [
            [
                'title' => 'Open the overview',
                'body' => 'Open Dashboard or the offered Work inbox in the correct club. Check visible categories and counters.',
            ],
            [
                'title' => 'Open the business record',
                'body' => 'Choose, for example, an expense awaiting approval or a job requiring attention. Open the underlying record.',
            ],
            [
                'title' => 'Review content',
                'body' => 'Check request, documents, status and responsibility in the module. A counter alone is not a basis for a decision.',
            ],
            [
                'title' => 'Follow the result',
                'body' => 'Perform the allowed action in the module, then check stored status and overview.',
            ],
        ],
        'notes' => [
            'A category appears only with the required permissions and module releases.',
            'Pending business work, inbox messages and technical synchronisation errors are different task types.',
        ],
    ],
    'catalogs' => [
        'title' => 'Maintain reference data and language variants',
        'intro' => 'Reference data defines selectable values; changes can affect several workflows.',
        'steps' => [
            [
                'title' => 'Choose the catalog',
                'body' => 'Open Settings and the relevant area, such as Members and club, Sport and facilities or Finance.',
            ],
            [
                'title' => 'Review the entry',
                'body' => 'Find the existing value. Check code, label, order and active state before creating a similar entry.',
            ],
            [
                'title' => 'Maintain language variants',
                'body' => 'Use the offered lookup translations for released languages. Internal codes and existing user input remain unchanged.',
            ],
            [
                'title' => 'Check the effect',
                'body' => 'Check affected forms after saving. Deactivate old values only after reviewing existing usage.',
            ],
        ],
        'notes' => [
            'Translating a label does not release a new interface language. Language availability is centrally managed.',
            'Do not change status codes to bypass a business workflow.',
        ],
    ],
    'message_templates' => [
        'title' => 'Maintain message templates with placeholders',
        'intro' => 'Templates are managed by event, channel and language.',
        'steps' => [
            [
                'title' => 'Choose the template',
                'body' => 'Open Message templates and check event, email or push channel and language. Choose an existing template or an allowed create action.',
            ],
            [
                'title' => 'Edit text',
                'body' => 'Complete subject or title and message. Use only placeholders offered for that event.',
            ],
            [
                'title' => 'Review the preview',
                'body' => 'Check the sample-data preview and resolve invalid placeholders or unsupported formatting. Maintain each required language variant separately.',
            ],
            [
                'title' => 'Check activation',
                'body' => 'Save the authorised variant with the intended active state. Use an offered test action only with its permitted recipient.',
            ],
        ],
        'notes' => [
            'Saving a template does not itself send a circular message.',
            'Sample-data previews do not replace checking actual recipients.',
        ],
    ],
];
