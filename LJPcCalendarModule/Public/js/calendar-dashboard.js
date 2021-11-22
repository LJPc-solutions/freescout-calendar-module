$(document).ready(function() {
    $('.dash-card.ljpc_move_dash_card').each(function(i, el) {
        $('.dash-cards').prepend(el);
    });

    String.prototype.allReplace = function(obj) {
        var retStr = this;
        for (var x in obj) {
            retStr = retStr.replace(new RegExp(x, 'g'), obj[x]);
        }
        return retStr;
    };

    function getCalendar(calendarId) {
        let foundCalendar;
        calendars.forEach(function(calendar) {
            if(calendar.id === calendarId) {
                foundCalendar = calendar
            }
        });

        return foundCalendar;
    }

    function order(items) {
        var allDay = [];
        var normal = [];
        for (const uid in items) {
            const item = items[uid];
            if(item.isAllDay && moment(item.start).format('YYYY-MM-DD') === moment().format('YYYY-MM-DD')) {
                //Today
                allDay.push(item);
            } else if(item.isAllDay && moment(item.start).diff(moment()) < 0 && moment(item.end).diff(moment()) > 0) {
                //Inside all day
                allDay.push(item);
            } else {
                if(moment(item.start).diff(moment()) < 0) continue;
                normal.push(item);
            }
        }

        normal.sort((a, b) => (a.start > b.start) ? 1 : -1)

        $('.dash-calendar-contents').html('');
        var totalShow = 0;
        const template = `
<div class="dash-card-list-item">
    <small>{{title}}</small>
    <strong class="has-value" style="text-transform:lowercase; font-weight:400; font-size: 85%; position: relative; top:3px;">{{time}}</strong><br />
    <span class="badge" style="background-color: {{bgColor}}">{{calendar}}</span>
    <strong class="has-value" style="text-transform:lowercase">{{relativeTime}}</strong>
</div>
`
        for (const item of allDay) {
            if(totalShow === 3) {
                break;
            }

            $('.dash-calendar-contents').append(template.allReplace({
                '{{title}}': item.title,
                '{{time}}': translations.today,
                '{{bgColor}}': getCalendar(item.calendarId).colors.backgroundColor,
                '{{calendar}}': getCalendar(item.calendarId).name,
                '{{relativeTime}}': translations.allDay,
            }));
            totalShow++;
        }
        for (const item of normal) {
            if(totalShow === 3) {
                break;
            }
            $('.dash-calendar-contents').append(template.allReplace({
                '{{title}}': item.title,
                '{{time}}': moment(item.start).format(translations.timeFormat),
                '{{bgColor}}': getCalendar(item.calendarId).colors.backgroundColor,
                '{{calendar}}': getCalendar(item.calendarId).name,
                '{{relativeTime}}': moment(item.start).fromNow(),
            }));
            totalShow++;
        }
        if(totalShow === 0) {
            $('.dash-card-calendar').hide();
        } else {
            $('.dash-card-calendar').show();
        }
    }

    function showItems() {
        $.ajax({
            type: "GET",
            url: '/calendar/ajax',
            data: {
                start: moment().startOf('day').unix(),
                end: moment().startOf('day').add(7, 'days').unix()
            },
            success: function(data) {
                order(data);
            }
        });
    }

    setTimeout(function() {
        var channel = poly.subscribe('calendar');
        channel.on('Modules\\LJPcCalendarModule\\Events\\CalendarUpdatedEvent', function(data, event) {
            showItems();
        });
    }, 2000);

    setInterval(function() {
        showItems()
    }, 60000);

    showItems();
});
