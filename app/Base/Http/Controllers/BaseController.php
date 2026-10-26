<?php

namespace App\Base\Http\Controllers;

use App\Base\Traits\Response;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller;

class BaseController extends Controller {

    use ValidatesRequests, AuthorizesRequests, Response;
}
