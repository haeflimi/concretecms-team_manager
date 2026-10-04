<?php
namespace TeamManager\Team\Export\Format;

use TeamManager\Entity\Team;

/**
 * Battlefy team import: "teamName,player,email", one row per member, captain first.
 */
class BattlefyCsvFormat extends CsvFormat
{
    public function getHandle(): string
    {
        return 'battlefy';
    }

    public function getLabel(): string
    {
        return t('Battlefy CSV');
    }

    public function getDescription(): string
    {
        return t('Battlefy team import with rosters');
    }

    public function render(array $teams, array $singles): string
    {
        $rows = [['teamName', 'player', 'email']];
        foreach ($teams as $team) {
            /** @var Team $team */
            foreach ($this->getMembers($team) as $member) {
                $rows[] = [$team->getName(), $member->getUserName(), $this->getEmail($member)];
            }
        }

        return $this->toCsv($rows);
    }
}
