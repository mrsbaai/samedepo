<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Support Agent Identity
    |--------------------------------------------------------------------------
    |
    | The agent name shown next to admin replies is displayed as
    | "{agent_name} from Support" (e.g. "Robert" becomes "Robert from
    | Support"). Leave unset to just show "Support". Changing this only
    | affects new messages — each admin message stores a snapshot of the
    | formatted name at the time it was sent, so past conversations keep
    | showing the name the user actually talked to. Admin messages always
    | show a "Support" badge instead of a personal avatar.
    |
    */

    'agent_name' => env('SUPPORT_AGENT_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Automatic Ticket Closing
    |--------------------------------------------------------------------------
    |
    | Open tickets with no new message for this many days are automatically
    | closed by the `support:close-inactive-tickets` scheduled command, and
    | the ticket owner is emailed to let them know.
    |
    */

    'auto_close_after_days' => (int) env('SUPPORT_AUTO_CLOSE_AFTER_DAYS', 7),

];
