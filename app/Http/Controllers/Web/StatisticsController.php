<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\LibraryStatistics;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    public function __invoke(LibraryStatistics $statistics): Response
    {
        return Inertia::render('stats', [
            'summary' => $statistics->summary(),
            'channels' => $statistics->channels(),
            'daily' => $statistics->daily(),
        ]);
    }
}
