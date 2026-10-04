<?php
namespace TeamManager\Team\Export;

use TeamManager\Entity\Team;
use TeamManager\Entity\TeamPoolUser;

/**
 * A downloadable format of a pool's teams, e.g. for seeding a tournament platform.
 */
interface TeamPoolExportFormat
{
    /**
     * Used in the download URL.
     */
    public function getHandle(): string;

    public function getLabel(): string;

    /**
     * Short hint shown in the menu, e.g. the platforms the format is for.
     */
    public function getDescription(): string;

    public function getFileExtension(): string;

    public function getContentType(): string;

    /**
     * @param Team[] $teams in seed order
     * @param TeamPoolUser[] $singles free agents of the pool
     */
    public function render(array $teams, array $singles): string;
}
