<?php

// Public instructions for additional app modules.
return [
    'training' => [
        'title' => 'Plan a training session and share with coaches',
        'intro' => 'Plan training supports authorised preparation, saved plans and suggestions that are explicitly accepted.',
        'steps' => [
            [
                'title' => 'Open the planner',
                'body' => 'Open Plan training from the module menu and select an offered team. Check existing saved sessions before generating a new plan.',
            ],
            [
                'title' => 'Set the parameters',
                'body' => 'Enter the displayed training parameters, including player count, goal and constraints. Review them and deliberately start the available creation action.',
            ],
            [
                'title' => 'Review and edit',
                'body' => 'Wait for the loaded plan and professionally check its setup and sequence. Use offered editing or comparison. Apply a suggestion only with the explicit acceptance action.',
            ],
            [
                'title' => 'Use collaboration and PDF',
                'body' => 'Open offered collaboration for authorised coaches or the PDF preview. Check the current plan before sharing or exporting.',
            ],
        ],
        'notes' => [
            'AI features require club permission and provider configuration. A suggestion is not automatically a reliable training instruction.',
            'After an uncertain creation result, check the existing job rather than repeatedly starting paid requests.',
        ],
    ],
    'facilities' => [
        'title' => 'Check facility status and closures',
        'intro' => 'Facility status shows the club’s configured pitches and their current state.',
        'steps' => [
            [
                'title' => 'Open facility status',
                'body' => 'Open Facility status from the module menu or the offered status view in pitch occupancy. Check the selected club.',
            ],
            [
                'title' => 'Read the facility card',
                'body' => 'Read the relevant facility card, current status and notes. Another facility can have a different status.',
            ],
            [
                'title' => 'Change status with permission',
                'body' => 'If a status action is offered, choose the appropriate configured status and review other details. Deliberately confirm the change and check the refreshed state.',
            ],
            [
                'title' => 'Clarify conflicting information',
                'body' => 'Reload if information differs. Clarify an unexplained closure with the responsible club office instead of bypassing it through another booking.',
            ],
        ],
        'notes' => [
            'Status changes require specific permissions. Seeing a facility does not grant permission to release it.',
            'Facility status and an approved reservation are separate information. Follow the club’s requirements for actual use.',
        ],
    ],
    'challenge' => [
        'title' => 'Play the club challenge and view rankings',
        'intro' => 'The club challenge offers shooting and goalkeeping. Availability depends on club release.',
        'steps' => [
            [
                'title' => 'Open the challenge',
                'body' => 'Open Club challenge from the module menu if enabled. Check the displayed club and game options.',
            ],
            [
                'title' => 'Choose discipline and round',
                'body' => 'Choose penalty shooting or saving. Start a club round for possible ranking or unranked training.',
            ],
            [
                'title' => 'Play ten attempts',
                'body' => 'When shooting, choose a corner to match the power bar. When saving, watch the flight direction and react in time. Follow the on-screen instructions until the round ends.',
            ],
            [
                'title' => 'Check the result',
                'body' => 'Read the round result and ranking-transfer confirmation. Open rankings and select the offered discipline. If transfer fails, use only the offered result retry.',
            ],
        ],
        'notes' => [
            'A local practice round is not automatically a saved club-ranking entry. The displayed saving status remains authoritative.',
            'Starting a new challenge or resetting rankings is a separate administrative action with significant consequences.',
        ],
    ],
    'sponsor_run' => [
        'title' => 'Participate in a sponsored run and follow pledges',
        'intro' => 'Sponsored run brings together enabled events, participants and sponsor pledges.',
        'steps' => [
            [
                'title' => 'Select an event',
                'body' => 'Open Sponsored run and select an enabled event. Check season, date and the offered personal, team or helper view.',
            ],
            [
                'title' => 'Check participants',
                'body' => 'Open yourself or someone you may represent. Review tasks and existing pledges. Parents deliberately select the correct child.',
            ],
            [
                'title' => 'Create a sponsor link deliberately',
                'body' => 'Open the offered QR/sharing view. Read the name-sharing consent and create the link only after explicit confirmation. Check it before forwarding.',
            ],
            [
                'title' => 'Review laps and results',
                'body' => 'Authorised helpers record laps or check-in using offered actions. Review draft and preview before a binding release. Invoice status can be viewed with permission.',
            ],
        ],
        'notes' => [
            'Seeing an event does not grant helper, release or administration rights.',
            'A public sponsor link shares released details. It does not enable private invoicing or payment actions.',
        ],
    ],
    'registrations' => [
        'title' => 'Review member registrations with permission',
        'intro' => 'Registrations is the authorised review view for submitted membership or trial-training requests.',
        'steps' => [
            [
                'title' => 'Open Registrations',
                'body' => 'Open Registrations from the module menu if available. Find the application and check person, status and team.',
            ],
            [
                'title' => 'Review details and attachments',
                'body' => 'Open the detail view and check released information and available attachments. Download only documents needed for your task.',
            ],
            [
                'title' => 'Choose assignment or status action',
                'body' => 'Use offered editing, team assignment or status actions. Check their scope and complete required fields before confirming.',
            ],
            [
                'title' => 'Check the new state',
                'body' => 'Reload the confirmed application and inspect status and assignment. After a conflict or unclear network result, check the current state first.',
            ],
        ],
        'notes' => [
            'Coaches handle only authorised team-related applications; this does not grant general admission or financial rights.',
            'Accepted applications may remain traceable in Registrations even when no longer shown in My Teams.',
        ],
    ],
    'approvals' => [
        'title' => 'Handle pending work and approvals in the app',
        'intro' => 'Authorised administration roles see server-enabled pending-work tiles on Today.',
        'steps' => [
            [
                'title' => 'Review Today’s pending work',
                'body' => 'Open Today and review offered tiles and counts for change requests, registrations, pitch requests, expenses, bus and documents.',
            ],
            [
                'title' => 'Open a category',
                'body' => 'Tap the required category. Check list and filters because a count is an overview, not an individual application.',
            ],
            [
                'title' => 'Review the request and evidence',
                'body' => 'Open the item and check person or resource, period and available evidence. For expenses, inspect line items and transfer status.',
            ],
            [
                'title' => 'Confirm a decision deliberately',
                'body' => 'Choose the offered approval, rejection or change action and complete required details. Then check the current item status. After an unclear result, reload first.',
            ],
        ],
        'notes' => [
            'Tiles and actions depend on specific permissions, not just a role label. Missing tiles do not grant indirect administrative access.',
            'An approval does not execute an actual bank payment. Check consequences before each binding decision.',
        ],
    ],
    'getting_started' => [
        'title' => 'Find your way around Today and the menus',
        'intro' => 'The start screen shows relevant modules, upcoming events and authorised work without repeating every function.',
        'steps' => [
            [
                'title' => 'Check account and club',
                'body' => 'Read the club name in the header and inspect Profile if needed. Switch to the intended club before editing.',
            ],
            [
                'title' => 'Open key modules',
                'body' => 'Use Expenses, Members, the centre chat button and Events in the bottom navigation where enabled. The menu contains additional modules.',
            ],
            [
                'title' => 'Review upcoming events',
                'body' => 'Open an upcoming event on Today and check yourself or the person you represent. Respond within the event itself.',
            ],
            [
                'title' => 'Use getting started and help',
                'body' => 'Use offered getting-started tasks for your role. If an action is unclear, open Help & Support and find its guide.',
            ],
        ],
        'notes' => [
            'Accounts do not all see the same modules or tasks. The server decides from club configuration and personal permissions.',
            'An introduction does not grant permission or make business changes without your explicit action.',
        ],
    ],
];
