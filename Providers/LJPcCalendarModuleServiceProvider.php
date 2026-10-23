<?php

namespace Modules\LJPcCalendarModule\Providers;

use Config;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\LJPcCalendarModule\CalendarProviders\HttpClientFactory;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\Console\RefreshOccurrences;
use Modules\LJPcCalendarModule\Console\SyncCalendars;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Services\DashboardCard;
use Modules\LJPcCalendarModule\Services\InviteService;
use Modules\LJPcCalendarModule\Services\LineItemText;
use Modules\LJPcCalendarModule\Services\PhpTimezoneFinder;

define('LJPC_CALENDARS_MODULE', 'ljpccalendarmodule');

class LJPcCalendarModuleServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    public const MIN_PHP_VERSION_ID = 80200;
    public const MIN_APP_VERSION = '1.8.243';

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        if (!self::phpSupported(PHP_VERSION_ID)) {
            $this->registerPhpNotice();

            return;
        }
        // FreeScout installs third-party module updates without checking requiredAppVersion.
        if (!\Helper::checkAppVersion(self::MIN_APP_VERSION)) {
            $this->registerAppVersionNotice();

            return;
        }

        require_once __DIR__ . '/../vendor/autoload.php';
        \Illuminate\Support\Facades\Gate::policy(Calendar::class, CalendarPolicy::class);
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(HttpClientFactory::class);
        \Sabre\VObject\TimeZoneUtil::addTimezoneFinder('ljpc_php', new PhpTimezoneFinder());

        $this->registerConfig();
        $this->registerViews();
        $this->registerCommands();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->hooks();
    }

    public static function phpSupported(int $versionId): bool
    {
        return $versionId >= self::MIN_PHP_VERSION_ID;
    }

    /**
     * The v3 migration calls this before it changes anything. Throwing (not returning) keeps the migration unrecorded,
     * so nothing is converted and the next migrate after the PHP or FreeScout upgrade does the work.
     * Callers pass PHP_VERSION_ID and \Helper::checkAppVersion(self::MIN_APP_VERSION).
     */
    public static function assertPlatformSupported(int $phpVersionId, bool $appVersionSupported): void
    {
        if (!self::phpSupported($phpVersionId)) {
            throw new \RuntimeException(__('The calendar module needs PHP 8.2 or newer. This server runs PHP :version, so the calendar upgrade did not start.', ['version' => PHP_VERSION]));
        }
        if (!$appVersionSupported) {
            throw new \RuntimeException(__('The calendar module needs FreeScout 1.8.243 or newer. This server runs FreeScout :version, so the calendar upgrade did not start.', ['version' => (string) Config::get('app.version')]));
        }
    }

    /** Admins see why the module is paused instead of the whole helpdesk crashing on an old PHP. */
    private function registerPhpNotice(): void
    {
        \Eventy::addFilter('dashboard.before', function ($html = '') {
            $user = auth()->user();
            if (!$user || !$user->isAdmin()) {
                return $html;
            }

            return $html . '<div class="alert alert-danger">' . e(__('The calendar module needs PHP 8.2 or newer. This server runs PHP :version, so the module is paused.', ['version' => PHP_VERSION])) . '</div>';
        });
    }

    /** Admins see why the module is paused on a FreeScout version older than the module needs. */
    private function registerAppVersionNotice(): void
    {
        \Eventy::addFilter('dashboard.before', function ($html = '') {
            $user = auth()->user();
            if (!$user || !$user->isAdmin()) {
                return $html;
            }

            return $html . '<div class="alert alert-danger">' . e(__('The calendar module needs FreeScout 1.8.243 or newer. This server runs FreeScout :version, so the module is paused.', ['version' => (string) Config::get('app.version')])) . '</div>';
        });
    }

    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__ . '/../Config/config.php' => config_path('ljpccalendarmodule.php'),
        ], 'config');
        $this->mergeConfigFrom(__DIR__ . '/../Config/config.php', 'ljpccalendarmodule');
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/ljpccalendarmodule');

        $sourcePath = __DIR__ . '/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath,
        ], 'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/ljpccalendarmodule';
        }, Config::get('view.paths')), [$sourcePath]), 'calendar');
    }

    /**
     * Artisan commands
     */
    public function registerCommands()
    {
        $this->commands([RefreshOccurrences::class, SyncCalendars::class]);
    }

    /**
     * Module hooks.
     */
    public function hooks()
    {
        \Eventy::addFilter('schedule', function ($schedule) {
            $schedule->command('calendar:refresh-occurrences')->dailyAt('03:17')->withoutOverlapping();
            // In the background, so a long sync never holds up the commands scheduled after it. The run holds
            // per-calendar leases of its own; the mutex only stops runs from piling up, and expires after 5 minutes
            // (not the default 24 hours) when a run dies without releasing it.
            $schedule->command('calendar:sync')->everyMinute()->withoutOverlapping(5)->runInBackground();

            return $schedule;
        });

        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(LJPC_CALENDARS_MODULE) . '/css/module.css';

            return $styles;
        });

        \Eventy::addAction('menu.append', function () {
            echo View::make('calendar::partials/menu')->render();
        });
        \Eventy::addFilter('menu.selected', function ($menu) {
            $menu['calendar'] = ['ljpccalendarmodule.index'];

            return $menu;
        });

        // "Add to calendar" on the conversation toolbar. A failure here must never break the conversation page.
        \Eventy::addAction('conversation.action_buttons', function ($conversation, $mailbox) {
            try {
                $user = auth()->user();
                if (!$user || !app(\Modules\LJPcCalendarModule\Services\MigrationService::class)->isFinished()) {
                    return;
                }
                $canCreate = \Modules\LJPcCalendarModule\Policies\CalendarPolicy::viewableCalendars($user)->contains(function ($calendar) use ($user) {
                    return $user->can('create', $calendar);
                });
                if ($canCreate) {
                    echo View::make('calendar::partials/conversation_button', ['conversation' => $conversation, 'user' => $user])->render();
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }, 20, 2);

        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['calendar'] = ['title' => __('Calendar'), 'icon' => 'calendar', 'order' => 200];

            return $sections;
        }, 15);
        // FreeScout answers 404 for a section whose settings array is empty; the section keeps no core settings of its own
        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            return $section === 'calendar' ? ['calendar' => true] : $settings;
        }, 20, 2);
        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section === 'calendar' ? 'calendar::settings' : $view;
        }, 20, 2);
        // The section's view data: list, new or edit mode, plus the upgrade report.
        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section !== 'calendar') {
                return $params;
            }
            $params['template_vars'] = \Modules\LJPcCalendarModule\Http\SettingsPage::vars(request());

            return $params;
        }, 20, 2);

        \Eventy::addFilter('dashboard.before', function ($html = '') {
            $user = auth()->user();
            if (!$user) {
                return $html;
            }
            try {
                return $html . app(DashboardCard::class)->render($user);
            } catch (\Throwable $e) {
                report($e); // logged with trace; the FreeScout dashboard must keep working

                return $html;
            }
        });

        // Invites in new mail are read when it arrives, before core saves the conversation: an answer to one of our own
        // invites is processed then, and leaves the conversation's status as it was. Older mail is read when its
        // attachments are first shown. Fetching mail must keep working whatever a file holds.
        \Eventy::addFilter('conversation.status_changing', function ($status, $conversation) {
            \Modules\LJPcCalendarModule\Services\InviteReplies::rememberStatus($conversation);

            return $status;
        }, 20, 2);
        foreach (['conversation.created_by_customer', 'conversation.customer_replied'] as $hook) {
            \Eventy::addFilter($hook, function ($conversation, $thread) {
                try {
                    app(\Modules\LJPcCalendarModule\Services\InviteReplies::class)->receive($conversation, $thread);
                } catch (\Throwable $e) {
                    report($e);
                }

                return $conversation;
            }, 20, 2);
        }

        // The invite cards below a customer's message. A failure here must never break the conversation page.
        \Eventy::addAction('thread.attachments_list_append', function ($thread, $conversation, $mailbox) {
            try {
                $user = auth()->user();
                if (!$user || (int) $thread->type !== \App\Thread::TYPE_CUSTOMER || !app(\Modules\LJPcCalendarModule\Services\MigrationService::class)->isFinished()) {
                    return;
                }
                $html = '';
                foreach (app(InviteService::class)->cards($thread, $user) as $card) {
                    $html .= \Modules\LJPcCalendarModule\Http\InviteCard::render($card, $user);
                }
                echo $html === '' ? '' : $html . \Modules\LJPcCalendarModule\Http\InviteCard::assets($user);
            } catch (\Throwable $e) {
                report($e);
            }
        }, 20, 3);

        // Our calendar replies (answers to invites, invites we send) carry their iTIP message and subject in the thread.
        \Eventy::addAction('email.reply_to_customer.swiftmessage', function ($swiftmessage, $fromAlias, $thread, $mailbox) {
            \Modules\LJPcCalendarModule\Services\ItipMail::addPart($swiftmessage, $thread);
        }, 20, 4);
        \Eventy::addFilter('email.reply_to_customer.subject', function ($subject, $conversation, $thread) {
            return \Modules\LJPcCalendarModule\Services\ItipMail::subject($subject, $thread);
        }, 20, 3);

        \Eventy::addFilter('thread.action_text', function ($didThis, $thread, $conversationNumber, $escape, $viewedByUser = null) {
            if ((int) $thread->action_type !== CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR) {
                return $didThis;
            }
            try {
                return LineItemText::render($thread, (bool) $escape, $viewedByUser instanceof \App\User ? $viewedByUser : null);
            } catch (\Throwable $e) {
                report($e); // logged with trace; conversation pages and notification emails must keep rendering

                // Core has no text of its own for this action type ($didThis is empty), so a neutral line stands in.
                return __('Added to a calendar.');
            }
        }, 20, 5);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerTranslations();
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $this->loadJsonTranslationsFrom(__DIR__ . '/../Resources/lang');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
