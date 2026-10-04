<?php
namespace TeamManager\Team\Export\Format;

use Concrete\Core\User\UserInfoRepository;
use TeamManager\Entity\Team;
use TeamManager\Entity\TeamPoolUser;

/**
 * Everything in one sheet: a row per team member, then the free agents with empty team columns.
 * For Tournify (paste from Excel), spreadsheets and other platforms.
 */
class RosterCsvFormat extends CsvFormat
{
    /** @var UserInfoRepository */
    protected $userInfoRepository;

    public function __construct(UserInfoRepository $userInfoRepository)
    {
        $this->userInfoRepository = $userInfoRepository;
    }

    public function getHandle(): string
    {
        return 'roster';
    }

    public function getLabel(): string
    {
        return t('Roster CSV');
    }

    public function getDescription(): string
    {
        return t('Teams, members and free agents for Tournify, Excel and others');
    }

    public function render(array $teams, array $singles): string
    {
        $rows = [['team_id', 'team_name', 'tag', 'seed', 'player_id', 'username', 'email', 'captain', 'free_agent', 'date_joined']];
        foreach (array_values($teams) as $i => $team) {
            /** @var Team $team */
            foreach ($this->getMembers($team) as $member) {
                $rows[] = [
                    $team->getID(),
                    $team->getName(),
                    (string) $team->getTag(),
                    $i + 1,
                    $member->getUserID(),
                    $member->getUserName(),
                    $this->getEmail($member),
                    $member->isCaptain() ? 1 : 0,
                    0,
                    $member->getJoinedAt()->format('Y-m-d H:i'),
                ];
            }
        }
        foreach ($singles as $single) {
            /** @var TeamPoolUser $single */
            $userInfo = $this->userInfoRepository->getByID($single->getUserID());
            $rows[] = [
                '', '', '', '',
                $single->getUserID(),
                $userInfo ? $userInfo->getUserName() : '',
                $userInfo ? (string) $userInfo->getUserEmail() : '',
                0,
                1,
                $single->getJoinedAt()->format('Y-m-d H:i'),
            ];
        }

        return $this->toCsv($rows);
    }
}
