<?php

return [
    'command_description' => 'Dispatch committed friendly-request test emails to the fixed test recipient; explicit dispatch and both mail switches required.',
    'closed' => 'Pitch test-mail delivery is closed. Explicit dispatch and both test-mail switches are required in a permitted environment.',
    'invalid_scope' => 'Specify a positive tenant ID, an optional positive event ID, and a limit between 1 and 50.',
    'result' => 'Test recipient: :recipient. Accepted: :accepted. Require review: :failed. Interrupted or uncertain sends are never retried automatically.',
];
