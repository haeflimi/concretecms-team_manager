<?php

return [
    // maximum number of members per team, 0 = unlimited
    'max_team_size' => 0,
    // pending invitations and join requests older than this are treated as expired, 0 = never
    'request_expiry_days' => 14,
    // maximum length of the short team tag, e.g. "TCN"
    'tag_max_length' => 8,
    // IDs below are written by TeamInstaller on install / upgrade
    // "Team Pool" group type, its roles and the "Team Pools" group folder
    'group_type_id' => null,
    'player_role_id' => null,
    'free_agent_role_id' => null,
    'group_folder_id' => null,
    'logo_folder_id' => null,
];
