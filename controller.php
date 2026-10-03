<?php
namespace Concrete\Package\TeamManager;

use Concrete\Core\Backup\ContentImporter;
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
    protected $pkgVersion = '3.1.0';
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

        parent::upgrade();
        // the legacy "team_manager" block type is kept on purpose: application/blocks/team_manager overrides it
        // with the TFTS team UI, deleting it would remove those blocks from all pages.
        $this->installContent();
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
