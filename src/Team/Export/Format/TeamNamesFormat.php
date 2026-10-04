<?php
namespace TeamManager\Team\Export\Format;

use TeamManager\Entity\Team;
use TeamManager\Team\Export\TeamPoolExportFormat;

/**
 * One team name per line in seed order, for Challonge "Bulk Add", start.gg and bracket generators.
 * With captain email the lines are "Name, email": Challonge then invites the captain by email.
 */
class TeamNamesFormat implements TeamPoolExportFormat
{
    /** @var bool */
    protected $withCaptainEmail;

    public function __construct(bool $withCaptainEmail = false)
    {
        $this->withCaptainEmail = $withCaptainEmail;
    }

    public function getHandle(): string
    {
        return $this->withCaptainEmail ? 'names_email' : 'names';
    }

    public function getLabel(): string
    {
        return $this->withCaptainEmail ? t('Team names with captain email (.txt)') : t('Team names (.txt)');
    }

    public function getDescription(): string
    {
        return $this->withCaptainEmail
            ? t('Challonge Bulk Add, invites the captains by email')
            : t('Challonge Bulk Add, start.gg, bracket generators');
    }

    public function getFileExtension(): string
    {
        return 'txt';
    }

    public function getContentType(): string
    {
        return 'text/plain; charset=UTF-8';
    }

    public function render(array $teams, array $singles): string
    {
        $lines = array_map(function (Team $team) {
            // Challonge splits the line at the comma
            $line = trim(preg_replace('/\s*,\s*/', ' ', $team->getName()));
            if ($this->withCaptainEmail) {
                $captain = $team->getCaptains()[0] ?? null;
                $userInfo = $captain ? $captain->getUserInfo() : null;
                if ($userInfo) {
                    $line .= ', ' . $userInfo->getUserEmail();
                }
            }

            return $line;
        }, $teams);

        return implode("\n", $lines) . "\n";
    }
}
