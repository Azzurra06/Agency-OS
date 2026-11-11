<?php

declare(strict_types=1);

namespace App\Http;

use App\App;
use App\Http\Controllers\Api\FinancialProfileApiController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OrganizationApiController;
use App\Http\Controllers\Api\ServicesApiController;
use App\Http\Controllers\Pages\AppPagesController;
use App\Http\Controllers\Pages\AuthPagesController;
use App\Http\Controllers\Pages\DemoPagesController;
use App\Http\Controllers\Pages\PublicPagesController;

/**
 * Table des routes. Chaque route déclare explicitement son accès (« public » ou une permission).
 * Arguments : méthode, chemin, action, accès, autorisée en démo (défaut oui), ouvre une session (pages publiques à formulaire).
 */
final class Routes
{
    public static function build(App $app): Router
    {
        $r = new Router();
        $public = new PublicPagesController($app);
        $auth = new AuthPagesController($app);
        $demo = new DemoPagesController($app);
        $pages = new AppPagesController($app);
        $organization = new OrganizationApiController($app);
        $finance = new FinancialProfileApiController($app);
        $services = new ServicesApiController($app);
        $p = Route::ACCESS_PUBLIC;

        // Public
        $r->add('GET', '/', $public->home(...), $p);
        $r->add('GET', '/api/v1/health', (new HealthController())->show(...), $p);
        $r->add('GET', '/demo', $demo->show(...), $p, true, true);
        $r->add('POST', '/demo', $demo->start(...), $p);
        $r->add('GET', '/demo/expiree', $public->demoExpired(...), $p);
        $r->add('GET', '/connexion', $auth->loginForm(...), $p, true, true);
        $r->add('POST', '/connexion', $auth->login(...), $p);
        $r->add('GET', '/inscription', $auth->registerInfo(...), $p);
        $r->add('GET', '/mot-de-passe-oublie', $auth->forgotForm(...), $p, true, true);
        $r->add('POST', '/mot-de-passe-oublie', $auth->forgot(...), $p);
        $r->add('GET', '/reinitialisation/{token}', $auth->resetExchange(...), $p, true, true);
        $r->add('GET', '/reinitialisation', $auth->resetForm(...), $p, true, true);
        $r->add('POST', '/reinitialisation', $auth->reset(...), $p);
        $r->add('GET', '/verification/{token}', $auth->verify(...), $p, true, true);

        // Application (authentifiée)
        $r->add('POST', '/deconnexion', $auth->logout(...), 'app.read');
        $r->add('POST', '/demo/reinitialiser', $demo->reset(...), 'app.read');
        $r->add('POST', '/demo/quitter', $demo->quit(...), 'app.read');
        $r->add('GET', '/app', $pages->dashboard(...), 'app.read');
        $r->add('GET', '/app/bienvenue', $pages->onboarding(...), 'app.read');
        $r->add('GET', '/app/parametres', static fn (): Response => Response::redirect('/app/parametres/agence'), 'app.read');
        $r->add('GET', '/app/parametres/agence', $pages->settingsAgency(...), 'app.read');
        $r->add('GET', '/app/parametres/finances', $pages->settingsFinance(...), 'finance.read');
        $r->add('GET', '/app/parametres/prestations', $pages->settingsServices(...), 'app.read');
        foreach (['diagnostic', 'pricing', 'recurrent', 'strategie'] as $module) {
            $r->add('GET', '/app/' . $module, $pages->module(...), 'app.read');
        }

        // API
        $r->add('GET', '/api/v1/organization', $organization->show(...), 'app.read');
        $r->add('PATCH', '/api/v1/organization', $organization->update(...), 'organization.write');
        $r->add('PATCH', '/api/v1/organization/onboarding', $organization->onboarding(...), 'organization.write');
        $r->add('GET', '/api/v1/financial-profile', $finance->show(...), 'finance.read');
        $r->add('PUT', '/api/v1/financial-profile', $finance->update(...), 'finance.write');
        $r->add('GET', '/api/v1/financial-profile/derived', $finance->derived(...), 'finance.read');
        $r->add('GET', '/api/v1/financial-profile/changes', $finance->changes(...), 'finance.read');
        $r->add('GET', '/api/v1/services', $services->index(...), 'app.read');
        $r->add('POST', '/api/v1/services', $services->store(...), 'organization.write');
        $r->add('PATCH', '/api/v1/services/{id}', $services->update(...), 'organization.write');
        $r->add('DELETE', '/api/v1/services/{id}', $services->destroy(...), 'organization.write');

        return $r;
    }
}
