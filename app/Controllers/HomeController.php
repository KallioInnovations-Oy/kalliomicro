<?php

declare(strict_types=1);

namespace App\Controllers;

use KallioMicro\Http\Controller;
use KallioMicro\Http\Request;
use KallioMicro\Http\Response;

class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render('home', [
            'title' => 'Welcome to KallioMicro',
        ]);
    }
}
