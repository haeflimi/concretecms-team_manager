<?php
namespace Concrete\Package\TeamManager;

use Concrete\Core\Backup\ContentImporter;
use Concrete\Core\Block\BlockController;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Database\EntityManager\Provider\ProviderAggregateInterface;
use Concrete\Core\Database\EntityManager\Provider\StandardPackageProvider;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use TeamManager\Install\TeamInstaller;
use TeamManager\Listener\GroupListener;
use TeamManager\Team\TeamConfig;
use TeamManager\Team\TeamPoolRepository;
use TeamManager\Team\TeamRepository;
use TeamManager\Team\TeamRequestRepository;

class Controller extends Package implements ProviderAggregateInterface
{
    protected $pkgHandle = 'team_manager';
    protected $appVersionRequired = '9.4';
    protected $phpVersionRequired = '8.0';
    protected $pkgVersion = '3.2.0';
    protected $pkgAutoloaderRegistries = [
        'src' => '\TeamManager',
    ];

    public function getPackageName()
    {
        return t('Team Manager');
    }

    public function getPackageDescription()
    {
        return t('Teams with captains, invitations and join requests, organized in team pools (core groups usable for permissions).');
    }

    public function getEntityManagerProvider()
    {
        return new StandardPackageProvider($this->app, $this, [
            'src/Entity' => 'TeamManager\Entity',
        ]);
    }

    public function on_start()
    {
        // shared instances within a request
        foreach ([TeamConfig::class, TeamRepository::class, TeamPoolRepository::class, TeamRequestRepository::class] as $class) {
            $this->app->singleton($class);
        }

        // keep team data consistent when pool groups or users are removed outside of the team manager
        $director = $this->app->make('director');
        $listeners = [
            'on_group_delete' => 'onGroupDelete',
            'on_user_delete' => 'onUserDelete',
        ];
        foreach ($listeners as $event => $method) {
            $director->addListener($event, function ($e) use ($method) {
                $this->app->make(GroupListener::class)->$method($e);
            });
        }
    }

    public function install()
    {
        $pkg = parent::install();
        $this->installContent();

        return $pkg;
    }

    public function upgrade()
    {
        $installed = $this->getPackageEntity()->getPackageVersion();
        if (version_compare($installed, '3.0.0', '<')) {
            // 3.0 moved teams from core groups to own tables, the old team tables are not converted.
            // Drop them before the schema update, their rows would break the new foreign keys.
            $db = $this->app->make(Connection::class);
            foreach (['tmTeamRequest', 'tmTeamPoolUser', 'tmTeamPool', 'tmTeamProfile'] as $table) {
                $db->executeStatement('DROP TABLE IF EXISTS ' . $table);
            }
        }

        $this->removeLegacyBlockType();

        parent::upgrade();
        $this->installContent();
    }

    /**
     * 3.2 removed the unused legacy "team_manager" block type, delete it together with all remaining instances.
     */
    private function removeLegacyBlockType()
    {
        $blockType = BlockType::getByHandle('team_manager');
        if ($blockType) {
            // the block's controller is gone, core needs a controller class to delete instances:
            // stand in the plain block controller, btTeamManager is dropped below anyway
            $class = 'Concrete\Package\TeamManager\Block\TeamManager\Controller';
            if (!class_exists($class)) {
                class_alias(BlockController::class, $class);
            }

            // removes the instances on all page versions, stacks and scrapbook aliases, then the type itself
            $btID = (int) $blockType->getBlockTypeID();
            $blockType->delete();

            // sweep instances core does not reach (not placed on any page, e.g. in a pile)
            $db = $this->app->make(Connection::class);
            $bIDs = $db->fetchFirstColumn('SELECT bID FROM Blocks WHERE btID = ?', [$btID]);
            foreach ($bIDs as $bID) {
                foreach (['CollectionVersionBlocks', 'BlockPermissionAssignments', 'CollectionVersionBlockStyles', 'CollectionVersionBlocksCacheSettings', 'Blocks'] as $table) {
                    $db->executeStatement('DELETE FROM ' . $table . ' WHERE bID = ?', [$bID]);
                }
                $db->executeStatement("DELETE FROM PileContents WHERE itemType = 'BLOCK' AND itemID = ?", [$bID]);
            }
        }

        $this->app->make(Connection::class)->executeStatement('DROP TABLE IF EXISTS btTeamManager');
    }

    public function uninstall()
    {
        $this->app->make(TeamInstaller::class)->uninstall();
        parent::uninstall();
    }

    private function installContent()
    {
        $this->app->make(TeamInstaller::class)->install();

        $ci = new ContentImporter();
        $ci->importContentFile($this->getPackagePath() . '/install.xml');

        // the importer skips existing block types, refresh them so new db.xml columns are created
        foreach (['my_teams', 'team_directory'] as $handle) {
            $blockType = BlockType::getByHandle($handle);
            if ($blockType) {
                $blockType->refresh();
            }
        }

        $page = Page::getByPath('/dashboard/users/teams');
        if (!$page || $page->isError()) {
            $page = SinglePage::add('/dashboard/users/teams', $this->getPackageEntity());
            $page->update(['cName' => t('Teams'), 'cDescription' => t('Manage teams and their members.')]);
        }
    }
}
