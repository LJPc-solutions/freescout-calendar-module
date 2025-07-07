# LJPc calendar module for FreeScout

This module adds a calendar module to FreeScout.

<a href="https://www.buymeacoffee.com/Lars-" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-orange.png" alt="Buy Me A Coffee" height="60" style="height: 60px !important;width: 217px !important;" ></a>

> [!CAUTION]
> Version 2.0.0 is a complete rewrite of the module. This means that there are a lot of changes in the code. We did our very best to make it fully backwards compatible, but we can't guarantee that everything will work as expected. Please
> make a backup of your database and the module before upgrading to version 2.0.0.

## Explanation video

[![Watch the video](https://resources.ljpc.network/freescout-modules/calendar/video-thumbnail-with-overlay.png)](https://videos.ljpc.nl/view?m=18xtdDMS2)

## Functions

- You can add unlimited calendars via the settings page.
- It is possible to automatically import an external calendar via its an ICS link or sync via CalDAV. This way, you can import your Google Calendar, iCloud Calendar, or any other calendar that supports ICS or CalDAV.
- Extra card on the dashboard to view the first 3 upcoming events.
- Every dependency is added to this module, so there are no dependencies on CDNs.
- Permissions are added to the module. You can set which users/teams cam view the events on the dashboard, can view the calendar, create events and edit events
- Create events from a conversation. If the conversation includes an attachment with an ICS file, the event will be created with the data from the ICS file. Otherwise, you can specify the details yourself.
- Fully responsive calendar view.
- Performance optimizations for large calendars with instant event lookups via database indexing and CalDAV REPORT queries.

## Configuration

### Performance Feature Flags

The calendar module includes performance optimization features that can be enabled via environment variables:

```bash
# Enable database indexing for instant event lookups (recommended)
CALENDAR_ENABLE_EVENT_INDEX=true

# Enable CalDAV REPORT queries for targeted event fetching
CALENDAR_ENABLE_CALDAV_REPORTS=true

# Force legacy parsing mode (disable all optimizations)
CALENDAR_FORCE_LEGACY_MODE=false
```

Add these to your `.env` file to enable the performance features. The event index dramatically improves event lookup speed from 10+ seconds to ~1.5ms for large calendars.

## Installation

1. Download the latest module zip file [here](https://resources.ljpc.network/freescout-modules/calendar/latest.zip). **Do not use the master branch!** The master branch is not stable and should only be used for development
   purposes.
2. Transfer the zip file to the server in the Modules folder of FreeScout.
3. Unpack the zip file.
4. Remove the zip file.
5. Activate the module via the Modules page in FreeScout.
6. Configure the module by setting up calendars via the settings.

## Update Instructions

This module supports automatic updates. You can update the module via the Modules page in FreeScout.

> [!NOTE]  
> This works since version 2.0.0. If you are using an older version, you need to update manually.

If you are upgrading from version 1.x, please check the [releasenotes](https://github.com/LJPc-solutions/freescout-calendar-module/releases/tag/2.0.0).

## Translations

Here is the complete list of translations for the WHMCS Module for FreeScout:

- English (default)
- Dutch (nl)
- Czech (cs)
- Danish (da)
- German (de)
- Spanish (es)
- Persian (fa)
- Finnish (fi)
- French (fr)
- Croatian (hr)
- Italian (it)
- Japanese (ja)
- Korean (ko)
- Norwegian (no)
- Polish (pl)
- Portuguese (Brazil) (pt-BR)
- Portuguese (Portugal) (pt-PT)
- Russian (ru)
- Slovak (sk)
- Swedish (sv)
- Turkish (tr)
- Chinese (Simplified) (zh-CN)

The module supports a wide range of languages to cater to users from different regions and linguistic backgrounds. If you would like to contribute a translation for a language that is not listed here, please feel free to submit a pull
request with the new language files.

## The future of this module

Feel free to add your own features by sending a pull request.

## Custom software

Interested in a custom FreeScout module or anything else? Please let us know
via [info@ljpc.nl](mailto:info@ljpc.nl?subject=Calendar%20module) or [www.ljpc.solutions](https://ljpc.solutions).

## Donations

This module took us a lot of time, but we decided to make it open source anyway. If we helped you or your business,
please consider donating.
[Click here](https://www.buymeacoffee.com/Lars-) to donate.



