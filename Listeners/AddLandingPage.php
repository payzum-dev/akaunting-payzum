<?php

namespace Modules\Payzum\Listeners;

use App\Events\Auth\LandingPageShowing as Event;

class AddLandingPage
{
    /**
     * Handle the event.
     *
     * @param Event $event
     * @return void
     */
    public function handle(Event $event)
    {
        $event->user->landing_pages['payzum.settings.edit'] = trans('payzum::general.name');
    }
}
