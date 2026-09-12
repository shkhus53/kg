<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Event;
use App\Models\Miqaat;
use App\Models\Venue;
use Illuminate\View\View;

class MasterDataController extends Controller
{
    public function index(): View
    {
        return view('admin.masters.index', [
            'departmentCount' => Department::count(),
            'miqaatCount' => Miqaat::count(),
            'eventCount' => Event::count(),
            'venueCount' => Venue::count(),
        ]);
    }
}
