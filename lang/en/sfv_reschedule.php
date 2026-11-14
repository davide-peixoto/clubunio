<?php

return [
    'heading' => 'Impact of rescheduling the match',
    'no_conflicts' => 'No known conflicts for the new pitch allocation.',
    'restricted' => 'Linked team events need manual review. Count: :count',
    'blocked' => 'Resolve pitch conflicts or review the affected team events first.',
    'single_required' => 'Select this match individually and review the impact of rescheduling it.',
    'confirm' => 'Apply the new match allocation and the team event changes shown here? Team and pitch planning notifications will be triggered. Resolve any conflicts by rescheduling other bookings separately.',
    'apply' => 'Apply match reschedule',
    'stale' => 'The impact of rescheduling has changed. Review it again.',
    'conflicts' => [
        'training' => 'Overlapping training session',
        'competition' => 'Overlapping match',
        'request' => 'Overlapping booking',
        'block' => 'Overlapping pitch closure',
        'unavailable' => 'Pitch is unavailable',
        'restricted' => 'Pitch scheduling conflict',
    ],
];
