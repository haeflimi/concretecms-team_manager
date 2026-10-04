<?php
namespace TeamManager\Team;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\User\UserInfoRepository;
use TeamManager\Entity\Team;
use TeamManager\Entity\TeamPool;

/**
 * User autocomplete for all inputs where a user is entered (js/user-search.js).
 *
 * Searches all active users by username (admins also by email), ranks exact matches, then names starting with the
 * keywords, then names containing them. With a team or pool as context every result gets its state there:
 * users who can't be added (already in the team, in another team of the pool, ...) are disabled with the reason,
 * players looking for a team in the pool are suggested first.
 */
class UserSearch
{
    /** admin adds a member to the team */
    const MODE_MEMBER = 'member';
    /** captain invites a user to the team, open invitations / join requests block a new invitation */
    const MODE_INVITE = 'invite';
    /** admin adds a player looking for a team to the pool */
    const MODE_SINGLE = 'single';
    /** captain of a new team, optionally in a pool */
    const MODE_CAPTAIN = 'captain';

    const STATE_OK = 'ok';
    const STATE_SUGGESTED = 'suggested';
    const STATE_DISABLED = 'disabled';

    /** @var Connection */
    protected $db;
    /** @var UserInfoRepository */
    protected $userInfoRepository;
    /** @var TeamPoolRepository */
    protected $pools;
    /** @var TeamRequestRepository */
    protected $requests;

    public function __construct(Connection $db, UserInfoRepository $userInfoRepository, TeamPoolRepository $pools, TeamRequestRepository $requests)
    {
        $this->db = $db;
        $this->userInfoRepository = $userInfoRepository;
        $this->pools = $pools;
        $this->requests = $requests;
    }

    /**
     * @param Team|null $team team the user is added to (MODE_MEMBER, MODE_INVITE)
     * @param TeamPool|null $pool pool the user is added to, defaults to the team's pool
     * @param bool $withEmail search and return email addresses (dashboard only)
     *
     * @return array[] list of [id, name, email, avatar, state, hint]
     */
    public function search(string $keywords, string $mode, ?Team $team = null, ?TeamPool $pool = null, bool $withEmail = false, int $limit = 10): array
    {
        $keywords = trim($keywords);
        if ($keywords === '') {
            return [];
        }
        $pool = $pool ?: ($team ? $team->getPool() : null);
        $like = addcslashes($keywords, '%_\\');
        $where = 'uName like :contains' . ($withEmail ? ' or uEmail like :contains' : '');
        // more candidates than shown, the state may change the order
        $rows = $this->db->fetchAllAssociative(
            "select uID, uName, uEmail,
                case when uName = :exact then 0 when uName like :prefix then 1 when uName like :contains then 2 else 3 end as score
             from Users where uIsActive = 1 and ($where)
             order by score, char_length(uName), uName limit " . ($limit * 3),
            ['exact' => $keywords, 'prefix' => $like . '%', 'contains' => '%' . $like . '%']
        );

        $singles = [];
        if ($pool) {
            foreach ($this->pools->getSingles($pool) as $single) {
                $singles[$single->getUserID()] = true;
            }
        }

        $results = [];
        foreach ($rows as $row) {
            $uID = (int) $row['uID'];
            [$state, $hint] = $this->getState($uID, $mode, $team, $pool, isset($singles[$uID]));
            $userInfo = $this->userInfoRepository->getByID($uID);
            $avatar = $userInfo ? $userInfo->getUserAvatar() : null;
            $results[] = [
                'id' => $uID,
                'name' => $row['uName'],
                'email' => $withEmail ? $row['uEmail'] : null,
                'avatar' => $avatar ? (string) $avatar->getPath() : null,
                'state' => $state,
                'hint' => $hint,
                'score' => (int) $row['score'],
            ];
        }

        // exact match first, then suggested (looking for a team), possible, disabled; keeps the name ranking inside
        $weight = [self::STATE_SUGGESTED => 0, self::STATE_OK => 1, self::STATE_DISABLED => 2];
        usort($results, function (array $a, array $b) use ($weight) {
            return [$a['score'] > 0, $weight[$a['state']], $a['score']] <=> [$b['score'] > 0, $weight[$b['state']], $b['score']];
        });

        return array_map(function (array $result) {
            unset($result['score']);

            return $result;
        }, array_slice($results, 0, $limit));
    }

    /**
     * @return array [state, hint]
     */
    protected function getState(int $uID, string $mode, ?Team $team, ?TeamPool $pool, bool $isSingle): array
    {
        if ($team && $team->hasMember($uID)) {
            return [self::STATE_DISABLED, t('Already in this team')];
        }
        if ($pool) {
            $other = $this->pools->getTeamOfUser($pool, $uID, $team ? [$team->getID()] : []);
            if ($other) {
                return [self::STATE_DISABLED, t('In team %s', $other->getName())];
            }
        }
        if ($mode === self::MODE_INVITE && $team && $this->requests->findPending($team->getID(), $uID)) {
            return [self::STATE_DISABLED, t('Invitation or join request open')];
        }
        if ($isSingle) {
            return $mode === self::MODE_SINGLE
                ? [self::STATE_DISABLED, t('Already looking for a team')]
                : [self::STATE_SUGGESTED, t('Looking for a team')];
        }

        return [self::STATE_OK, null];
    }
}
