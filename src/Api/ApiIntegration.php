<?php
namespace TeamManager\Api;

use Concrete\Core\Api\Events\GenerateApiSpecEvent;
use Concrete\Core\Api\OpenApi\SourceRegistry;
use Concrete\Core\Application\Application;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Middleware\ApiLoggerMiddleware;
use Concrete\Core\Http\Middleware\FractalNegotiatorMiddleware;
use Concrete\Core\Http\Middleware\OAuthAuthenticationMiddleware;
use Concrete\Core\Http\Middleware\OAuthErrorMiddleware;
use Concrete\Core\Routing\Router;

/**
 * Adds the team endpoints (routes/api.php) to the Concrete REST API, guarded by the "team_manager:read" scope.
 *
 * The scope is stored in OAuth2Scope on install and added to the OpenAPI spec, so the core scope synchronization
 * (SynchronizeScopesCommand, which deletes scopes missing from the spec) keeps it and the API documentation lists
 * the endpoints.
 */
class ApiIntegration
{
    const SCOPE = 'team_manager:read';

    /** @var Application */
    protected $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public static function getScopeDescription(): string
    {
        return t('Read team pools, teams and their members');
    }

    /**
     * Called from the package's on_start.
     */
    public function register(): void
    {
        // the annotated controllers and transformers of the package become part of the API spec / documentation
        $this->app->make(SourceRegistry::class)->addSource(__DIR__);
        $this->app->make('director')->addListener('on_api_spec_generate', function (GenerateApiSpecEvent $event) {
            $this->addScopeToSpec($event);
        });

        $config = $this->app->make('config');
        if (!$config->get('concrete.api.enabled')) {
            return;
        }
        // same prefix and middleware as the core API routes (ApiRouteList)
        $group = $this->app->make(Router::class)->buildGroup()
            ->setPrefix('/ccm/api/1.0')
            ->addMiddleware(OAuthErrorMiddleware::class)
            ->addMiddleware(OAuthAuthenticationMiddleware::class)
            ->addMiddleware(FractalNegotiatorMiddleware::class);
        if ($config->get('concrete.log.api')) {
            $group->addMiddleware(ApiLoggerMiddleware::class, 9);
        }
        $group->routes('api.php', 'team_manager');
    }

    /**
     * Stores the scope so it can be granted to integrations (Dashboard › System › API › Integrations).
     */
    public function installScope(): void
    {
        $db = $this->app->make(Connection::class);
        if (!$db->fetchOne('select identifier from OAuth2Scope where identifier = ?', [self::SCOPE])) {
            $db->insert('OAuth2Scope', ['identifier' => self::SCOPE, 'description' => self::getScopeDescription()]);
        }
    }

    public function uninstallScope(): void
    {
        $db = $this->app->make(Connection::class);
        $db->delete('OAuth2ClientScopes', ['scopeIdentifier' => self::SCOPE]);
        $db->delete('OAuth2Scope', ['identifier' => self::SCOPE]);
    }

    /**
     * Both security schemes (client credentials and authorization code) get the scope.
     */
    protected function addScopeToSpec(GenerateApiSpecEvent $event): void
    {
        $components = $event->getOpenApi()->components;
        if (!is_object($components) || !is_array($components->securitySchemes)) {
            return;
        }
        foreach ($components->securitySchemes as $scheme) {
            if (!is_array($scheme->flows)) {
                continue;
            }
            foreach ($scheme->flows as $flow) {
                $scopes = is_array($flow->scopes) ? $flow->scopes : [];
                $scopes[self::SCOPE] = self::getScopeDescription();
                $flow->scopes = $scopes;
            }
        }
    }
}
