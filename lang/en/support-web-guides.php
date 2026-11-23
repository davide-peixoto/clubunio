<?php

return [
    'login' => [
        'title' => 'Sign in in the browser and check access',
        'intro' => 'Use your personal club account. Sign-in and club permission are separate.',
        'steps' => [
            [
                'title' => 'Check the environment',
                'body' => 'Open the address provided by the club. Check whether you need DEV, Stage or the production application.',
            ],
            [
                'title' => 'Sign in',
                'body' => 'Choose Sign in and complete the offered Keycloak flow, including a second verification step if configured.',
            ],
            [
                'title' => 'Check the club',
                'body' => 'After returning, check the club name and workspace. The member portal and administration offer different menus according to your role.',
            ],
            [
                'title' => 'Investigate errors',
                'body' => 'Note the time, environment and whether the error appeared before or after Keycloak. Ask the club office to check missing club access.',
            ],
        ],
        'notes' => [
            'HTTP 403 can indicate missing club or workspace permissions. Repeated sign-ins do not grant more rights.',
            'Never copy passwords, one-time codes or recovery codes into a support request.',
        ],
    ],
    'expense' => [
        'title' => 'Create and review expenses on the web',
        'intro' => 'Create the claim and its lines, then submit it after checking.',
        'steps' => [
            [
                'title' => 'Open expenses',
                'body' => 'Open Expense claims in the portal or the permitted administration area. Search and status filters help find existing claims.',
            ],
            [
                'title' => 'Create a claim',
                'body' => 'Complete the offered details such as team, expense round and bank account. Add lines with date, type and amount.',
            ],
            [
                'title' => 'Check receipts',
                'body' => 'Add required documents in the line form. Open the saved files and check readability, amount and assignment.',
            ],
            [
                'title' => 'Change status',
                'body' => 'For an editable claim, select an allowed new status and enter the required review note. Check the stored status afterwards.',
            ],
        ],
        'notes' => [
            'Transitions depend on role, module profile and claim status. Approval requires the appropriate permission.',
            'An approved claim is not evidence of a completed bank payment.',
        ],
    ],
    'profile' => [
        'title' => 'Personal profile and account security',
        'intro' => 'The personal account, member records and device features have separate settings.',
        'steps' => [
            [
                'title' => 'Open your profile',
                'body' => 'Check the display name and username in your personal profile. Confirm that you are using the intended account.',
            ],
            [
                'title' => 'Manage security',
                'body' => 'Open the offered Keycloak account-management link. Manage password, sign-in methods and device sessions in the separate tab.',
            ],
            [
                'title' => 'Change member data',
                'body' => 'For address or contact information, open the authorised member record and use the change-request workflow.',
            ],
            [
                'title' => 'Review sessions',
                'body' => 'Revoke sessions you no longer use in account management. Sign out after working on a shared browser.',
            ],
        ],
        'notes' => [
            'Face ID, saved app profiles and the push switch belong to the app. The web profile does not enable these features on a phone.',
        ],
    ],
    'tenants' => [
        'title' => 'Work in the correct club',
        'intro' => 'Check the active club before making changes.',
        'steps' => [
            [
                'title' => 'Check the club',
                'body' => 'Check the club name and address in the header or navigation. For test environments also check DEV or Stage.',
            ],
            [
                'title' => 'Switch clubs',
                'body' => 'If your account belongs to several clubs and a selector is offered, choose the intended club there.',
            ],
            [
                'title' => 'Reopen the module',
                'body' => 'After switching, reopen the module and check team, season and record. Do not continue using old forms from another club.',
            ],
            [
                'title' => 'Report missing access',
                'body' => 'If an expected club is missing, ask its office to check your personal access.',
            ],
        ],
        'notes' => [
            'A custom club domain may be restricted to its own club and need not offer unrestricted switching.',
            'Menu visibility does not replace server-side permission checks.',
        ],
    ],
    'chat' => [
        'title' => 'Use messages on the web',
        'intro' => 'The web chat uses the same conversations and contact rules as the app.',
        'steps' => [
            [
                'title' => 'Choose a conversation',
                'body' => 'Open Chat in web administration. Choose an existing conversation or use the offered search and contact selector.',
            ],
            [
                'title' => 'Check recipients',
                'body' => 'Check club and recipients. The selector only offers contacts your account may message under the club and team rules.',
            ],
            [
                'title' => 'Compose the message',
                'body' => 'Write the text and use the offered actions to add an attachment, poll or permitted team event. Check the composition before sending.',
            ],
            [
                'title' => 'Check the conversation',
                'body' => 'Use the available actions for replies, reactions and permitted changes. After a network error, check the conversation before sending again.',
            ],
        ],
        'notes' => [
            'Parent, player and coach accounts have different contact options. A help article does not extend these rights.',
            'Check that images and files are intended for every recipient.',
        ],
    ],
    'translation' => [
        'title' => 'Set up message translation on the web',
        'intro' => 'Interface language and translation of individual messages are separate.',
        'steps' => [
            [
                'title' => 'Open chat settings',
                'body' => 'Open web chat settings and locate the message-translation switch.',
            ],
            [
                'title' => 'Choose a target language',
                'body' => 'Enable the feature when needed and choose a target released by the backend. This list can be broader than the interface-language list.',
            ],
            [
                'title' => 'Confirm the privacy notice',
                'body' => 'Before a translation request, read the displayed privacy notice. Confirm processing only if you want to request the translation.',
            ],
            [
                'title' => 'Compare with the original',
                'body' => 'Compare important content with the original. Turn the feature off in chat settings when you no longer want to use it.',
            ],
        ],
        'notes' => [
            'Chat translations are processed by OpenAI. The interface uses maintained local texts and is not translated by AI on every view.',
            'A translation may contain errors and does not change the original message.',
        ],
    ],
    'planning' => [
        'title' => 'Team events and responses on the web',
        'intro' => 'Use Team planning for events, calendar and responses.',
        'steps' => [
            [
                'title' => 'Choose team and period',
                'body' => 'Open Team planning and set the appropriate team plus the offered date and status filters.',
            ],
            [
                'title' => 'Open or create an event',
                'body' => 'Open an existing event or the offered create action. Check date, start, duration, location and team.',
            ],
            [
                'title' => 'Check participants',
                'body' => 'Review responses, guests and additional invitations in the event detail view. Only manage people within your permitted scope.',
            ],
            [
                'title' => 'Review series changes',
                'body' => 'For recurring events, use the series workflow and review its preview before changing several events together.',
            ],
        ],
        'notes' => [
            'The current club season limits visible teams and events in the coach web portal. For empty lists, first check season and filters.',
            'A calendar event is not proof of an approved pitch reservation.',
        ],
    ],
    'teams' => [
        'title' => 'Manage teams, staff and training times',
        'intro' => 'Team views depend on role, season and assignment.',
        'steps' => [
            [
                'title' => 'Open the team',
                'body' => 'Open the team area and choose the intended season team. Check its season and label.',
            ],
            [
                'title' => 'Review players and staff',
                'body' => 'Check the visible assignments. Open member details only within your administration or coaching permissions.',
            ],
            [
                'title' => 'Check training times',
                'body' => 'Review training day, start, end and facility, including assignment to the summer or winter plan.',
            ],
            [
                'title' => 'Record changes',
                'body' => 'Use the offered assignment actions. Changes to personal member data follow the change-request workflow.',
            ],
        ],
        'notes' => [
            'The app offers My Teams. Parent access to a child team does not allow access to other players personal details.',
            'The coach web portal excludes historical or future assignments outside the current club season.',
        ],
    ],
    'members' => [
        'title' => 'Member records and change requests on the web',
        'intro' => 'Your own records, children and administrative member views have different permissions.',
        'steps' => [
            [
                'title' => 'Find the member',
                'body' => 'Open Members in the permitted portal or administration area. Search for the intended record and check the correct person.',
            ],
            [
                'title' => 'Review details',
                'body' => 'Check personal details, address and communication. Invoices, documents and cards require their own additional permission.',
            ],
            [
                'title' => 'Request a change',
                'body' => 'Use the offered change action, complete the details and submit the request. Follow its status instead of creating the same request repeatedly.',
            ],
            [
                'title' => 'Resolve parent access',
                'body' => 'If a child is missing, have the stored parent email and verified account link checked. Adults require explicit consent.',
            ],
        ],
        'notes' => [
            'Coaches receive details only within their permitted teams. Parents do not receive other players personal data through a child team.',
            'A matching email alone does not remove a manual block on a parent link.',
        ],
    ],
    'membership' => [
        'title' => 'Review membership applications',
        'intro' => 'Public club registration and administrative review are separate workflows.',
        'steps' => [
            [
                'title' => 'Use the club form',
                'body' => 'Use the published registration link for the correct club. Check the club name before entering personal data.',
            ],
            [
                'title' => 'Complete required details',
                'body' => 'Provide address, contact and billing information plus requested attachments. The required email also depends on the billing recipient.',
            ],
            [
                'title' => 'Review the application',
                'body' => 'Authorised reviewers open Registrations and the application. Check documents, status and team assignment.',
            ],
            [
                'title' => 'Save the decision',
                'body' => 'Choose an allowed status and enter the required reason. Afterwards check the decision and any resulting member assignment.',
            ],
        ],
        'notes' => [
            'An application is not yet admission. Photos and identity documents belong in protected upload fields, not support free text.',
            'Accepted trial applications may disappear from app team details while remaining traceable in Registrations.',
        ],
    ],
    'wallet' => [
        'title' => 'Check member cards and Wallet',
        'intro' => 'Cards use club logo and colours. QR verification shows the current status.',
        'steps' => [
            [
                'title' => 'Open the card',
                'body' => 'Open the released card from an authorised member record. Check name, club and membership status.',
            ],
            [
                'title' => 'Verify the QR code',
                'body' => 'Scan the card code using another device. On the resulting webpage, check club and current validity.',
            ],
            [
                'title' => 'Use Wallet in the app',
                'body' => 'To save on a phone, open the card in the ClubUnio app. Use the offered Apple or Google Wallet action if configured.',
            ],
            [
                'title' => 'Report discrepancies',
                'body' => 'Report an incorrect status or missing club branding to the office with the member reference and environment. Do not publish unprotected card copies.',
            ],
        ],
        'notes' => [
            'A saved Wallet pass does not replace live verification. Discounts also depend on partner terms.',
            'Missing Wallet actions can indicate incomplete provider, certificate or environment setup.',
        ],
    ],
    'events' => [
        'title' => 'Prepare and review event QR check-in',
        'intro' => 'Permitted organisers can provide an event time window and QR poster.',
        'steps' => [
            [
                'title' => 'Open the event',
                'body' => 'Open Events and the intended event. Check season, location and any team assignment.',
            ],
            [
                'title' => 'Configure check-in',
                'body' => 'If released, open Configure check-in. Enable the feature and set opening and closing times.',
            ],
            [
                'title' => 'Print the QR poster',
                'body' => 'Print the offered poster for the entrance. Test its link with an authorised test account before the event begins.',
            ],
            [
                'title' => 'Review attendance',
                'body' => 'Members scan and confirm with their account. Review the check-in list in event details and close check-in using its dedicated action when needed.',
            ],
        ],
        'notes' => [
            'Scanning alone does not confirm attendance: sign-in, permission, time window and confirmation are checked.',
            'Replacing a QR link invalidates old posters. Print the current poster after replacement.',
        ],
    ],
    'reservations' => [
        'title' => 'Handle bus reservations and pitch requests',
        'intro' => 'Check period, resource and status before each decision.',
        'steps' => [
            [
                'title' => 'Open the calendar',
                'body' => 'Open Bus reservations or Pitch occupancy. Choose the day or period and check vehicle or pitch.',
            ],
            [
                'title' => 'Create a request',
                'body' => 'Use the offered create action or select a time range in the pitch calendar. Check the prefilled date, start, duration and resource before submitting.',
            ],
            [
                'title' => 'Review the decision',
                'body' => 'As the responsible reviewer, open the request. Check conflicts and existing occupancy before using the offered approval or rejection action with a reason.',
            ],
            [
                'title' => 'Check status',
                'body' => 'Check the saved status. After changing time or assignment, check again whether approval is required.',
            ],
        ],
        'notes' => [
            'A submitted request is confirmed only after the required decision.',
            'A current closure and approval of a future pitch request are separate decisions. Use only the offered authorised actions.',
        ],
    ],
    'documents' => [
        'title' => 'Open documents and invoices safely',
        'intro' => 'Document rights are checked in addition to permission to view a member record.',
        'steps' => [
            [
                'title' => 'Choose the area',
                'body' => 'Open Documents or the document or invoice section of an authorised member record.',
            ],
            [
                'title' => 'Check assignment',
                'body' => 'Check document type, member, date and status. Only open files your account may download.',
            ],
            [
                'title' => 'Open the file',
                'body' => 'Use preview or download. An external invoice PDF also requires the configured invoicing service to be reachable.',
            ],
            [
                'title' => 'Report loading errors',
                'body' => 'Check the connection and retry once. Report repeated errors with time, document type and reference rather than forwarding confidential attachments without review.',
            ],
        ],
        'notes' => [
            'A link is not a public release. Private documents still require an authorised session when opened again.',
            'Withdrawn or outdated documents may be unavailable even if a reference remains visible.',
        ],
    ],
    'keyboxes' => [
        'title' => 'Manage personal keyboxes',
        'intro' => 'Real Igloohome access and simulation-only keybox assignments are separate areas.',
        'steps' => [
            [
                'title' => 'Choose the box',
                'body' => 'As an authorised administrator, open Personal keyboxes. Check club and box; distinguish changing room, equipment room and any assigned bus box.',
            ],
            [
                'title' => 'Maintain assignment',
                'body' => 'Check name and optional bus assignment. Maintain the provider device assignment only in its protected field and enter the required reason.',
            ],
            [
                'title' => 'Review release',
                'body' => 'Review personal assignments, active state and complete provider setup. A saved box alone does not guarantee successful code issuance.',
            ],
            [
                'title' => 'Review emergency access and revocation',
                'body' => 'Maintain emergency access only through the offered protected fields. Check physical measures needed after code changes; do not blindly retry uncertain issuance.',
            ],
        ],
        'notes' => [
            'Emergency access requires separate setup and failure conditions. A keybox test assignment is a simulation and does not open a physical box.',
            'Never include provider credentials or emergency PINs in screenshots, videos or support text. Hiding a known physical code does not revoke it.',
        ],
    ],
    'notifications' => [
        'title' => 'Use the browser inbox',
        'intro' => 'Notifications link to records; access is checked again when opened.',
        'steps' => [
            [
                'title' => 'Open the inbox',
                'body' => 'Use the offered bell or inbox area of the portal.',
            ],
            [
                'title' => 'Choose a notification',
                'body' => 'Check subject and date, then open the notification. Check club and current status of the target record.',
            ],
            [
                'title' => 'Change read status',
                'body' => 'Use Mark all read if needed. Wait for confirmation and check the counter rather than clicking repeatedly.',
            ],
            [
                'title' => 'Resolve unavailable targets',
                'body' => 'Check whether the record still exists and your role still permits access. Report repeated errors with notification reference and time.',
            ],
        ],
        'notes' => [
            'A notification grants no additional access to members, invoices or teams.',
            'Reading in the browser and push delivery to a phone are separate processes.',
        ],
    ],
    'push' => [
        'title' => 'Investigate push issues in club operations',
        'intro' => 'Push concerns the mobile app. A web session does not register a phone for app push.',
        'steps' => [
            [
                'title' => 'Identify device and build',
                'body' => 'Ask whether ClubUnio or a club build is affected, which version is used and which environment is selected.',
            ],
            [
                'title' => 'Check device permission',
                'body' => 'Ask the person to check the notification switch in the app profile and notification permission in the phone system settings.',
            ],
            [
                'title' => 'Distinguish setup issues',
                'body' => 'If several devices are affected, have the administrator check push setup for environment and build. A sent message does not confirm push delivery.',
            ],
            [
                'title' => 'Document delays',
                'body' => 'Record send and receipt times, environment and app version. Report recurring issues without private message content or device tokens.',
            ],
        ],
        'notes' => [
            'Focus mode, connectivity and the operating system affect display.',
            'Push requires configured providers, the correct build and a valid device session.',
        ],
    ],
    'diagnostics' => [
        'title' => 'Create a useful support request',
        'intro' => 'Describe the issue clearly. Support does not replace business approval.',
        'steps' => [
            [
                'title' => 'Describe the issue',
                'body' => 'Note club, module, time and last action. Distinguish expected behaviour from the actual message.',
            ],
            [
                'title' => 'Search help',
                'body' => 'Search Help and support for the task or error. Check whether the guide concerns the app or web.',
            ],
            [
                'title' => 'Open a ticket',
                'body' => 'If the issue remains, create a request with a meaningful subject and reproducible steps. Review necessary details and attachments before sharing.',
            ],
            [
                'title' => 'Follow up in the ticket',
                'body' => 'Use the same ticket for replies and updates rather than several requests for one incident. Close it after checking the solution.',
            ],
        ],
        'notes' => [
            'Do not send passwords, one-time codes, PINs, banking credentials or unchecked identity-document copies.',
            'The app additionally offers an explicitly prepared diagnostic email draft. That device attachment is not automatically created from a web session.',
        ],
    ],
];
