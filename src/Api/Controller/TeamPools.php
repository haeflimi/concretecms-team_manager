<?php
namespace TeamManager\Api\Controller;

use Concrete\Core\Api\ApiController;
use League\Fractal\Resource\Collection;
use TeamManager\Api\Transformer\TeamPoolTransformer;
use TeamManager\Api\Transformer\TeamTransformer;
use TeamManager\Team\TeamPoolRepository;
use TeamManager\Team\TeamRepository;

/**
 * Read-only team endpoints, access is controlled by the "team_manager:read" scope (see routes/api.php).
 */
class TeamPools extends ApiController
{
    /**
     * @OA\Get(
     *     path="/ccm/api/1.0/team_pools",
     *     tags={"teams"},
     *     operationId="getTeamPools",
     *     summary="Returns all team pools with their settings and the number of teams, players and free agents, sorted by name.",
     *     security={
     *         {"authorization": {"team_manager:read"}},
     *         {"clientCredentials": {"team_manager:read"}}
     *     },
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/TeamPool")
     *         ),
     *     ),
     * )
     */
    public function listPools()
    {
        $pools = $this->app->make(TeamPoolRepository::class)->getAll();

        return new Collection($pools, $this->app->make(TeamPoolTransformer::class), 'team_pools');
    }

    /**
     * @OA\Get(
     *     path="/ccm/api/1.0/team_pools/{poolID}/teams",
     *     tags={"teams"},
     *     operationId="getTeamPoolTeams",
     *     summary="Returns the teams of a team pool with their members, sorted by name.",
     *     security={
     *         {"authorization": {"team_manager:read"}},
     *         {"clientCredentials": {"team_manager:read"}}
     *     },
     *     @OA\Parameter(
     *         name="poolID",
     *         in="path",
     *         description="ID of the team pool",
     *         required=true,
     *         @OA\Schema(
     *             type="integer",
     *             format="int64"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/Team")
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Team pool not found"
     *     ),
     * )
     */
    public function listTeams($poolID)
    {
        $pool = $this->app->make(TeamPoolRepository::class)->getByID((int) $poolID);
        if (!$pool) {
            return $this->error(t('Team pool not found.'), 404);
        }
        $teams = $this->app->make(TeamRepository::class)->findAll($pool);

        return new Collection($teams, new TeamTransformer(), 'teams');
    }
}
