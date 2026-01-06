<?php

declare(strict_types=1);

namespace App\Controllers;

use KallioMicro\Http\Controller;
use KallioMicro\Http\Request;
use KallioMicro\Http\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render('dashboard', [
            'title' => 'Dashboard',
        ]);
    }
}
