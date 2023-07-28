<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGeneralSettingsRequest;
use App\Settings\GeneralSettings;
use Inertia\Inertia;

class UpdateGeneralSettingsController extends Controller
{
    public function __construct(private readonly GeneralSettings $settings)
    {
    }

    public function __invoke(UpdateGeneralSettingsRequest $request)
    {
        $this->settings->siteName = $request->input('siteName');
        $this->settings->save();

        return to_route('admin.settings');
    }
}