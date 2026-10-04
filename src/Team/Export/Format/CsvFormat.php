<?php
namespace TeamManager\Team\Export\Format;

use TeamManager\Entity\Team;
use TeamManager\Entity\TeamMember;
use TeamManager\Team\Export\TeamPoolExportFormat;

/**
 * CSV helpers: UTF-8 with BOM, so Excel shows umlauts correctly, and members with the captains first.
 */
abstract class CsvFormat implements TeamPoolExportFormat
{
    public function getFileExtension(): string
    {
        return 'csv';
    }

    public function getContentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    /**
     * @param array[] $rows the first row is the header
     */
    protected function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * @return TeamMember[] captains first, then by join date
     */
    protected function getMembers(Team $team): array
    {
        $members = $team->getMembers();
        usort($members, function (TeamMember $a, TeamMember $b) {
            return ($b->isCaptain() <=> $a->isCaptain()) ?: ($a->getJoinedAt() <=> $b->getJoinedAt());
        });

        return $members;
    }

    protected function getEmail(TeamMember $member): string
    {
        $userInfo = $member->getUserInfo();

        return $userInfo ? (string) $userInfo->getUserEmail() : '';
    }
}
