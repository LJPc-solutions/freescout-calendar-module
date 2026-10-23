#!/usr/bin/env bash
# Adds Radicale to FreeScout's ddev project for the CalDAV integration tests and the e2e suite (local development only,
# never shipped):
#   bash Tests/caldav/install.sh
# Copies the compose file and the Radicale config into FreeScout's .ddev folder, restarts ddev, and creates the test
# calendar http://radicale:5232/calendar-test/test/ (user calendar-test, password calendar-test). The host reaches the
# server at http://freescout.ddev.site:5232. FreeScout's .env is not changed: the script prints the
# APP_REMOTE_HOST_WHITE_LIST line that lets FreeScout reach the container.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
module="$(cd "$here/../.." && pwd -P)"
root="$(cd "$module/../.." && pwd -P)"
if [ "$(basename "$(dirname "$module")")" != "Modules" ] || [ ! -f "$root/.ddev/config.yaml" ]; then
    echo "Run this from a module inside Modules/ of a FreeScout checkout that is a ddev project." >&2
    exit 1
fi

mkdir -p "$root/.ddev/radicale"
cp "$here/docker-compose.radicale.yaml" "$root/.ddev/docker-compose.radicale.yaml"
cp "$here/config" "$here/users" "$here/rights" "$root/.ddev/radicale/"
cd "$root"
ddev restart

radicale() {
    ddev exec --raw -- curl --silent --output /dev/null --write-out '%{http_code}' --user calendar-test:calendar-test "$@"
}
for attempt in $(seq 1 30); do
    if [ "$(radicale --request PROPFIND --header 'Depth: 0' http://radicale:5232/calendar-test/ || true)" = "207" ]; then
        break
    fi
    if [ "$attempt" = 30 ]; then
        echo "Radicale did not answer at http://radicale:5232 within 30 seconds." >&2
        exit 1
    fi
    sleep 1
done
status="$(radicale --request MKCALENDAR --header 'Content-Type: application/xml; charset=utf-8' \
    --data '<?xml version="1.0" encoding="utf-8"?><C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:set><D:prop><D:displayname>FreeScout test</D:displayname></D:prop></D:set></C:mkcalendar>' \
    http://radicale:5232/calendar-test/test/)"
case "$status" in
    201) echo "Created the test calendar http://radicale:5232/calendar-test/test/." ;;
    405|409) echo "The test calendar http://radicale:5232/calendar-test/test/ exists already." ;;
    *) echo "Creating the test calendar failed with HTTP $status." >&2; exit 1 ;;
esac

project="$(sed -n 's/^name:[[:space:]]*//p' .ddev/config.yaml | head -n 1)"
subnet="$(docker network inspect "ddev-${project}_default" --format '{{range .IPAM.Config}}{{.Subnet}} {{end}}' | awk '{print $1}')"
# ddev may give its network another subnet when it creates it again, so a subnet in docker's 172.16.0.0/12 pool is
# allowed as that whole pool.
case "$subnet" in
    ""|172.1[6-9].*|172.2[0-9].*|172.3[01].*) range=172.16.0.0/12 ;;
    *) range=$subnet ;;
esac
echo
echo "FreeScout only reaches Radicale when its host and the ddev network (now ${subnet:-unknown}) are allowed. Add this"
echo "line to FreeScout's .env (merge it with an existing APP_REMOTE_HOST_WHITE_LIST), then run: ddev exec php artisan config:clear"
echo
echo "APP_REMOTE_HOST_WHITE_LIST=radicale,${range}"
