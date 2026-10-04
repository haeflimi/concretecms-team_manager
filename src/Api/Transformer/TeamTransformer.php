<?php
namespace TeamManager\Api\Transformer;

use League\Fractal\TransformerAbstract;
use TeamManager\Entity\Team;
use TeamManager\Entity\TeamMember;

/**
 * @OA\Schema(
 *     schema="Team",
 *     title="Team",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="tag", type="string", nullable=true),
 *     @OA\Property(property="description", type="string"),
 *     @OA\Property(property="logo_url", type="string", nullable=true),
 *     @OA\Property(property="pool_id", type="integer", nullable=true),
 *     @OA\Property(property="member_count", type="integer"),
 *     @OA\Property(
 *         property="members",
 *         type="array",
 *         @OA\Items(
 *             type="object",
 *             @OA\Property(property="user_id", type="integer"),
 *             @OA\Property(property="username", type="string"),
 *             @OA\Property(property="captain", type="boolean"),
 *             @OA\Property(property="date_joined", type="string", format="date-time")
 *         )
 *     ),
 *     @OA\Property(property="date_created", type="string", format="date-time")
 * )
 */
class TeamTransformer extends TransformerAbstract
{
    public function transform(Team $team): array
    {
        $logo = $team->getLogo();
        $logoVersion = $logo ? $logo->getApprovedVersion() : null;

        return [
            'id' => $team->getID(),
            'name' => $team->getName(),
            'tag' => $team->getTag(),
            'description' => $team->getDescription(),
            'logo_url' => $logoVersion ? (string) $logoVersion->getURL() : null,
            'pool_id' => $team->getPool() ? $team->getPool()->getID() : null,
            'member_count' => $team->getMemberCount(),
            'members' => array_map(function (TeamMember $member) {
                return [
                    'user_id' => $member->getUserID(),
                    'username' => $member->getUserName(),
                    'captain' => $member->isCaptain(),
                    'date_joined' => $member->getJoinedAt()->format(DATE_ATOM),
                ];
            }, $team->getMembers()),
            'date_created' => $team->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
