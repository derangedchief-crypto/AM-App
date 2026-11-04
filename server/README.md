# AM customer API — shared worker integration

The owner approved hosting AM's API alongside the reseller website, provided the existing site remains intact. The existing reseller worker will be reused; a separate worker is no longer required.

Target architecture:

`AM Android → AM customer API → existing reseller worker`

AM owns its customer API, session storage, and customer-scoping logic. Worker shared secrets remain on the server. The Android app must not receive a reseller-wide worker secret or the full reseller line listing. Existing Hub/reseller code and worker contracts are not altered for this integration.

## Current step: identify the hosting layout

`inspect-host.py` is a read-only inventory intended for the aaPanel terminal on the reseller website host. It identifies the application by `manage-lines.php` and `includes/functions.php`, then reports document roots, public server names, PHP capability flags, configuration-section presence (not values), and whether an `am-api` directory already exists.

It does not load/execute the site's PHP configuration, access databases or workers, write files, deploy anything, change ownership/permissions, modify `.user.ini`/vhosts, or restart services. Its output can be returned to the coding session; it excludes worker secrets, credentials, and customer data.

After this inventory, prepare an isolated AM deployment using the verified hosting paths. Confirm its authenticated package/bouquet reads with an authorized test customer before connecting Android. Existing reseller routes/login should receive before/after read-only smoke checks. Do not overwrite an existing `am-api` directory or reuse reseller browser sessions for customer authorization.

This directory currently contains the inventory tool and integration notes, not a deployed/runnable customer API.
