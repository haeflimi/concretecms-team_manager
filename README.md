# team_manager

Concrete CMS 9 package that adds **Teams** with captains, invitations, join requests and a small team profile
(tag, description, logo), organized in **Team Pools**.

## How teams are stored

Teams are **not** core groups. They live in the package tables only, so team memberships never show up in the
users' group memberships and can't be used in permissions. Pools on the other hand **are** core groups, so a whole
pool can be used for permissions.

| What | Where |
|---|---|
| Team (name, tag, description, logo file, pool) | `tmTeam` |
| Team members, captain flag | `tmTeamMember` |
| Invitations & join requests | `tmTeamRequest` (core `GroupJoinRequests` is not used, its notifications are broken in 9.4) |
| Pool | core group of the group type **Team Pool** in the **Team Pools** group folder + settings in `tmTeamPool` (same ID) |
| Free agents (users looking for a team in a pool) | `tmTeamPoolUser` |
| Logos | file manager folder **Team Logos** |

The package tables are the source of truth. The members of a pool group are kept in sync automatically
(`TeamManager\Team\TeamPoolMembership`):

* players of the pool's teams get the group role **Team Player** (default role of the type)
* free agents get the role **Free Agent**
* everybody else is removed from the group

Changes made to pool groups in Dashboard › Groups are overwritten on the next change of that user, or with
"Resync group members" on the pool page.

The IDs of the group type, roles and folders are stored in the package config
(`team_manager::settings.*`), see `config/settings.php` for the other options:

* `max_team_size` – members per team, 0 = unlimited (teams in a pool with its own max. team size use that instead)
* `request_expiry_days` – open invitations / join requests expire after this many days, 0 = never
* `tag_max_length` – max length of the team tag
* `team_name_adjectives`, `team_name_nouns` – comma separated word lists for random team names
  ("Furious Llamas"), used by the **Random** button next to the name when creating a team (Team Directory,
  Dashboard › Add Team). Taken names are skipped. Override them in `application/config/team_manager/settings.php`:

  ```php
  <?php
  return [
      'team_name_adjectives' => 'Swiss, Alpine, Grumpy',
      'team_name_nouns' => 'Yodelers, Cheesemakers, Goats',
  ];
  ```

  The lists are strings on purpose: an override replaces them as a whole, override arrays would be merged with
  the default lists by index.

## Team Pools

A pool holds teams and/or single users that want to join a team. Pools can only be created on the dashboard.

* A team is in at most one pool, teams can also have no pool.
* A user can be in **only one team per pool**. Joining, invites, accepting, adding and moving are rejected otherwise.
* Single players are listed as "looking for a team" until they join a team of that pool, then their entry is removed.
* Pool settings: open for joining, captains may register teams, users may create new teams (My Teams), users may
  join as single player, max. teams.
  They apply to users only, admins can always add teams and players.
* **Max. team size** (0 = the global `max_team_size` applies): members per team of the pool. It applies to admins
  too: adding or moving members into a full team, and putting a too big team into the pool are rejected. The limit
  can't be lowered below the size of a team already in the pool.
* Deleting a pool deletes its group, the teams are kept (they have no pool afterwards). Deleting a pool group in
  Dashboard › Groups has the same effect.


* **My Teams** – answer invitations, manage your teams (invite, approve join requests,
  promote/demote captains, remove members, edit profile, leave, disband), see the pools you're listed in as
  single player. The block has no options.
  The package also adds the account page **My Teams** (`/account/teams`) with this block, listed in the account
  menu next to "Edit Profile". It uses the core account controller and theme, no core or theme file is overridden.
  The page is created on install / upgrade and removed on uninstall.
* **Team Directory** – overview of all pools, search across the teams in pools, pool view
  (`?pool=<id>`: teams, players looking for a team, join as single player, create a team, register your team,
  invite players to your team) and team view (`?team=<id>`, "Request to join").
  Users can only create teams in a pool: "Create a new team" is shown if the pool is open, allows team creation,
  isn't full and the user isn't in a team of it yet. Teams without pool are created on the dashboard, the
  directory doesn't list them and they can't be joined there.
  Options: limit joining to one pool, and optionally only show that pool.

## REST API

Two read-only endpoints are added to the Concrete REST API (`/ccm/api/1.0`) when the API is enabled
(Dashboard › System & Settings › API). Both need the scope **`team_manager:read`** ("Read team pools, teams and
their members"); grant it to an integration in Dashboard › System & Settings › API › Integrations. It works with
client credentials and authorization code tokens.

| Endpoint | Returns |
|---|---|
| `GET /ccm/api/1.0/team_pools` | all pools: id, name, description, `team_count`, `player_count`, `free_agent_count`, `settings` (`open`, `allow_teams`, `allow_team_creation`, `allow_free_agents`, `max_teams`, `max_team_size`), `date_created` |
| `GET /ccm/api/1.0/team_pools/{poolID}/teams` | the pool's teams: id, name, tag, description, `logo_url`, `pool_id`, `member_count`, `members` (`user_id`, `username`, `captain`, `date_joined`), `date_created`; 404 if the pool doesn't exist |

Responses are wrapped in `{"data": [...]}` like the core endpoints. `max_team_size` is the pool's limit or the
global `max_team_size`, 0 = unlimited.

```sh
curl -d "grant_type=client_credentials&client_id=…&client_secret=…&scope=team_manager:read" https://example.com/oauth/2.0/token
curl -H "Authorization: Bearer <access_token>" https://example.com/ccm/api/1.0/team_pools
```

Implementation: `TeamManager\Api\ApiIntegration` registers `routes/api.php` with the core API middleware, stores the
scope on install / upgrade (removed on uninstall) and adds the scope, the endpoints and the `TeamPool` / `Team`
schemas to the OpenAPI spec. That keeps the scope alive when Concrete synchronizes its scopes (it deletes scopes
missing from the spec) and lists the endpoints in the API documentation.

## Export for tournament platforms

Dashboard › Teams › **Export** (list / board filtered by a pool, and the pool page) downloads a pool's teams in seed
order (by name). The files contain member emails, they're only available on the dashboard
(`TeamManager\Team\Export\TeamPoolExporter`, one class per format in `src/Team/Export/Format`).

| Format | Content | Use it for |
|---|---|---|
| Team names (.txt) | one team name per line (commas removed) | Challonge "Bulk Add" (paste), start.gg, bracket generators |
| Team names with captain email (.txt) | `Team name, captain email` | Challonge "Bulk Add", invites the captains by email |
| Battlefy CSV | `teamName,player,email`, one row per member, captains first | Battlefy team import (players without account get a verification email) |
| Roster CSV | `team_id,team_name,tag,seed,player_id,username,email,captain,free_agent,date_joined`, free agents at the end | Tournify (paste from Excel), spreadsheets, other platforms |

CSV files are UTF-8 with BOM so Excel shows umlauts. Platform notes (as of 2026-10):

* **Toornament** has no file import, participants with lineups can only be created through its API v2
  (paid Arena plan or API plan). Team names max. 40 characters.
* **Challonge** imports names only; rosters are built by the captains through invites in Challonge.
* **Battlefy** and **Tournify** take complete rosters from a file / paste.

## Dashboard

**Dashboard › Users & Groups › Teams** (`/dashboard/users/teams`), access is controlled by the page permissions.

* **List** – all teams with pool, captains, member count and creation date, pool filter, search, and totals
  (teams, users in teams, empty teams, pools, players looking for a team). Below the teams the players looking for
  a team, with their pool and note: add them to a team of their pool or remove them from the pool. The pool filter
  and the search (username, note) apply to them too.
* **Pools** – create, edit and delete pools; per pool: add/remove teams, add/remove single players and
  add them to a team of the pool.
* **Board** – every team as a card, filterable by pool. Teams that aren't full show their free places as empty
  slots (one "Drop a player here" slot without size limit): drop a member of another team on a slot to move them.
  Drop a member onto a member of another team to swap the two, this works with full teams too
  (`TeamService::swapMembers()`, one transaction). Add members by username, promote/demote and remove with the
  icons on hover. Optionally captains keep their role when moved or swapped, otherwise a team that lost its captain
  gets its longest standing member as captain. Filtered by a pool, the players looking for a team are shown on top
  and can be dragged onto an empty slot.
* **Randomize** – in the list and board view when filtered by a pool, each action asks for confirmation first
  (`TeamManager\Team\TeamRandomizer`, one transaction, nothing changes if it fails):
  * *Complete randomize* deletes all teams of the pool and shuffles all its players (team players and players looking
    for a team) into new teams with random names.
  * *Randomize unteamed players* shuffles only the players looking for a team into new teams, existing teams stay.
  * *Randomize names only* gives the pool's teams new random names, everything else stays.

  The players per team are chosen in the dialog (max. the pool's team size limit), the teams are filled evenly and
  the first player of each new team becomes captain. The pool's max. number of teams is not checked (admin action).
* **Team** – change the pool, edit name, tag, description and logo, add members (optionally as captain), move members to
  another team, change roles, see/cancel open invitations and join requests, delete the team.
* **Add Team** – create a team, optionally with a captain right away.

Admin actions use `TeamService::asAdmin()`: captain checks and the global `max_team_size` are skipped, and a team is not
deleted automatically when its last member is removed (users leaving still delete an empty team).
The first member added to a team without captain becomes captain.

## Rules

* The creator of a team becomes its captain. Only captains (and super users) can invite, approve,
  change roles, remove members, edit or disband the team.
* A team always keeps at least one captain: if the last captain leaves, the longest standing member is promoted.
* If the last member leaves, the team is deleted.

## API / events

Use `TeamManager\Team\TeamService` for all changes (incl. `addMember()` and `moveMember()`) and `TeamManager\Team\TeamRepository` to read teams.
The service dispatches `TeamManager\Team\Event\TeamEvent` as:

`on_team_create`, `on_team_update`, `on_team_member_join`, `on_team_member_leave`, `on_team_role_change`,
`on_team_pool_change`, `on_team_disband`

Pools: `TeamService::setTeamPool()`, `joinPool()`, `leavePool()`, `assignSingle()`; pool CRUD in
`TeamManager\Team\TeamPoolService`, reading in `TeamManager\Team\TeamPoolRepository`.

## Translations

All user facing texts use `t()` / `t2()`, also the JavaScript texts (passed from PHP). Included: German in
`languages/de_DE` (German spelling) and `languages/de_CH` (Swiss spelling, «» quotes), informal "du". Notification
mails are rendered in the recipient's language (their profile language, else the site's default locale).

The names of the group type, its roles and the folders ("Team Pool", "Team Player", "Free Agent", "Team Pools",
"Team Logos") are stored when the package is installed, in the language active at that moment.

To update the translations after changing texts (run as the web server user, the package's `languages` folder must
be writable for it):

```sh
concrete/bin/concrete c5:package-translate team_manager -l de_DE -l de_CH -x
```

This updates `languages/messages.pot` and the `.po` files (existing translations are kept), translate the new
entries in the `.po` files, then run the command again to compile the `.mo` files.

## Upgrading from 2.x

In 2.x teams were core groups. 3.0 does **not** convert them: the old team tables are dropped on upgrade, the
group type is renamed to *Team Pool*, its Captain / Member roles are replaced by Team Player / Free Agent and the
"Teams" group folder becomes "Team Pools". Groups created as teams by 2.x keep the (renamed) type but are not
pools, delete them in Dashboard › Groups.

