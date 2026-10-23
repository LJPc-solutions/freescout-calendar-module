<?php

namespace Modules\LJPcCalendarModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\LJPcCalendarModule\Http\FrontendConfig;

class CalendarPageController extends Controller
{
    public function index(Request $request)
    {
        return view('calendar::index', ['config' => FrontendConfig::page($request->user())]);
    }
}
