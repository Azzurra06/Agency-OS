<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Support\View;

/**
 * Pages publiques du socle (lot 0). Les écrans définitifs arrivent au lot 1.
 */
final class PublicPagesController
{
    public function __construct(private readonly App $app)
    {
    }

    public function home(Request $request): Response
    {
        return Response::html(View::render('pages/home', [
            'title' => 'Agency OS',
            'isPortfolio' => $this->app->config->isPortfolio(),
        ], 'layouts/minimal'));
    }

    public function comingSoon(Request $request): Response
    {
        return Response::html(View::render('pages/coming-soon', ['title' => 'Connexion'], 'layouts/minimal'));
    }

    public function demoExpired(Request $request): Response
    {
        return Response::html(View::render('pages/demo-expired', ['title' => 'Démonstration expirée'], 'layouts/minimal'));
    }
}
