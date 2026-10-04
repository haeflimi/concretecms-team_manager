<?php
namespace TeamManager\Api\Transformer;

use League\Fractal\TransformerAbstract;
use TeamManager\Entity\TeamPool;
use TeamManager\Team\TeamConfig;
use TeamManager\Team\TeamPoolRepository;

/**
 * @OA\Schema(
 *     schema="TeamPool",
 *     title="Team pool",
 *     @OA\Property(property="id", type="integer", description="ID of the pool, it's the ID of the pool's user group"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="description", type="string"),
 *     @OA\Property(property="team_count", type="integer", description="Teams in the pool"),
 *     @OA\Property(property="player_count", type="integer", description="Users playing in a team of the pool"),
 *     @OA\Property(property="free_agent_count", type="integer", description="Users looking for a team in the pool"),
 *     @OA\Property(
 *         property="settings",
 *         type="object",
 *         @OA\Property(property="open", type="boolean", description="Users and captains may join"),
 *         @OA\Property(property="allow_teams", type="boolean", description="Captains may register their teams"),
 *         @OA\Property(property="allow_team_creation", type="boolean", description="Users may create new teams in the pool"),
 *         @OA\Property(property="allow_free_agents", type="boolean", description="Users may join as free agent"),
 *         @OA\Property(property="max_teams", type="integer", description="0 = unlimited"),
 *         @OA\Property(property="max_team_size", type="integer", description="Members per team, the pool's limit or the global one, 0 = unlimited")
 *     ),
 *     @OA\Property(property="date_created", type="string", format="date-time")
 * )
 */
class TeamPoolTransformer extends TransformerAbstract
{
    /** @var TeamPoolRepository */
    protected $pools;
    /** @var TeamConfig */
    protected $config;

    public function __construct(TeamPoolRepository $pools, TeamConfig $config)
    {
        $this->pools = $pools;
        $this->config = $config;
    }

    public function transform(TeamPool $pool): array
    {
        $teams = $pool->getTeams();
        $players = 0;
        foreach ($teams as $team) {
            $players += $team->getMemberCount();
        }

        return [
            'id' => $pool->getID(),
            'name' => $pool->getName(),
            'description' => $pool->getDescription(),
            'team_count' => $this->pools->countTeams($pool),
            'player_count' => $players,
            'free_agent_count' => count($this->pools->getSingles($pool)),
            'settings' => [
                'open' => $pool->isOpen(),
                'allow_teams' => $pool->allowsTeams(),
                'allow_team_creation' => $pool->allowsTeamCreation(),
                'allow_free_agents' => $pool->allowsSingles(),
                'max_teams' => $pool->getMaxTeams(),
                'max_team_size' => $pool->getMaxTeamSize() ?: $this->config->getMaxTeamSize(),
            ],
            'date_created' => $pool->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
