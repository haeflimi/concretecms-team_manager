<?php

namespace TeamManager\Controller;

use Concrete\Core\Controller\Controller;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\User;
use Concrete\Core\Validation\CSRF\Token;
use Symfony\Component\HttpFoundation\JsonResponse;
use TeamManager\Team\TeamPoolPages;
use TeamManager\Team\TeamPoolRepository;
use TeamManager\Team\TeamService;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * One-click free agent sign-up for cards outside the Team Directory (e.g. the tournament cards of the theme):
 *   POST /ccm/team_manager/pool/{gID}/join   (token team_pool_join)
 *   POST /ccm/team_manager/pool/{gID}/leave  (token team_pool_leave)
 * Answers the pool's fresh status (TeamPoolPages::status) or {error}. The rules are TeamService's.
 */
class PoolApi extends Controller
{
    public const TOKEN_JOIN = 'team_pool_join';
    public const TOKEN_LEAVE = 'team_pool_leave';

    public function join($gID = null): JsonResponse
    {
        return $this->act((int) $gID, self::TOKEN_JOIN, function ($pool, $user, TeamService $teams) {
            $teams->joinPool($pool, $user->getUserInfoObject());
        });
    }

    public function leave($gID = null): JsonResponse
    {
        return $this->act((int) $gID, self::TOKEN_LEAVE, function ($pool, $user, TeamService $teams) {
            $single = $this->app->make(TeamPoolRepository::class)->findSingle($pool, (int) $user->getUserID());
            if ($single === null) {
                throw new UserMessageException(t('You are not looking for a team in %s.', $pool->getName()));
            }
            $teams->leavePool($single, $user->getUserInfoObject());
        });
    }

    protected function act(int $gID, string $tokenAction, callable $action): JsonResponse
    {
        $user = $this->app->make(User::class);
        if (!$user->isRegistered()) {
            return new JsonResponse(['error' => t('Please sign in first.')], 401);
        }
        if (!$this->app->make(Token::class)->validate($tokenAction, (string) $this->request->request->get('ccm_token'))) {
            return new JsonResponse(['error' => t('The form has expired, please reload the page.')], 403);
        }
        $pool = $this->app->make(TeamPoolRepository::class)->getByID($gID);
        if ($pool === null) {
            return new JsonResponse(['error' => t('This team pool does not exist (any more).')], 404);
        }
        try {
            $action($pool, $user, $this->app->make(TeamService::class));
        } catch (UserMessageException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse($this->app->make(TeamPoolPages::class)->status($pool, $user));
    }
}
