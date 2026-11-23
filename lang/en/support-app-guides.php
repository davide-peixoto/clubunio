<?php

// Public app instructions. No private account or club data.
return [
    'login' => [
        'title' => 'Sign in to the app and use biometrics',
        'intro' => 'Use your personal ClubUnio account. Club-branded and ClubUnio apps use the same personal sign-in flow.',
        'steps' => [
            ['title' => 'Check the environment', 'body' => 'Before signing in, check the selected environment. The settings icon opens the available choices. DEV and Stage are test environments with sessions separate from PROD.'],
            ['title' => 'Sign in', 'body' => 'Tap Sign in, complete sign-in in the Keycloak window and return to the app. Then check the account and club name in the header.'],
            ['title' => 'Enable biometrics', 'body' => 'Open Profile and enable biometric sign-in if you want to unlock the saved personal session with device biometrics. Confirm the system prompt and check the switch state.'],
            ['title' => 'Record an error', 'body' => 'If an error occurs, note the environment, time and visible diagnostic code. After checking the connection, try signing in again or open the offered support.'],
        ],
        'notes' => ['Biometrics does not replace a valid server session. An expired or revoked session requires a fresh sign-in.', 'Do not share passwords or recovery codes.'],
    ],
    'expense' => [
        'title' => 'Create and submit an expense receipt',
        'intro' => 'Create the receipt in the selected club, then add its line items and evidence.',
        'steps' => [
            ['title' => 'Open Expenses', 'body' => 'Open Expenses from the bottom navigation or module menu. Check the season and existing receipts before creating another receipt.'],
            ['title' => 'Create a receipt', 'body' => 'Start a new receipt, complete the displayed required fields and choose the appropriate available assignments. Save the receipt.'],
            ['title' => 'Add a line item and evidence', 'body' => 'Open the receipt and add a line item. Enter the amount and required details. Choose the evidence using the offered camera or file picker and check its preview.'],
            ['title' => 'Check transfer and submission', 'body' => 'Review line items and attachment transfer status. Use the offered status action to submit, adding a reason if requested. Then check the confirmed status in the detail view.'],
        ],
        'notes' => ['A saved draft is not approval or payment. Actions depend on your role, receipt status and club configuration.', 'If saving has an unclear result, reopen the existing receipt instead of creating the same receipt again.'],
    ],
    'profile' => [
        'title' => 'Manage your profile, app language and saved sign-ins',
        'intro' => 'Profile contains personal settings and device sessions. App language and message translation are separate.',
        'steps' => [
            ['title' => 'Open Profile', 'body' => 'Tap the profile icon in the top menu. Check the displayed account before changing a personal setting.'],
            ['title' => 'Choose the app language', 'body' => 'Open App language and select a language enabled by the backend. This changes the interface; it does not automatically translate chat messages.'],
            ['title' => 'Manage another sign-in', 'body' => 'Under Profiles / Logins, add another sign-in or switch to a saved account. After switching, check the account and club.'],
            ['title' => 'Check device sessions', 'body' => 'Review signed-in devices. Deliberately revoke another device session that is no longer needed. To sign out on this device, use the sign-out action in the header menu.'],
        ],
        'notes' => ['Switching accounts and switching clubs are different actions.', 'Blocked chat contacts manages private-contact blocks; it does not grant team or member access.'],
    ],
    'tenants' => [
        'title' => 'Select the correct club',
        'intro' => 'The ClubUnio app supports several authorised clubs. Club-branded apps remain bound to their own club.',
        'steps' => [
            ['title' => 'Identify the active club', 'body' => 'Read the club name in the header, particularly before changing expenses, member details or reservations.'],
            ['title' => 'Open club selection', 'body' => 'In the ClubUnio app, tap club selection in the header when several clubs are available. Only your authorised clubs are offered.'],
            ['title' => 'Choose the destination club', 'body' => 'Select the destination club and wait for its context to load. Reopen the required module and check that its data belongs to the intended club.'],
            ['title' => 'Check notification destinations', 'body' => 'Opening a notification checks its destination club and permissions again. Deliberately confirm an offered switch and check the club name on the opened object.'],
        ],
        'notes' => ['Custom builds do not offer switching to other clubs. Use the ClubUnio app with the appropriate permission.', 'Selecting DEV, Stage or PROD changes the server environment, not the club.'],
    ],
    'chat' => [
        'title' => 'Use messages, images, events and reactions',
        'intro' => 'The centre chat button opens authorised conversations. Group chats and personal messages are separate.',
        'steps' => [
            ['title' => 'Find a conversation', 'body' => 'Open Chats and select Group chats or Messages. Search for a loaded chat name. Club announcements remain pinned at the top; list search does not search message text.'],
            ['title' => 'Prepare a message', 'body' => 'Open a conversation, or use the compose icon to start a direct message and search for an allowed contact. Write your text. Use the plus icon next to the input to add offered images, files or a team event.'],
            ['title' => 'Send and watch the status', 'body' => 'Review text and prepared attachments, then tap Send. You can keep typing while the previous message is saved. Watch the displayed saving or error status.'],
            ['title' => 'Reply and react', 'body' => 'Swipe a message to select it as a reply reference. Long-press a saved message to open available actions and reactions. Tap existing reactions to see who added them.'],
            ['title' => 'Check readers', 'body' => 'Long-press your own saved message and choose Read by when offered. This list loads only when requested.'],
        ],
        'notes' => ['Direct contacts depend on role and team: parents mainly receive staff contacts, players their authorised team contacts; coaches and administration receive their separately authorised wider contacts.', 'Read by shows the conversation read position, not proof of physically viewing a message. After a timeout, check the existing send status before sending the same text again.'],
    ],
    'translation' => [
        'title' => 'Translate individual chat messages',
        'intro' => 'Message translation is optional and separate from app language. Enabling it does not translate messages automatically.',
        'steps' => [
            ['title' => 'Open chat settings', 'body' => 'Open settings from the chat overview and find message translation. Read the privacy notice about processing by OpenAI.'],
            ['title' => 'Set a target language', 'body' => 'Deliberately enable the feature and choose an offered target language. The centrally enabled choices may differ from app languages. Save the setting.'],
            ['title' => 'Translate a message', 'body' => 'Open the conversation and choose Translate for the desired message. Review the external-processing confirmation and confirm only if you agree.'],
            ['title' => 'Compare with the original', 'body' => 'Read the translation and keep the original as the authoritative message. Disable the feature in chat settings if you no longer want to use it.'],
        ],
        'notes' => ['Translations can contain errors. Consider confidential information before external processing.', 'Disabled translation, a restricted language or an exhausted budget may prevent the action. The interface uses local language catalogs, not this feature.'],
    ],
    'planning' => [
        'title' => 'Find team events and respond',
        'intro' => 'Team planning shows authorised events and responses. With family accounts, deliberately choose the correct person.',
        'steps' => [
            ['title' => 'Choose a team and period', 'body' => 'Open Events or Team planning. Choose the desired team or offered combined view, then use the calendar or list.'],
            ['title' => 'Check the event', 'body' => 'Open an event and check date, start time, team and location. Use the offered actions to load older events or additional weeks.'],
            ['title' => 'Record a response', 'body' => 'Choose yourself or a player you may represent, then use the offered acceptance or absence action. Check the saved response afterwards.'],
            ['title' => 'Use coach actions', 'body' => 'Authorised coaches can find Edit, Add player / guest, and cancellation or deletion actions. Check the scope of a change and confirm deliberately.'],
        ],
        'notes' => ['A chat poll does not replace an event response. Attendance can only be confirmed according to the applicable event rules.', 'If events are missing, first check team, period, club and permissions.'],
    ],
    'teams' => [
        'title' => 'Use My Teams and authorised team details',
        'intro' => 'My Teams brings together your teams, players, staff and valid training times.',
        'steps' => [
            ['title' => 'Open a team', 'body' => 'Open My Teams from the module menu and choose the team. Check its season and name in the details.'],
            ['title' => 'Review players and staff', 'body' => 'Read the available entries. Parents see only other players’ names and the released staff contact details.'],
            ['title' => 'Request a change as a coach', 'body' => 'As that team’s coach, open an authorised member and choose Request a change when offered. Correct permitted details and submit the request for review.'],
            ['title' => 'Check training and registrations', 'body' => 'Review currently valid training times and open available trial-training requests. Accepted registrations are no longer listed again in these team details.'],
        ],
        'notes' => ['Coach permissions apply to authorised teams, not all members or family invoices.', 'Hiding accepted trial-training requests does not change the separate Registrations module.'],
    ],
    'members' => [
        'title' => 'Open your member details and children',
        'intro' => 'Members shows personal, represented or administratively authorised records. Every dossier is authorised again.',
        'steps' => [
            ['title' => 'Open a member', 'body' => 'Tap Members in the bottom navigation, find the entry and open its card directly.'],
            ['title' => 'Check details and actions', 'body' => 'Review personal details, address and communication. Use offered actions for documents, invoices, member card or changes.'],
            ['title' => 'Check access to children', 'body' => 'Check that your verified sign-in email is recorded as a parent address for the child. Under-18 access may then be approved automatically unless a manual block exists.'],
            ['title' => 'Manage adult parent access', 'body' => 'Adult members require explicit consent. As an authorised member, open Parent access / Parent logins in Profile and approve or revoke the access. Contact the office if the link is unclear.'],
        ],
        'notes' => ['A shared email alone does not grant access to arbitrary dossiers. Adult rules apply from the eighteenth birthday.', 'Coaches open team members through My Teams. This does not give parents access to other players’ details.'],
    ],
    'membership' => [
        'title' => 'Apply for membership or register another child',
        'intro' => 'A membership application collects details and documents; it does not yet mean admission to the club.',
        'steps' => [
            ['title' => 'Choose the correct entry', 'body' => 'Without sign-in, an enabled club-branded app offers New membership on its start screen. The ClubUnio core app does not offer this anonymous entry. With a parent account, sign in, open Members and use the offered new-membership action.'],
            ['title' => 'Complete the application', 'body' => 'Enter the person, address and contacts. Choose the invoice recipient: the corresponding member, mother or father email must be completed. Follow other displayed required fields.'],
            ['title' => 'Add attachments', 'body' => 'Choose the required portrait, identity document and other evidence. Check the preview and confirmed transfer. Do not bypass disabled uploads using an insecure channel.'],
            ['title' => 'Confirm and follow the status', 'body' => 'Complete the offered account and email confirmation. Review the summary and submit. Keep the confirmed reference and follow the status using the offered personal access.'],
        ],
        'notes' => ['With an existing parent account, do not create another account as a precaution; use the additional-membership flow.', 'Required fields, file formats and review steps vary by club. Contact the office if uploads are unavailable.'],
    ],
    'wallet' => [
        'title' => 'Show your member card and save it to Wallet',
        'intro' => 'The card uses the club logo and colours. Its QR code enables a current validity check.',
        'steps' => [
            ['title' => 'Open the card', 'body' => 'In Members, open yourself or a child you may represent and choose Member card.'],
            ['title' => 'Check the card', 'body' => 'Review club, member, status and last-check time. An old screenshot is not current proof of validity.'],
            ['title' => 'Add to Wallet', 'body' => 'In the export section, select Add to Apple Wallet on iPhone or Add to Google Wallet on Android if configured. Complete the offered dialog and check the saved card.'],
            ['title' => 'Let a partner verify it', 'body' => 'Show the QR code in the app or Wallet. A partner can scan it with a phone camera and check current validity on the webpage. Deliberately use export or sharing.'],
        ],
        'notes' => ['Opening a Wallet dialog does not confirm saving. The action may be disabled without complete configuration.', 'Valid membership does not guarantee a discount. Partner offer terms apply; old exports do not replace live verification.'],
    ],
    'events' => [
        'title' => 'Scan an event QR and confirm attendance',
        'intro' => 'The event QR opens personal check-in. Scanning alone does not record attendance.',
        'steps' => [
            ['title' => 'Scan the entrance QR', 'body' => 'Use your phone’s system camera to scan the club’s event QR and open the recognised link.'],
            ['title' => 'Check account and event', 'body' => 'Sign in if required. Check club, event, location and time window. A club-branded app rejects other clubs’ destinations.'],
            ['title' => 'Choose the person deliberately', 'body' => 'Choose an offered personal member identity or someone you may represent. Check whose attendance is being recorded.'],
            ['title' => 'Confirm participation', 'body' => 'Explicitly tap Confirm participation and wait for the confirmed display. If network status is unclear, inspect the refreshed view before further action.'],
        ],
        'notes' => ['Disabled or expired check-in and a closed time window do not permit confirmation.', 'Direct app opening depends on device and build links. If a browser opens, use its offered sign-in flow.'],
    ],
    'reservations' => [
        'title' => 'Reserve a bus or request a pitch',
        'intro' => 'Reservations belong to the selected club. A request and a confirmed booking are different states.',
        'steps' => [
            ['title' => 'Choose module and resource', 'body' => 'Open Bus for a bus reservation or Pitch occupancy for a pitch request. Select date and resource.'],
            ['title' => 'Select the period', 'body' => 'Select an offered free calendar area or a new entry. In the supported occupancy calendar, drag across several slots to set start and duration.'],
            ['title' => 'Review and submit', 'body' => 'Check date, start, duration, team, bus or pitch, and other required details. Submit and inspect the confirmed save result.'],
            ['title' => 'Follow the status', 'body' => 'Reopen the request and check its current status. Editing, cancellation or approval requires an offered action. Bus access additionally follows reservation and access-window rules.'],
        ],
        'notes' => ['A request is not automatically approved. The server checks conflicts, closures and current permissions.', 'Refresh an unclear status instead of creating a second request.'],
    ],
    'documents' => [
        'title' => 'Read documents and invoices',
        'intro' => 'Documents load according to current permissions. Personal documents and family invoices are not visible to every club account.',
        'steps' => [
            ['title' => 'Choose the entry', 'body' => 'Open Documents from the module menu, or open an authorised member and use its document or invoice action.'],
            ['title' => 'Select a file', 'body' => 'Check the entry title and relationship. Open the file or an invoice’s offered PDF view.'],
            ['title' => 'Review the display', 'body' => 'Wait for the preview and check the document. Deliberately use offered export or sharing actions.'],
            ['title' => 'Handle loading errors', 'body' => 'Check connection, account and club, then tap Reload. If the error persists, report time and document type with the offered diagnostics.'],
        ],
        'notes' => ['Coach access to contacts does not grant general invoice or identity-document access.', 'Do not attach confidential documents to external contacts or support messages without review.'],
    ],
    'keyboxes' => [
        'title' => 'Use keyboxes and the emergency flow',
        'intro' => 'Changing-room, equipment-room and bus access require personal permissions and complete club configuration.',
        'steps' => [
            ['title' => 'Open an authorised box', 'body' => 'Use the offered keybox entry in Profile or the box of your approved bus reservation. Select a personally authorised box.'],
            ['title' => 'Request access', 'body' => 'Check box and context, then deliberately start the offered access action. Wait for a definite result; the request may be checked in the background.'],
            ['title' => 'Use the code privately', 'body' => 'Use a successfully issued code only for the intended access. Do not share it in chat and leave the view after use.'],
            ['title' => 'Handle an outage', 'body' => 'After confirmed non-issuance, a new deliberate attempt may be offered. Only three confirmed provider outages and existing emergency configuration can allow a brief emergency code display. Contact the office if the result is uncertain.'],
        ],
        'notes' => ['Do not blindly repeat requests after an uncertain PIN result. Do not bypass missing permissions using another box.', 'Hiding a known physical offline code does not invalidate it. An emergency code is not a normal access code to store permanently.'],
    ],
    'notifications' => [
        'title' => 'Open the inbox and linked objects',
        'intro' => 'The bell in the top menu opens your notifications. Linked objects still require current permissions.',
        'steps' => [
            ['title' => 'Open the inbox', 'body' => 'Tap the bell in the top menu and check the list and displayed club.'],
            ['title' => 'Select a notification', 'body' => 'Tap a notification. The app checks destination and permissions again, then opens the object. Deliberately confirm a required club switch.'],
            ['title' => 'Manage read status', 'body' => 'Use Mark all read to mark available notifications together. Then check the list and unread count.'],
            ['title' => 'Check unavailable destinations', 'body' => 'If an object is unavailable, check club and account and reload. Changed permissions or a removed object may prevent access.'],
        ],
        'notes' => ['An unread notification does not grant additional object permissions. Club-branded apps do not switch to other clubs.', 'Inbox and system push are separate: a notification can exist without a push alert.'],
    ],
    'push' => [
        'title' => 'Enable and check push notifications',
        'intro' => 'Push requires Profile permission, operating-system permission and complete app and server setup.',
        'steps' => [
            ['title' => 'Check the Profile switch', 'body' => 'Open Profile and find notification settings. Read any notice that this build or environment is unavailable.'],
            ['title' => 'Enable notifications', 'body' => 'Deliberately enable the switch and confirm the system prompt. Denied system permission may need changing in device settings.'],
            ['title' => 'Check device settings', 'body' => 'In the operating system’s settings for the app, review notification permission, display options and Focus or Do Not Disturb modes.'],
            ['title' => 'Compare with the inbox', 'body' => 'If a push is missing, first check the inbox and active account. Report repeated issues with environment, time and device type through Support.'],
        ],
        'notes' => ['Connection, power-saving rules and focus modes affect system delivery; a particular delivery time is not guaranteed.', 'Signing in again does not automatically configure a missing provider.'],
    ],
    'diagnostics' => [
        'title' => 'Find help and prepare diagnostic contact',
        'intro' => 'The knowledge base explains operation. Report technical errors reproducibly with a reviewed diagnostic attachment.',
        'steps' => [
            ['title' => 'Find a guide', 'body' => 'Open Help & Support from the module menu and search for the task or module. Read the app-specific guide and its notes.'],
            ['title' => 'Describe the error', 'body' => 'Note action, expected and actual result, environment, club and time. Explain whether the error is reproducible.'],
            ['title' => 'Prepare diagnostic contact', 'body' => 'Use the mail/diagnostics icon in Support. Review displayed account, club and device information, sanitised technical events and the prepared attachment.'],
            ['title' => 'Send deliberately', 'body' => 'Review recipient, message and attachments in the mail or sharing flow, then send yourself. An opened draft is not confirmed delivery. Alternatively use an enabled support ticket.'],
        ],
        'notes' => ['Do not include passwords, sign-in codes, keybox PINs or unreviewed identity photos in the description.', 'A support request does not change club data, grant permission or trigger a payment.'],
    ],
];
