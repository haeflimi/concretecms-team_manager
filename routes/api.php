<?php

defined('C5_EXECUTE') or die('Access Denied.');

use TeamManager\Api\ApiIntegration;

/**
 * Team endpoints of the REST API, loaded by TeamManager\Api\ApiIntegration with the /ccm/api/1.0 prefix.
 *
 * @var Concrete\Core\Routing\Router $router
 */

$router->get('/team_pools', '\TeamManager\Api\Controller\TeamPools::listPools')
    ->setScopes(ApiIntegration::SCOPE)
;

$router->get('/team_pools/{poolID}/teams', '\TeamManager\Api\Controller\TeamPools::listTeams')
    ->setRequirement('poolID', '[0-9]+')
    ->setScopes(ApiIntegration::SCOPE)
;
