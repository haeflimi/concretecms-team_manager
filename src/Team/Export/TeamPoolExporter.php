<?php
namespace TeamManager\Team\Export;

use Concrete\Core\Application\Application;
use Concrete\Core\Error\UserMessageException;
use Symfony\Component\HttpFoundation\Response;
use TeamManager\Entity\TeamPool;
use TeamManager\Team\Export\Format\BattlefyCsvFormat;
use TeamManager\Team\Export\Format\RosterCsvFormat;
use TeamManager\Team\Export\Format\TeamNamesFormat;
use TeamManager\Team\TeamPoolRepository;
use TeamManager\Team\TeamRepository;

/**
 * Downloads of a pool's teams in formats tournament platforms can import (see README "Export").
 */
class TeamPoolExporter
{
    /** @var Application */
    protected $app;
    /** @var TeamRepository */
    protected $teams;
    /** @var TeamPoolRepository */
    protected $pools;

    public function __construct(Application $app, TeamRepository $teams, TeamPoolRepository $pools)
    {
        $this->app = $app;
        $this->teams = $teams;
        $this->pools = $pools;
    }

    /**
     * @return TeamPoolExportFormat[] by handle
     */
    public function getFormats(): array
    {
        $formats = [];
        foreach ([
            new TeamNamesFormat(),
            new TeamNamesFormat(true),
            $this->app->make(BattlefyCsvFormat::class),
            $this->app->make(RosterCsvFormat::class),
        ] as $format) {
            $formats[$format->getHandle()] = $format;
        }

        return $formats;
    }

    /**
     * The teams are in seed order: by name, like in the dashboard.
     */
    public function render(TeamPool $pool, string $handle): string
    {
        return $this->getFormat($handle)->render($this->teams->findAll($pool), $this->pools->getSingles($pool));
    }

    public function download(TeamPool $pool, string $handle): Response
    {
        $format = $this->getFormat($handle);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($this->app->make('helper/text')->asciify($pool->getName()))), '-') ?: 'pool';
        $fileName = sprintf('%s-%s-%s.%s', $slug, $format->getHandle(), date('Y-m-d'), $format->getFileExtension());

        return new Response($this->render($pool, $handle), 200, [
            'Content-Type' => $format->getContentType(),
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    protected function getFormat(string $handle): TeamPoolExportFormat
    {
        $formats = $this->getFormats();
        if (!isset($formats[$handle])) {
            throw new UserMessageException(t('Unknown export format.'));
        }

        return $formats[$handle];
    }
}
