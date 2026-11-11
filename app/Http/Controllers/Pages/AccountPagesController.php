<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Paramètres Compte, Sécurité et Données (docs/07 §4.8, docs/04 §7.4 et D8). Formulaires HTML classiques protégés
 * par CSRF ; toute action sensible redemande le mot de passe. En démonstration : « Non disponible en démonstration ».
 */
final class AccountPagesController extends Controller
{
    public function account(Request $request): Response
    {
        return $this->settings($request, 'app/settings-account', 'compte', 'Paramètres · Compte');
    }

    public function security(Request $request): Response
    {
        $auth = $this->auth($request);

        return $this->settings($request, 'app/settings-security', 'securite', 'Paramètres · Sécurité', [
            'logins' => $auth->isDemo ? [] : $this->app->account()->recentLogins($auth->userId),
        ]);
    }

    public function data(Request $request): Response
    {
        return $this->settings($request, 'app/settings-data', 'donnees', 'Paramètres · Données');
    }

    public function changePassword(Request $request): Response
    {
        $auth = $this->auth($request);
        $new = self::field($request, 'new_password');
        if ($new !== self::field($request, 'new_password_confirmation')) {
            $this->flash('error', 'Les deux nouveaux mots de passe ne correspondent pas.');

            return Response::redirect('/app/parametres/compte');
        }
        $result = $this->app->account()->changePassword($auth->userId, self::field($request, 'current_password'), $new, $request->clientIp);
        $this->flash($result === 'ok' ? 'success' : 'error', match ($result) {
            'ok' => 'Mot de passe modifié. Vos autres sessions ont été fermées.',
            'invalid_password' => 'Mot de passe actuel incorrect.',
            default => 'Le nouveau mot de passe ne respecte pas les règles : au moins 12 caractères.',
        });

        return Response::redirect('/app/parametres/compte');
    }

    public function revokeSessions(Request $request): Response
    {
        $auth = $this->auth($request);
        $this->app->auth()->revokeAllSessions($auth->userId);
        $this->app->auth()->logout($auth->userId, $auth->organizationId, $request->clientIp);

        return Response::redirect('/connexion');
    }

    /** Export : mot de passe redemandé, document généré à la volée (jamais écrit sur le disque), téléchargé en pièce jointe. */
    public function export(Request $request): Response
    {
        $auth = $this->auth($request);
        $result = $this->app->account()->export($auth->userId, $this->org($request), self::field($request, 'password'), $request->clientIp);
        if ($result['status'] !== 'ok') {
            $this->flash('error', $result['status'] === 'rate_limited' ? 'Limite atteinte : 5 exports par heure. Réessayez plus tard.' : 'Mot de passe incorrect.');

            return Response::redirect('/app/parametres/donnees');
        }
        $filename = 'agency-os-export-' . $this->app->clock()->nowUtc()->format('Y-m-d') . '.json';

        return new Response(200, $result['body'], [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function requestDeletion(Request $request): Response
    {
        $auth = $this->auth($request);
        if (self::field($request, 'confirm') !== 'SUPPRIMER') {
            $this->flash('error', 'Saisissez SUPPRIMER pour confirmer.');

            return Response::redirect('/app/parametres/donnees');
        }
        $ok = $this->app->account()->requestDeletion($auth->userId, self::field($request, 'password'), $request->clientIp);
        $this->flash($ok ? 'info' : 'error', $ok
            ? 'Un lien de confirmation (valable 1 heure) vient d\'être envoyé à votre adresse email. Le compte ne sera désactivé qu\'après confirmation.'
            : 'Mot de passe incorrect.');

        return Response::redirect('/app/parametres/donnees');
    }

    /** Lien reçu par email : page de confirmation (GET sans effet), l'action est un POST protégé par CSRF. */
    public function deletionConfirmForm(Request $request): Response
    {
        $token = $request->param('token');

        return $this->page('account/token-action', [
            'title' => 'Supprimer le compte', 'valid' => $this->app->account()->tokenIsValid($token, 'delete_confirm'), 'token' => $token,
            'action' => '/compte/suppression', 'button' => 'Confirmer la suppression', 'danger' => true,
            'text' => 'Votre compte sera désactivé immédiatement, puis toutes ses données seront supprimées définitivement après 7 jours. Vous pourrez annuler pendant ce délai en vous reconnectant.',
        ], 'layouts/minimal')->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function deletionConfirm(Request $request): Response
    {
        $purge = $this->app->account()->confirmDeletion(self::field($request, 'token'), $request->clientIp);
        $_SESSION = [];

        return $this->page('account/token-done', [
            'title' => 'Compte désactivé',
            'ok' => $purge !== null,
            'message' => $purge === null
                ? 'Ce lien est invalide ou a expiré.'
                : 'Votre compte est désactivé. Ses données seront supprimées définitivement le ' . date('d/m/Y', (int) strtotime($purge)) . '. Pour annuler, connectez-vous avant cette date : un lien d\'annulation vous sera envoyé.',
        ], 'layouts/minimal');
    }

    public function cancelForm(Request $request): Response
    {
        $token = $request->param('token');

        return $this->page('account/token-action', [
            'title' => 'Annuler la suppression', 'valid' => $this->app->account()->tokenIsValid($token, 'delete_cancel'), 'token' => $token,
            'action' => '/compte/annulation', 'button' => 'Réactiver mon compte', 'danger' => false,
            'text' => 'Votre compte sera réactivé et ses données conservées. Vous pourrez ensuite vous reconnecter normalement.',
        ], 'layouts/minimal')->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function cancel(Request $request): Response
    {
        $ok = $this->app->account()->cancelDeletion(self::field($request, 'token'), $request->clientIp);

        return $this->page('account/token-done', [
            'title' => 'Suppression annulée', 'ok' => $ok,
            'message' => $ok ? 'La suppression est annulée et votre compte est réactivé. Vous pouvez vous connecter.' : 'Ce lien est invalide ou a expiré.',
        ], 'layouts/minimal');
    }

    /** @param array<string, mixed> $data */
    private function settings(Request $request, string $template, string $tab, string $title, array $data = []): Response
    {
        $auth = $this->auth($request);

        return $this->screen($request, $template, 'parametres', $title, $data + [
            'tab' => $tab,
            'account' => $auth->isDemo ? null : $this->app->account()->profile($auth->userId),
            'flash' => $this->pullFlash(),
        ]);
    }

    private static function field(Request $request, string $name): string
    {
        $value = $request->form[$name] ?? null;

        return is_string($value) ? $value : '';
    }
}
