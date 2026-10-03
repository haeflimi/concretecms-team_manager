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
* **Team Directory** – overview of all pools and the teams without pool, search across all teams, pool view
  (`?pool=<id>`: teams, players looking for a team, join as single player, create a team, register your team,
  invite players to your team) and team view (`?team=<id>`, "Request to join").
  Users can only create teams in a pool: "Create a new team" is shown if the pool is open, allows team creation,
  isn't full and the user isn't in a team of it yet. Teams without pool are created on the dashboard.
  Options: limit joining to one pool, and optionally only show that pool.

## Dashboard

**Dashboard › Users & Groups › Teams** (`/dashboard/users/teams`), access is controlled by the page permissions.

* **List** – all teams with pool, captains, member count and creation date, pool filter, search, and totals
  (teams, users in teams, empty teams, pools, players looking for a team).
* **Pools** – create, edit and delete pools; per pool: add/remove teams, add/remove single players and
  add them to a team of the pool.
* **Board** – every team as a card, filterable by pool. Drag a member onto another card to move them, add
  members by username, promote/demote and remove with the icons on hover. Optionally captains keep their role
  when moved. Filtered by a pool, the players looking for a team are shown on top and can be dragged onto a team.
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

## Upgrading from 2.x

In 2.x teams were core groups. 3.0 does **not** convert them: the old team tables are dropped on upgrade, the
group type is renamed to *Team Pool*, its Captain / Member roles are replaced by Team Player / Free Agent and the
"Teams" group folder becomes "Team Pools". Groups created as teams by 2.x keep the (renamed) type but are not
pools, delete them in Dashboard › Groups.

