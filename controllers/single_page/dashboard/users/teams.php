<?php
namespace Concrete\Package\TeamManager\Controller\SinglePage\Dashboard\Users;

use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use TeamManager\Entity\TeamPool;
use TeamManager\Entity\TeamPoolUser;
use TeamManager\Entity\TeamRequest;
use TeamManager\Entity\Team;
use TeamManager\Entity\TeamMember;
use TeamManager\Team\Pagination;
use TeamManager\Team\TeamNameGenerator;
use TeamManager\Team\TeamRandomizer;
use TeamManager\Team\UserSearch;
use TeamManager\Team\Export\TeamPoolExporter;
use TeamManager\Team\TeamPoolMembership;
use TeamManager\Team\TeamPoolRepository;
use TeamManager\Team\TeamPoolService;
use TeamManager\Team\TeamRepository;
use TeamManager\Team\TeamRequestRepository;
use TeamManager\Team\TeamService;

/**
 * Dashboard > Users & Groups > Teams: list, board (drag & drop), team and pool views for administrators.
 * Pools can only be created here.
 * Access is controlled by the page permissions of this dashboard page.
 */
class Teams extends DashboardPageController
{
    const TOKEN = 'team_dashboard';
    const PATH = '/dashboard/users/teams';

    public function view()
    {
        $keywords = trim((string) $this->request->query->get('keywords'));
        $page = $this->teams()->paginate($this->getPoolFilter(), $keywords, Pagination::getCurrentPage(), 50);

        $this->set('mode', 'list');
        $this->set('keywords', $keywords);
        $this->set('teams', $page['items']);
        $this->set('pagination', Pagination::render($page, (string) $this->app->make('url/manager')->resolve([self::PATH]), [
            'keywords' => $keywords,
            'pool' => (string) $this->request->query->get('pool'),
        ]));
        $this->set('stats', $this->getStats());
        $this->setListSingles($keywords);
        $this->setRandomizeData($this->getPoolFilter());
        $this->setCommon();
    }

    /**
     * Players looking for a team, filtered like the team list (pool filter, keywords on username and note),
     * with the teams of their pools to add them to.
     */
    protected function setListSingles(string $keywords): void
    {
        $filter = $this->getPoolFilter();
        $pools = $this->poolRepository();
        if ($filter === false) {
            // teams without pool, single players are always in a pool
            $singles = [];
        } else {
            $singles = $filter instanceof TeamPool ? $pools->getSingles($filter) : $pools->getAllSingles();
        }

        $userInfos = $this->app->make(UserInfoRepository::class);
        $entries = [];
        $poolTeams = [];
        foreach ($singles as $single) {
            $ui = $userInfos->getByID($single->getUserID());
            $name = $ui ? $ui->getUserName() : t('Deleted user');
            if ($keywords !== '' && mb_stripos($name, $keywords) === false && mb_stripos($single->getNote(), $keywords) === false) {
                continue;
            }
            $entries[] = ['single' => $single, 'name' => $name];
            $poolID = $single->getPool()->getID();
            if (!isset($poolTeams[$poolID])) {
                $poolTeams[$poolID] = $this->teams()->findAll($single->getPool());
            }
        }
        usort($entries, function (array $a, array $b) {
            return strcasecmp($a['single']->getPool()->getName(), $b['single']->getPool()->getName()) ?: strcasecmp($a['name'], $b['name']);
        });

        $this->set('listSingles', $entries);
        $this->set('listSinglesShown', $filter !== false);
        $this->set('singlePoolTeams', $poolTeams);
    }

    /**
     * All teams side by side, members can be dragged between them.
     */
    public function board()
    {
        $pool = $this->getPoolFilter();
        $this->set('mode', 'board');
        $this->set('boardData', array_map([$this, 'serializeTeam'], $this->teams()->findAll($pool)));
        // single players of the pool can be dragged onto its teams
        $this->set('boardPool', $pool instanceof TeamPool ? $pool : null);
        $this->set('boardSingles', $pool instanceof TeamPool ? $this->serializeSingles($pool) : []);
        $this->setRandomizeData($pool);
        $this->setCommon();
    }

    public function team($id = null)
    {
        $team = $this->teams()->getByID((int) $id);
        if (!$team) {
            $this->flash('error', t('The team does not exist.'));

            return $this->buildRedirect(self::PATH);
        }
        $requests = $this->app->make(TeamRequestRepository::class);

        $otherTeams = [];
        foreach ($this->teams()->findAll() as $other) {
            if ($other->getID() !== $team->getID()) {
                $otherTeams[$other->getID()] = $other->getDisplayName();
            }
        }

        $this->set('mode', 'team');
        $this->set('team', $team);
        $this->set('otherTeams', $otherTeams);
        $this->set('joinRequests', $requests->getPendingForTeam($team->getID(), TeamRequest::TYPE_JOIN));
        $this->set('invites', $requests->getPendingForTeam($team->getID(), TeamRequest::TYPE_INVITE));
        $this->set('userInfoRepository', $this->app->make(UserInfoRepository::class));
        $this->set('pageTitle', t('Team: %s', $team->getDisplayName()));
        $this->set('poolOptions', $this->getPoolOptions(t('No pool')));
        $this->setCommon();
    }

    public function add()
    {
        $this->set('mode', 'add');
        $this->set('pageTitle', t('Add Team'));
        $this->set('poolOptions', $this->getPoolOptions(t('No pool')));
        $this->set('selectedPoolID', (int) $this->request->query->get('pool'));
        $this->setCommon();
    }

    public function create_team()
    {
        return $this->respond(function (UserInfo $me, TeamService $service) {
            $team = $service->create(
                (string) $this->request->request->get('name'),
                (string) $this->request->request->get('tag'),
                (string) $this->request->request->get('description'),
                $me,
                false,
                $this->poolRepository()->getByID((int) $this->request->request->get('pool'))
            );
            $captain = trim((string) $this->request->request->get('captain'));
            if ($captain !== '') {
                $service->addMember($team, $this->findUser($captain), $me, true);
            }
            $this->redirectTo = self::PATH . '/team/' . $team->getID();

            return [t('Team %s has been created.', $team->getName()), [$team]];
        });
    }

    public function update_team()
    {
        return $this->respond(function (UserInfo $me, TeamService $service) {
            $team = $this->postedTeam();
            if ($this->request->request->get('removeLogo')) {
                $service->removeLogo($team, $me);
            }
            $team = $service->update(
                $team,
                (string) $this->request->request->get('name'),
                (string) $this->request->request->get('tag'),
                (string) $this->request->request->get('description'),
                $me,
                $this->request->files->get('logo')
            );

            return [t('Team %s has been saved.', $team->getName()), [$team]];
        });
    }

    public function add_member()
    {
        return $this->respond(function (UserInfo $me, TeamService $service) {
            $team = $this->postedTeam();
            $user = $this->findUser((string) $this->request->request->get('user'));
            $service->addMember($team, $user, $me, (bool) $this->request->request->get('captain'));

            return [t('%s has been added to %s.', $user->getUserName(), $team->getName()), [$team]];
        });
    }

    public function remove_member()
    {
        return $this->respond(function (UserInfo $me, TeamService $service) {
            $team = $this->postedTeam();
            $member = $this->postedMember($team);
            $service->removeMember($team, $member->getUserID(), $me);

            return [t('%s has been removed from %s.', $member->getUserName(), $team->getName()), [$team]];
        });
    }

    public function move_member()
    {
        return $this->respond(function (UserInfo $me, TeamService $service) {
            $from = $this->postedTeam();
            $to = $this->postedTeam('target');
            $member = $this->postedMember($from);
            $service->moveMember($from, $to, $member->getUserID(), $me, (bool) $this->request->request->get('keepCaptain'));

            return [t('%s has been moved from %s to %s.', $member->getUserName(), $from->getName(), $to->getName()), [$from, $to]];
        });
    }

    public function swap_members()
    {
        return $this->respond(function (UserInfo $me, TeamService $service) {
            $teamA = $this->postedTeam();
            $teamB = $this->postedTeam('target');
            $memberA = $this->postedMember($teamA);
            $memberB = $this->postedMember($teamB, 'targetUser');
            $service->swapMembers($teamA, $memberA->getUserID(), $teamB, $memberB->getUserID(), $me, (bool) $this->request->request->get('keepCaptain'));

            return [t('%s and %s have been swapped.', $memberA->getUserName(), $memberB->getUserName()), [$teamA, $teamB]];
        });
    }

    public function set_captain()
    {
        return $this->respond(function (UserInfo $me, TeamService $service) {
            $team = $this->postedTeam();
            $member = $this->postedMember($team);
            $captain = (bool) $this->request->request->get('captain');
            $service->setCaptain($team, $member->getUserID(), $captain, $me);

            return [
                $captain ? t('%s is now a captain.', $member->getUserName()) : t('%s is now a regular member.', $member->getUserName()),
                [$team],
            ];
        });
    }

    public function cancel_request()
    {
        return $this->respond(function (UserInfo $me, TeamService $service) {
            $request = $this->app->make(TeamRequestRepository::class)->getByID((int) $this->request->request->get('request'));
            if (!$request) {
                throw new UserMessageException(t('This request is no longer open.'));
            }
            $service->cancel($request, $me);

            return [t('The request has been cancelled.'), []];
        });
    }

    public function disband_team()
    {
        return $this->respond(function (UserInfo $me, TeamService $service) {
            $team = $this->postedTeam();
            $service->disband($team, $me);
            $this->redirectTo = self::PATH;

            return [t('Team %s has been deleted.', $team->getName()), []];
        });
    }

    public function pools()
    {
        $pools = [];
        foreach ($this->poolRepository()->getAll() as $pool) {
            $pools[] = [
                'pool' => $pool,
                'teams' => $this->poolRepository()->countTeams($pool),
                'singles' => $pool->getSingles()->count(),
            ];
        }
        $this->set('mode', 'pools');
        $this->set('pageTitle', t('Team Pools'));
        $this->set('pools', $pools);
        $this->setCommon();
    }

    public function add_pool()
    {
        $this->set('mode', 'pool_form');
        $this->set('pageTitle', t('Add Pool'));
        $this->set('pool', null);
        $this->setCommon();
    }

    public function pool($id = null)
    {
        $pool = $this->poolRepository()->getByID((int) $id);
        if (!$pool) {
            $this->flash('error', t('The pool does not exist.'));

            return $this->buildRedirect(self::PATH . '/pools');
        }

        // teams that can be added: all teams that aren't in this pool yet
        $otherTeams = [];
        foreach ($this->teams()->findAll() as $team) {
            $teamPool = $team->getPool();
            if (!$teamPool || $teamPool->getID() !== $pool->getID()) {
                $otherTeams[$team->getID()] = $team->getDisplayName() . ($teamPool ? ' (' . $teamPool->getName() . ')' : '');
            }
        }

        $this->set('mode', 'pool');
        $this->set('pageTitle', t('Pool: %s', $pool->getName()));
        $this->set('pool', $pool);
        $this->set('poolTeams', $this->teams()->findAll($pool));
        $this->set('otherTeams', $otherTeams);
        $this->set('userInfoRepository', $this->app->make(UserInfoRepository::class));
        $this->setCommon();
    }

    public function save_pool()
    {
        return $this->respond(function () {
            $service = $this->app->make(TeamPoolService::class);
            $data = $this->request->request->all();
            $pool = $this->poolRepository()->getByID((int) $this->request->request->get('pool'));
            if ($pool) {
                $service->update($pool, $data);
                $message = t('Pool %s has been saved.', $pool->getName());
            } else {
                $pool = $service->create($data);
                $message = t('Pool %s has been created.', $pool->getName());
            }
            $this->redirectTo = self::PATH . '/pool/' . $pool->getID();

            return [$message, []];
        });
    }

    public function delete_pool()
    {
        return $this->respond(function () {
            $pool = $this->postedPool();
            $name = $pool->getName();
            $this->app->make(TeamPoolService::class)->delete($pool);
            $this->redirectTo = self::PATH . '/pools';

            return [t('Pool %s has been deleted, its teams have no pool now.', $name), []];
        });
    }

    public function set_team_pool()
    {
        return $this->respond(function (UserInfo $me, TeamService $service) {
            $team = $this->postedTeam();
            $pool = $this->poolRepository()->getByID((int) $this->request->request->get('pool'));
            $service->setTeamPool($team, $pool, $me);

            return [
                $pool ? t('%s is now part of %s.', $team->getName(), $pool->getName()) : t('%s is not part of a pool anymore.', $team->getName()),
                [$team],
            ];
        });
    }

    public function add_single()
    {
        return $this->respond(function (UserInfo $me, TeamService $service) {
            $pool = $this->postedPool();
            $user = $this->findUser((string) $this->request->request->get('user'));
            $service->joinPool($pool, $user, (string) $this->request->request->get('note'));

            return [t('%s is now looking for a team in %s.', $user->getUserName(), $pool->getName()), [], $pool];
        });
    }

    public function assign_single()
    {
        return $this->respond(function (UserInfo $me, TeamService $service) {
            $single = $this->postedSingle();
            $pool = $single->getPool();
            $team = $this->postedTeam();
            $service->assignSingle($single, $team, $me);

            return [t('The player has been added to %s.', $team->getName()), [$team], $pool];
        });
    }

    public function remove_single()
    {
        return $this->respond(function (UserInfo $me, TeamService $service) {
            $single = $this->postedSingle();
            $pool = $single->getPool();
            $service->leavePool($single, $me);

            return [t('The player has been removed from %s.', $pool->getName()), [], $pool];
        });
    }

    /**
     * Rebuilds the pool group memberships from the teams and free agents of the pool.
     */
    public function resync_pool()
    {
        return $this->respond(function () {
            $pool = $this->postedPool();
            $this->app->make(TeamPoolMembership::class)->syncAll($pool);

            return [t('The members of the group %s have been updated.', $pool->getName()), []];
        });
    }

    /**
     * User autocomplete (js/user-search.js), with email addresses, the team / pool give each user's state.
     */
    public function search_users()
    {
        $query = $this->request->query;
        $mode = (string) $query->get('mode');
        if (!in_array($mode, [UserSearch::MODE_MEMBER, UserSearch::MODE_SINGLE, UserSearch::MODE_CAPTAIN], true)) {
            $mode = UserSearch::MODE_MEMBER;
        }

        return new JsonResponse($this->app->make(UserSearch::class)->search(
            (string) $query->get('q'),
            $mode,
            $this->teams()->getByID((int) $query->get('team')),
            $this->poolRepository()->getByID((int) $query->get('pool')),
            true
        ));
    }

    /**
     * Shuffles the players of a pool into random teams or gives its teams random names, see TeamRandomizer.
     */
    public function randomize_pool()
    {
        return $this->respond(function (UserInfo $me) {
            $pool = $this->postedPool();
            $mode = (string) $this->request->request->get('mode');
            $teams = $this->app->make(TeamRandomizer::class)->randomize($pool, $mode, (int) $this->request->request->get('teamSize'), $me);
            if ($mode === TeamRandomizer::MODE_NAMES) {
                $message = t2('%s team of %s has a new name.', '%s teams of %s have new names.', count($teams), $pool->getName());
            } else {
                $message = t2('%s new team has been created in %s.', '%s new teams have been created in %s.', count($teams), $pool->getName());
            }

            return [$message, $teams, $pool];
        });
    }

    /**
     * Downloads the pool's teams in a format for tournament platforms, see TeamPoolExporter.
     */
    public function export_pool($poolID = null, $format = null)
    {
        $pool = $this->poolRepository()->getByID((int) $poolID);
        if (!$pool) {
            $this->flash('error', t('The pool does not exist.'));

            return $this->buildRedirect(self::PATH);
        }
        try {
            return $this->app->make(TeamPoolExporter::class)->download($pool, (string) $format);
        } catch (UserMessageException $e) {
            $this->flash('error', $e->getMessage());

            return $this->buildRedirect(self::PATH . '/pool/' . $pool->getID());
        }
    }

    /**
     * Pool and numbers for the randomize menu and its confirmation dialogs, only when filtered by a pool.
     */
    protected function setRandomizeData($pool): void
    {
        if (!$pool instanceof TeamPool) {
            $this->set('randomizePool', null);

            return;
        }
        $randomizer = $this->app->make(TeamRandomizer::class);
        $teams = $this->teams()->findAll($pool);
        $this->set('randomizePool', $pool);
        $this->set('randomizeStats', [
            'teams' => count($teams),
            'players' => array_sum(array_map(function (Team $team) {
                return $team->getMemberCount();
            }, $teams)),
            'singles' => count($this->poolRepository()->getSingles($pool)),
            'sizeLimit' => $randomizer->getTeamSizeLimit($pool),
            'defaultSize' => $randomizer->getDefaultTeamSize($pool),
        ]);
    }

    /**
     * Random team name for the "Add Team" form.
     */
    public function random_team_name()
    {
        return new JsonResponse(['name' => $this->app->make(TeamNameGenerator::class)->generate()]);
    }

    /** @var string|null redirect target after a non-ajax action, defaults to the referring view */
    protected $redirectTo;

    /**
     * Runs an action and answers with JSON (board, ajax) or a redirect with flash message.
     *
     * @param callable $callback returns [message, Team[] affected teams, optional TeamPool whose singles changed]
     */
    protected function respond(callable $callback): Response
    {
        $ajax = $this->request->isXmlHttpRequest();
        try {
            if (!$this->request->isMethod('POST')) {
                throw new UserMessageException(t('Invalid request method.'));
            }
            if (!$this->token->validate(self::TOKEN)) {
                throw new UserMessageException($this->token->getErrorMessage());
            }
            $me = $this->app->make(User::class)->getUserInfoObject();
            $service = $this->app->make(TeamService::class)->asAdmin();
            $result = $callback($me, $service);
            [$message, $affected] = $result;
            $changedPool = $result[2] ?? null;
        } catch (UserMessageException $e) {
            if ($ajax) {
                return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());

            return $this->buildRedirect($this->getReturnPath());
        }

        if ($ajax) {
            // reload the affected teams, they may have been changed by captain succession etc.
            $teams = [];
            foreach ($affected as $team) {
                $fresh = $this->teams()->getByID($team->getID());
                $teams[] = $fresh ? $this->serializeTeam($fresh) : ['id' => $team->getID(), 'deleted' => true];
            }

            $response = ['success' => true, 'message' => $message, 'teams' => $teams];
            if ($changedPool instanceof TeamPool) {
                $response['singles'] = $this->serializeSingles($changedPool);
            }

            return new JsonResponse($response);
        }
        $this->flash('success', $message);

        return $this->buildRedirect($this->redirectTo ?: $this->getReturnPath());
    }

    /**
     * Forms post a "return" path so actions lead back to the list, board or team view they came from.
     */
    protected function getReturnPath(): string
    {
        $return = (string) $this->request->request->get('return');

        return strpos($return, self::PATH) === 0 ? $return : self::PATH;
    }

    protected function postedTeam(string $field = 'team'): Team
    {
        $team = $this->teams()->getByID((int) $this->request->request->get($field));
        if (!$team) {
            throw new UserMessageException(t('The team does not exist.'));
        }

        return $team;
    }

    protected function postedMember(Team $team, string $field = 'user'): TeamMember
    {
        $member = $team->getMember((int) $this->request->request->get($field));
        if (!$member) {
            throw new UserMessageException(t('This user is not a member of %s.', $team->getName()));
        }

        return $member;
    }

    protected function findUser(string $name): UserInfo
    {
        $name = trim($name);
        $userInfo = $name === '' ? null : $this->app->make(UserInfoRepository::class)->getByName($name);
        if (!$userInfo) {
            throw new UserMessageException(t('User "%s" not found.', $name));
        }

        return $userInfo;
    }

    /**
     * ?pool= filter of list and board: '' = all teams, 'none' = teams without pool, ID = teams of that pool.
     *
     * @return TeamPool|false|null
     */
    protected function getPoolFilter()
    {
        $value = (string) $this->request->query->get('pool');
        if ($value === 'none') {
            return false;
        }

        return $this->poolRepository()->getByID((int) $value);
    }

    protected function getPoolOptions(string $emptyLabel): array
    {
        $options = [0 => $emptyLabel];
        foreach ($this->poolRepository()->getAll() as $pool) {
            $options[$pool->getID()] = $pool->getName();
        }

        return $options;
    }

    protected function postedPool(): TeamPool
    {
        $pool = $this->poolRepository()->getByID((int) $this->request->request->get('pool'));
        if (!$pool) {
            throw new UserMessageException(t('The pool does not exist.'));
        }

        return $pool;
    }

    protected function postedSingle(): TeamPoolUser
    {
        $single = $this->poolRepository()->getSingle((int) $this->request->request->get('single'));
        if (!$single) {
            throw new UserMessageException(t('This player is not listed in the pool anymore.'));
        }

        return $single;
    }

    protected function serializeSingles(TeamPool $pool): array
    {
        $userInfos = $this->app->make(UserInfoRepository::class);
        $singles = [];
        foreach ($this->poolRepository()->getSingles($pool) as $single) {
            $ui = $userInfos->getByID($single->getUserID());
            if ($ui) {
                $singles[] = ['id' => $single->getID(), 'name' => $ui->getUserName(), 'note' => $single->getNote()];
            }
        }

        return $singles;
    }

    protected function serializeTeam(Team $team): array
    {
        return [
            'id' => $team->getID(),
            'name' => $team->getDisplayName(),
            'pool' => $team->getPool() ? $team->getPool()->getName() : null,
            // the board shows the free places as drop targets, 0 = unlimited
            'maxSize' => $this->app->make(TeamService::class)->getMaxTeamSize($team),
            'url' => (string) $this->app->make('url/manager')->resolve([self::PATH, 'team', $team->getID()]),
            'members' => array_map(function (TeamMember $member) {
                return [
                    'id' => $member->getUserID(),
                    'name' => $member->getUserName(),
                    'captain' => $member->isCaptain(),
                ];
            }, $team->getMembers()),
        ];
    }

    protected function getStats(): array
    {
        return [
            'teams' => $this->teams()->countAll(),
            'members' => $this->teams()->countMembers(),
            'empty' => $this->teams()->countEmpty(),
            'pools' => count($this->poolRepository()->getAll()),
            'singles' => $this->poolRepository()->countSingles(),
        ];
    }

    protected function setCommon(): void
    {
        $this->requireAsset('team_manager/user-search');
        $this->set('token', $this->token);
        $this->set('form', $this->app->make('helper/form'));
        $this->set('tokenAction', self::TOKEN);
        $this->set('basePath', self::PATH);
        $this->set('exportFormats', $this->app->make(TeamPoolExporter::class)->getFormats());
        $this->set('searchUsersURL', (string) $this->app->make('url/manager')->resolve([self::PATH, 'search_users']));
        $filterOptions = ['' => t('All teams'), 'none' => t('Teams without pool')];
        foreach ($this->poolRepository()->getAll() as $pool) {
            $filterOptions[$pool->getID()] = $pool->getName();
        }
        $this->set('poolFilterOptions', $filterOptions);
        $this->set('poolFilter', (string) $this->request->query->get('pool'));
    }

    protected function teams(): TeamRepository
    {
        return $this->app->make(TeamRepository::class);
    }

    protected function poolRepository(): TeamPoolRepository
    {
        return $this->app->make(TeamPoolRepository::class);
    }
}
