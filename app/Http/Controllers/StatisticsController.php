<?php

namespace App\Http\Controllers;

use App\Support\LibraryStatistics;

class StatisticsController extends Controller
{
    public function __construct(private readonly LibraryStatistics $statistics) {}

    public function show(): array
    {
        return $this->statistics->summary();
    }

    public function channels(): array
    {
        return $this->statistics->channels();
    }

    public function daily(): array
    {
        return $this->statistics->daily();
    }
}
