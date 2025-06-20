(function () {
    var module_routes = [
    {
        "uri": "calendar\/api\/users",
        "name": "ljpccalendarmodule.api.users"
    },
    {
        "uri": "calendar\/api\/calendars",
        "name": "ljpccalendarmodule.api.calendars.all"
    },
    {
        "uri": "calendar\/api\/calendars",
        "name": "ljpccalendarmodule.api.calendar.new"
    },
    {
        "uri": "calendar\/api\/calendars\/{id}",
        "name": "ljpccalendarmodule.api.calendar.update"
    },
    {
        "uri": "calendar\/api\/calendars\/{id}",
        "name": "ljpccalendarmodule.api.calendar.delete"
    },
    {
        "uri": "calendar\/api\/calendars\/authorized",
        "name": "ljpccalendarmodule.api.calendar.authorized"
    },
    {
        "uri": "calendar\/api\/events",
        "name": "ljpccalendarmodule.api.events"
    },
    {
        "uri": "calendar\/api\/events",
        "name": "ljpccalendarmodule.api.event.update"
    },
    {
        "uri": "calendar\/api\/events",
        "name": "ljpccalendarmodule.api.event.create"
    },
    {
        "uri": "calendar\/api\/events\/attachment",
        "name": "ljpccalendarmodule.api.event.create_from_attachment"
    },
    {
        "uri": "calendar\/api\/events\/{conversation}",
        "name": "ljpccalendarmodule.api.event.create_from_conversation"
    },
    {
        "uri": "calendar\/api\/events",
        "name": "ljpccalendarmodule.api.event.delete"
    },
    {
        "uri": "calendar\/api\/calendars\/{id}",
        "name": "ljpccalendarmodule.api.calendar.get"
    },
    {
        "uri": "calendar\/api\/event\/{eventId}",
        "name": "ljpccalendarmodule.api.event.get"
    }
];

    if (typeof(laroute) != "undefined") {
        laroute.add_routes(module_routes);
    } else {
        contole.log('laroute not initialized, can not add module routes:');
        contole.log(module_routes);
    }
})();