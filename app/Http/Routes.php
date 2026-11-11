<?php

declare(strict_types=1);

namespace App\Http;

use App\App;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\DiagnosticApiController;
use App\Http\Controllers\Api\FinancialProfileApiController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OrganizationApiController;
use App\Http\Controllers\Api\PricingApiController;
use App\Http\Controllers\Api\RecurringApiController;
use App\Http\Controllers\Api\StrategyApiController;
use App\Http\Controllers\Api\ServicesApiController;
use App\Http\Controllers\Pages\AccountPagesController;
use App\Http\Controllers\Pages\AppPagesController;
use App\Http\Controllers\Pages\AuthPagesController;
use App\Http\Controllers\Pages\DiagnosticPagesController;
use App\Http\Controllers\Pages\PricingPagesController;
use App\Http\Controllers\Pages\RecurringPagesController;
use App\Http\Controllers\Pages\StrategyPagesController;
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
        $diagnostic = new DiagnosticApiController($app);
        $diagnosticPages = new DiagnosticPagesController($app);
        $pricing = new PricingApiController($app);
        $pricingPages = new PricingPagesController($app);
        $recurring = new RecurringApiController($app);
        $recurringPages = new RecurringPagesController($app);
        $strategy = new StrategyApiController($app);
        $strategyPages = new StrategyPagesController($app);
        $account = new AccountPagesController($app);
        $p = Route::ACCESS_PUBLIC;

        // Public
        $r->add('GET', '/', $public->home(...), $p);
        $r->add('GET', '/api/v1/health', (new HealthController())->show(...), $p);
        $r->add('GET', '/demo', $demo->show(...), $p, true, true);
        $r->add('POST', '/demo', $demo->start(...), $p);
        $r->add('GET', '/demo/expiree', $public->demoExpired(...), $p);
        $r->add('GET', '/methodologie', $public->methodology(...), $p);
        $r->add('GET', '/glossaire', $public->glossary(...), $p);
        $r->add('GET', '/mentions-legales', $public->legal(...), $p);
        $r->add('GET', '/confidentialite', $public->privacy(...), $p);
        $r->add('GET', '/compte/suppression/{token}', $account->deletionConfirmForm(...), $p, true, true);
        $r->add('POST', '/compte/suppression', $account->deletionConfirm(...), $p);
        $r->add('GET', '/compte/annulation/{token}', $account->cancelForm(...), $p, true, true);
        $r->add('POST', '/compte/annulation', $account->cancel(...), $p);
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
        $r->add('GET', '/app/glossaire', $pages->glossary(...), 'app.read');
        $r->add('GET', '/app/parametres', static fn (): Response => Response::redirect('/app/parametres/agence'), 'app.read');
        $r->add('GET', '/app/parametres/agence', $pages->settingsAgency(...), 'app.read');
        $r->add('GET', '/app/parametres/finances', $pages->settingsFinance(...), 'finance.read');
        $r->add('GET', '/app/parametres/prestations', $pages->settingsServices(...), 'app.read');
        $r->add('GET', '/app/parametres/compte', $account->account(...), 'app.read');
        $r->add('GET', '/app/parametres/securite', $account->security(...), 'app.read');
        $r->add('GET', '/app/parametres/donnees', $account->data(...), 'app.read');
        $r->add('POST', '/app/parametres/compte/mot-de-passe', $account->changePassword(...), 'app.read', false);
        $r->add('POST', '/app/parametres/securite/deconnexion-globale', $account->revokeSessions(...), 'app.read', false);
        $r->add('POST', '/app/parametres/donnees/export', $account->export(...), 'app.read', false);
        $r->add('POST', '/app/parametres/donnees/suppression', $account->requestDeletion(...), 'organization.write', false);
        $r->add('GET', '/app/diagnostic', $diagnosticPages->home(...), 'app.read');
        $r->add('GET', '/app/diagnostic/questionnaire', $diagnosticPages->questionnaire(...), 'app.read');
        $r->add('GET', '/app/diagnostic/resultats/{id}', $diagnosticPages->results(...), 'app.read');
        $r->add('GET', '/app/pricing', $pricingPages->index(...), 'app.read');
        $r->add('GET', '/app/pricing/{id}', $pricingPages->show(...), 'app.read');
        $r->add('GET', '/app/recurrent', $recurringPages->index(...), 'app.read');
        $r->add('GET', '/app/recurrent/formules/nouvelle', $recurringPages->newOffer(...), 'app.read');
        $r->add('GET', '/app/recurrent/formules/{id}', $recurringPages->offer(...), 'app.read');
        $r->add('GET', '/app/recurrent/scenarios/{id}', $recurringPages->scenario(...), 'app.read');
        $r->add('GET', '/app/strategie', $strategyPages->index(...), 'app.read');
        $r->add('GET', '/app/strategie/plan', $strategyPages->plan(...), 'app.read');

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
        $r->add('GET', '/api/v1/diagnostic/questionnaire', $diagnostic->questionnaire(...), 'app.read');
        $r->add('GET', '/api/v1/diagnostics', $diagnostic->index(...), 'app.read');
        $r->add('POST', '/api/v1/diagnostics', $diagnostic->store(...), 'diagnostic.write');
        $r->add('PUT', '/api/v1/diagnostics/{id}/answers', $diagnostic->answer(...), 'diagnostic.write');
        $r->add('POST', '/api/v1/diagnostics/{id}/complete', $diagnostic->complete(...), 'diagnostic.write');
        $r->add('GET', '/api/v1/diagnostics/{id}/results', $diagnostic->results(...), 'app.read');
        $r->add('DELETE', '/api/v1/diagnostics/{id}', $diagnostic->destroy(...), 'diagnostic.write');
        $r->add('POST', '/api/v1/pricing/calculate', $pricing->calculate(...), 'app.read');
        $r->add('GET', '/api/v1/pricing/templates/{category}', $pricing->template(...), 'app.read');
        $r->add('GET', '/api/v1/pricing/simulations', $pricing->index(...), 'app.read');
        $r->add('POST', '/api/v1/pricing/simulations', $pricing->store(...), 'pricing.write');
        $r->add('GET', '/api/v1/pricing/simulations/{id}', $pricing->show(...), 'app.read');
        $r->add('PATCH', '/api/v1/pricing/simulations/{id}', $pricing->update(...), 'pricing.write');
        $r->add('DELETE', '/api/v1/pricing/simulations/{id}', $pricing->destroy(...), 'pricing.write');
        $r->add('POST', '/api/v1/pricing/simulations/{id}/scenarios', $pricing->addScenario(...), 'pricing.write');
        $r->add('POST', '/api/v1/pricing/simulations/{id}/finalize', $pricing->finalize(...), 'pricing.write');
        $r->add('POST', '/api/v1/pricing/simulations/{id}/new-version', $pricing->newVersion(...), 'pricing.write');
        $r->add('POST', '/api/v1/pricing/simulations/{id}/archive', $pricing->archive(...), 'pricing.write');
        $r->add('PUT', '/api/v1/pricing/scenarios/{id}', $pricing->saveScenario(...), 'pricing.write');
        $r->add('DELETE', '/api/v1/pricing/scenarios/{id}', $pricing->deleteScenario(...), 'pricing.write');
        $r->add('POST', '/api/v1/recurring/calculate', $recurring->calculate(...), 'app.read');
        $r->add('GET', '/api/v1/recurring/summary', $recurring->summary(...), 'app.read');
        $r->add('GET', '/api/v1/recurring/offers', $recurring->offers(...), 'app.read');
        $r->add('POST', '/api/v1/recurring/offers', $recurring->storeOffer(...), 'recurring.write');
        $r->add('GET', '/api/v1/recurring/offers/{id}', $recurring->showOffer(...), 'app.read');
        $r->add('PATCH', '/api/v1/recurring/offers/{id}', $recurring->updateOffer(...), 'recurring.write');
        $r->add('PATCH', '/api/v1/recurring/offers/{id}/contracts', $recurring->contracts(...), 'recurring.write');
        $r->add('DELETE', '/api/v1/recurring/offers/{id}', $recurring->destroyOffer(...), 'recurring.write');
        $r->add('GET', '/api/v1/recurring/scenarios', $recurring->scenarios(...), 'app.read');
        $r->add('POST', '/api/v1/recurring/scenarios', $recurring->storeScenario(...), 'recurring.write');
        $r->add('GET', '/api/v1/recurring/scenarios/{id}', $recurring->showScenario(...), 'app.read');
        $r->add('GET', '/api/v1/recurring/scenarios/{id}/projection', $recurring->projection(...), 'app.read');
        $r->add('PUT', '/api/v1/recurring/scenarios/{id}', $recurring->updateScenario(...), 'recurring.write');
        $r->add('DELETE', '/api/v1/recurring/scenarios/{id}', $recurring->destroyScenario(...), 'recurring.write');
        $r->add('POST', '/api/v1/recurring/scenarios/{id}/finalize', $recurring->finalize(...), 'recurring.write');
        $r->add('POST', '/api/v1/recurring/scenarios/{id}/new-version', $recurring->newVersion(...), 'recurring.write');
        $r->add('POST', '/api/v1/recurring/scenarios/{id}/archive', $recurring->archive(...), 'recurring.write');
        $r->add('GET', '/api/v1/dashboard/history', (new DashboardApiController($app))->history(...), 'app.read');
        $r->add('GET', '/api/v1/recommendations', $strategy->index(...), 'app.read');
        $r->add('POST', '/api/v1/recommendations/evaluate', $strategy->evaluate(...), 'strategy.write');
        $r->add('POST', '/api/v1/recommendations/{id}/accept', $strategy->accept(...), 'strategy.write');
        $r->add('POST', '/api/v1/recommendations/{id}/dismiss', $strategy->dismiss(...), 'strategy.write');
        $r->add('POST', '/api/v1/recommendations/{id}/snooze', $strategy->snooze(...), 'strategy.write');
        $r->add('POST', '/api/v1/recommendations/{id}/reopen', $strategy->reopen(...), 'strategy.write');
        $r->add('GET', '/api/v1/action-plan', $strategy->plan(...), 'app.read');
        $r->add('POST', '/api/v1/action-items', $strategy->storeAction(...), 'strategy.write');
        $r->add('PATCH', '/api/v1/action-items/{id}', $strategy->updateAction(...), 'strategy.write');
        $r->add('DELETE', '/api/v1/action-items/{id}', $strategy->destroyAction(...), 'strategy.write');
        $r->add('POST', '/api/v1/action-items/{id}/tasks', $strategy->storeTask(...), 'strategy.write');
        $r->add('PATCH', '/api/v1/action-tasks/{id}', $strategy->updateTask(...), 'strategy.write');
        $r->add('DELETE', '/api/v1/action-tasks/{id}', $strategy->destroyTask(...), 'strategy.write');
        $r->add('POST', '/api/v1/financial-profile/recurring-share/accept-computed', $recurring->acceptComputedShare(...), 'finance.write');

        return $r;
    }
}
