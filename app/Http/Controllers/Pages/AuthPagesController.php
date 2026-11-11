<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Support\TypedArray;

/**
 * Pages d'authentification (formulaires HTML classiques, utilisables sans JavaScript, protégés par CSRF).
 * Messages toujours génériques ; jetons de réinitialisation retirés de l'URL dès la première visite.
 */
final class AuthPagesController extends Controller
{
    public function loginForm(Request $request): Response
    {
        if (isset($_SESSION['user_id'])) {
            return Response::redirect('/app');
        }

        return $this->page('auth/login', ['title' => 'Connexion', 'flash' => $this->pullFlash(), 'isPortfolio' => $this->app->config->isPortfolio()], 'layouts/minimal');
    }

    public function login(Request $request): Response
    {
        $email = is_string($request->form['email'] ?? null) ? $request->form['email'] : '';
        $password = is_string($request->form['password'] ?? null) ? $request->form['password'] : '';
        $result = $this->app->auth()->attempt($email, $password, $request->clientIp, $request->header('user-agent'));
        if ($result === 'ok') {
            return Response::redirect('/app');
        }
        if ($result === 'deletion_scheduled') {
            // Compte en cours de suppression (D8) : l'annulation exige le mot de passe ET un lien envoyé par email.
            $this->app->account()->sendCancellationLink($email);
            $this->flash('info', 'Ce compte est en cours de suppression et désactivé. Un lien pour annuler la suppression vient d\'être envoyé à son adresse email.');

            return Response::redirect('/connexion');
        }
        $this->flash('error', $result === 'locked'
            ? 'Trop de tentatives. Réessayez dans quelques minutes.'
            : 'Identifiants invalides.');

        return Response::redirect('/connexion');
    }

    public function logout(Request $request): Response
    {
        $session = $_SESSION ?? [];
        $this->app->auth()->logout(
            isset($session['user_id']) ? TypedArray::int($session, 'user_id') : null,
            isset($session['organization_id']) ? TypedArray::int($session, 'organization_id') : null,
            $request->clientIp,
        );

        return Response::redirect('/');
    }

    public function registerInfo(Request $request): Response
    {
        return $this->page('auth/register-disabled', ['title' => 'Inscription'], 'layouts/minimal');
    }

    public function forgotForm(Request $request): Response
    {
        return $this->page('auth/forgot', ['title' => 'Mot de passe oublié', 'flash' => $this->pullFlash()], 'layouts/minimal');
    }

    public function forgot(Request $request): Response
    {
        $email = is_string($request->form['email'] ?? null) ? $request->form['email'] : '';
        if (filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false) {
            $this->app->auth()->requestPasswordReset($email, $request->clientIp);
        }
        // Réponse identique, que l'adresse existe ou non.
        $this->flash('info', 'Si un compte correspond à cette adresse, un lien de réinitialisation vient d\'être envoyé. Il est valable 30 minutes.');

        return Response::redirect('/mot-de-passe-oublie');
    }

    /** Échange le jeton contre un état de session, puis redirige vers une URL sans jeton. */
    public function resetExchange(Request $request): Response
    {
        $token = $request->param('token');
        if ($this->app->auth()->resetTokenUser($token) === null) {
            $this->flash('error', 'Ce lien est invalide ou a expiré. Faites une nouvelle demande.');

            return Response::redirect('/mot-de-passe-oublie')->withHeader('Referrer-Policy', 'no-referrer');
        }
        $_SESSION['reset_token'] = $token;

        return Response::redirect('/reinitialisation')->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function resetForm(Request $request): Response
    {
        if (!is_string($_SESSION['reset_token'] ?? null)) {
            return Response::redirect('/mot-de-passe-oublie');
        }

        return $this->page('auth/reset', ['title' => 'Nouveau mot de passe', 'flash' => $this->pullFlash()], 'layouts/minimal')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function reset(Request $request): Response
    {
        $token = $_SESSION['reset_token'] ?? null;
        $password = is_string($request->form['password'] ?? null) ? $request->form['password'] : '';
        $confirm = is_string($request->form['password_confirmation'] ?? null) ? $request->form['password_confirmation'] : '';
        if (!is_string($token)) {
            return Response::redirect('/mot-de-passe-oublie');
        }
        if (!hash_equals($password, $confirm)) {
            $this->flash('error', 'Les deux mots de passe ne correspondent pas.');

            return Response::redirect('/reinitialisation');
        }
        $result = $this->app->auth()->resetPassword($token, $password, $request->clientIp);
        if ($result === 'ok') {
            unset($_SESSION['reset_token']);
            $this->app->sessions()->regenerate();
            $this->flash('success', 'Votre mot de passe a été modifié. Connectez-vous avec le nouveau.');

            return Response::redirect('/connexion');
        }
        $this->flash('error', match ($result) {
            'password_too_short' => 'Le mot de passe doit contenir au moins 12 caractères.',
            'password_too_long' => 'Le mot de passe est trop long (72 octets au maximum).',
            default => 'Ce lien est invalide ou a expiré. Faites une nouvelle demande.',
        });

        return Response::redirect($result === 'invalid_token' ? '/mot-de-passe-oublie' : '/reinitialisation');
    }

    public function verify(Request $request): Response
    {
        $ok = $this->app->auth()->verifyEmail($request->param('token'));
        $this->flash($ok ? 'success' : 'error', $ok
            ? 'Adresse vérifiée. Vous pouvez vous connecter.'
            : 'Ce lien de vérification est invalide ou a expiré.');

        return Response::redirect('/connexion')->withHeader('Referrer-Policy', 'no-referrer');
    }
}
