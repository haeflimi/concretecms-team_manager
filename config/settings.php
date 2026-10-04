<?php

return [
    // maximum number of members per team, 0 = unlimited
    'max_team_size' => 0,
    // pending invitations and join requests older than this are treated as expired, 0 = never
    'request_expiry_days' => 14,
    // maximum length of the short team tag, e.g. "TCN"
    'tag_max_length' => 8,
    // random team names are "<adjective> <noun>", comma separated lists. Override them in
    // application/config/team_manager/settings.php, an override replaces the whole list:
    // return ['team_name_adjectives' => 'Swiss, Alpine', 'team_name_nouns' => 'Yodelers, Cheesemakers'];
    'team_name_adjectives' => '
        Angry, Atomic, Blazing, Brutal, Chaotic, Cosmic, Crimson, Cursed, Cyber, Dark,
        Deadly, Electric, Elite, Fearless, Feral, Fiery, Frozen, Furious, Galactic, Golden,
        Haunted, Hyper, Infernal, Iron, Legendary, Lunar, Mad, Mighty, Mystic, Neon,
        Nuclear, Phantom, Quantum, Radioactive, Rabid, Raging, Reckless, Rogue, Royal, Rusty,
        Savage, Shadow, Silent, Sneaky, Solar, Stealthy, Thundering, Toxic, Turbo, Wild
    ',
    'team_name_nouns' => '
        Assassins, Badgers, Bandits, Berserkers, Cobras, Crusaders, Cyborgs, Dragons, Eagles, Falcons,
        Gladiators, Goblins, Golems, Griffins, Hornets, Hunters, Hydras, Invaders, Jackals, Knights,
        Krakens, Llamas, Mammoths, Mercenaries, Monks, Ninjas, Outlaws, Owls, Pandas, Panthers,
        Phoenixes, Pirates, Raccoons, Raptors, Ravens, Rebels, Robots, Ronin, Samurai, Scorpions,
        Sharks, Spartans, Squirrels, Titans, Trolls, Valkyries, Vikings, Vipers, Wizards, Wolves
    ',
    // IDs below are written by TeamInstaller on install / upgrade
    // "Team Pool" group type, its roles and the "Team Pools" group folder
    'group_type_id' => null,
    'player_role_id' => null,
    'free_agent_role_id' => null,
    'group_folder_id' => null,
    'logo_folder_id' => null,
];
