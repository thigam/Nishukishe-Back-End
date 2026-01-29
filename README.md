# Nishukishe-Back-End
Back-End For Nishukishe.com

#Code lines for reseeding (if everything has been installed, otherwise skip this section)
php artisan migrate:fresh
php artisan db:seed --force
php artisan directions:populate
php artisan directions:backfill-h3
php artisan routes:backfill-route-stop
php artisan routes:seed-flags # set scheduled and variation flags on routes
# One-time build
sudo docker run --rm -v $(pwd)/osrm-data:/data osrm/osrm-backend \
  osrm-extract -p /opt/osrm/profiles/foot.lua /data/kenya-latest.osm.pbf

sudo docker run --rm -v $(pwd)/osrm-data:/data osrm/osrm-backend \
  osrm-partition /data/kenya-latest.osrm

sudo docker run --rm -v $(pwd)/osrm-data:/data osrm/osrm-backend \
  osrm-customize /data/kenya-latest.osrm

# Serve
sudo docker run --rm -p 5000:5000 -v $(pwd)/osrm-data:/data osrm/osrm-backend \
  osrm-routed --algorithm mld /data/kenya-latest.osrm
php artisan directions:backfill-nearest-node
php artisan transfers:build --host=http://localhost:5000 --cap=600
php artisan transfers:approx --speed=1.4 --cap=600 # approximate method
php artisan serve
####

## Social analytics ingestion

Configure the following environment variables to enable automated social metrics syncing:

- `FACEBOOK_PAGE_ID`, `FACEBOOK_ACCESS_TOKEN`
- `INSTAGRAM_BUSINESS_ID`, `INSTAGRAM_ACCESS_TOKEN`
- `X_USER_ID`, `X_BEARER_TOKEN`
- `YOUTUBE_CHANNEL_ID`, `YOUTUBE_API_KEY`
- `LINKEDIN_ORGANIZATION_ID`, `LINKEDIN_ACCESS_TOKEN` (optional display overrides via `LINKEDIN_DISPLAY_NAME`, `LINKEDIN_HANDLE`, `LINKEDIN_PROFILE_URL`, `LINKEDIN_AVATAR_URL`)
- `TIKTOK_BUSINESS_ID`, `TIKTOK_ACCESS_TOKEN`

Each provider respects the optional `*_API_BASE` variables defined in `config/services.php`. When credentials are omitted or API calls fail, the ingestion pipeline falls back to JSON stubs stored in `storage/app/social/stubs` to keep the analytics UI populated.

Run the ingestion command for all providers (or pass a specific platform such as `facebook`, `instagram`, `x`, `youtube`, `linkedin`, `tiktok`):

```bash
php artisan social:ingest all --since="2024-05-01"
```

The command upserts account snapshots and per-post metrics that power the Super Admin analytics social tab.

## Google OAuth onboarding

1. Install dependencies and publish configuration:
   ```bash
   composer install --ignore-platform-req=ext-ffi
   php artisan migrate
   ```
2. Create OAuth credentials in the Google Cloud console (OAuth client type "Web application").
   - Authorized redirect URI: `https://<your-backend-domain>/api/auth/google/callback`
   - JavaScript origin: `https://<your-frontend-domain>`
3. Populate the new environment variables in `.env` (and `.env.example` for sharing):
   ```env
   GOOGLE_CLIENT_ID=...
   GOOGLE_CLIENT_SECRET=...
   GOOGLE_REDIRECT_URI="${APP_URL}/api/auth/google/callback"
   GOOGLE_FRONTEND_REDIRECT="${FRONTEND_URL}/login/google/callback"
   ```
4. Deploy the frontend so the `/login/google/callback` route can capture the `token`, `role` and `mode` parameters returned by the backend.
5. The following endpoints are available once configured:
   - `GET /api/auth/google/redirect` – start the OAuth flow for sign-in/registration.
   - `GET /api/auth/google/link` – start OAuth while signed-in to link an existing account.
   - `DELETE /api/auth/google/link` – disconnect the linked Google account.
   - `GET /api/auth/google/status` – returns `{ linked: boolean }` for profile UI.
6. Every successful Google callback issues a fresh Sanctum token so sessions behave the same as password logins.

## Installing and configuring Laravel: 
https://gist.github.com/noynaert/4cce92ec25b8e4eae0eca8e9e000fd9c

## Fresh Migrate:
php artisan migrate:fresh

# Seed all tables:
php artsian db:seed --force

## Update Sacco tiers

Run the migration and reseed the tier data:

```
php artisan migrate
php artisan db:seed --class=SaccoTierSeeder
```

## Setup for development

1. Install PHP and Composer.
2. Install the H3 C library so the PHP bindings can link against `libh3.so`. On Ubuntu you can run `sudo apt-get install libh3-1` or build from source.
3. Ensure `ffi.enable=true` in `/etc/php/8.3/cli/php.ini` so PHP can load the library.
4. Run `composer install` inside the `backend` directory.
5. Create `database/database.sqlite` and run `php artisan migrate`.
6. Run the test suite:

```bash
APP_KEY=base64:PszxbzmePy6xVbWbr/1QYKqHfOgx/Cs04u5KHyZquqY= php -d ffi.enable=1 artisan test
```
7. Provide your Google Places API key in `.env` using the `GOOGLE_MAPS_API_KEY` variable.

## Available routes:
/sacco -> returns all sacco information 

/sacco/{id} -> returns sacco with specific id

/sacco/name/{name} -> returns sacco with specific name

## Directions
/direction -> returns all directions

## Counties
/counties -> returns all counties

## Routes
/routes -> returns all routes

/routes/sacco/{name} -> returns routes for a specific sacco

/routes/id/{routeid}  -> returns information on a specific route id

## Stops
/stops -> returns all stop information




 



## OSRM Setup

To build the pedestrian routing graph using OSRM:

1. Create an `osrm-data` directory and download `kenya-latest.osm.pbf` from [Geofabrik](https://download.geofabrik.de/africa/kenya.html).
2. Run the official OSRM Docker image to extract and contract the data:

```bash
docker run -t -v $(pwd)/osrm-data:/data osrm/osrm-backend osrm-extract -p /opt/foot.lua /data/kenya-latest.osm.pbf
docker run -t -v $(pwd)/osrm-data:/data osrm/osrm-backend osrm-contract /data/kenya-latest.osrm
docker run -t -i -p 5000:5000 -v $(pwd)/osrm-data:/data osrm/osrm-backend osrm-routed --algorithm mld /data/kenya-latest.osrm
```
proper pipeline:
# 1) Extract with the foot profile
docker run -t -v $(pwd)/osrm-data:/data osrm/osrm-backend \
    osrm-extract -p /opt/foot.lua /data/kenya-latest.osm.pbf

# 2) Partition the graph into cells
docker run -t -v $(pwd)/osrm-data:/data osrm/osrm-backend \
    osrm-partition /data/kenya-latest.osrm

# 3) Customize the partitions (precompute weights)
docker run -t -v $(pwd)/osrm-data:/data osrm/osrm-backend \
    osrm-customize /data/kenya-latest.osrm

# 4) Serve with the MLD algorithm on port 5000
docker run -t -i -p 5000:5000 -v $(pwd)/osrm-data:/data osrm/osrm-backend \
    osrm-routed --algorithm mld /data/kenya-latest.osrm


With the router listening on `http://localhost:5000`, populate `nearest_node_ids` for each stop:

```bash
php artisan directions:backfill-nearest-node
```

Next, create walking edges between nearby stops:

```bash
php artisan transfers:build --host=http://localhost:5000 --cap=600
php artisan transfers:approx --speed=1.4 --cap=600 # approximate method
```

### Multi‑leg routing

The `/api/multileg-route` endpoint performs multi‑leg route finding. It accepts
either query parameters `start_lat`, `start_lng`, `end_lat`, `end_lng` (like the
single‑leg `/routes/search` endpoint) **or** a JSON body:

```json
{
  "origin": [lat, lng],
  "destination": [lat, lng],
  "include_walking": true
}
```

The response groups results into up to seven direct routes (`single_leg`) and
seven multi‑leg alternatives (`multi_leg`). Each route is an ordered list of
steps with a `stop_id` and `label` such as `"bus via R12"` or `"walk 5 min"`.

Set `include_walking` to `false` to omit walking transfers between stops.

## Fare calculation

* Base sacco fares are stored on `sacco_routes.peak_fare` and `sacco_routes.off_peak_fare` (see `App\Models\SaccoRoutes`).
* `App\Services\FareCalculator` maps those sacco amounts through tiers keyed to the percentage of the full route distance, applying these fractions to the provided fares:
  * ≤25% of the route: 50% off-peak / 60% peak
  * ≤50% of the route: 70% off-peak / 80% peak
  * ≤75% of the route: 90% off-peak / 100% peak
  * ≤100% of the route: 100% off-peak / 100% peak
* If a sacco fare is missing, the legacy fallback amounts (40/60, 60/80, 80/100, 110/130) are used as-is for that tier.
* Peak fares are selected during the 05:30–09:30 and 16:00–20:00 windows, or when the CBD/event flags indicate peak conditions. Off-peak is used otherwise.
* Fares are rounded to the nearest 10 (off-peak) or rounded up to the nearest 10 (peak). Segments longer than the full route distance (or >45km when the full distance is unknown) retain the calculated fare but flag `requires_manual_fare`.
#### Rate limits

- The `POST /api/multileg-route` endpoint is capped at **20 requests per minute** per authenticated user or IP (when unauthenticated).
- When the limit is exceeded the API responds with HTTP 429 and a JSON body like:

  ```json
  {
    "message": "Too many multileg route requests. Please retry after the cooldown.",
    "hint": "You can slow down requests or back off when rate-limit headers signal exhaustion."
  }
  ```

- Rate-limit headers are exposed to browsers so clients can self-throttle:
  - `X-RateLimit-Limit`
  - `X-RateLimit-Remaining`
  - `Retry-After`

##Installing Uber's H3:
git clone https://github.com/uber/h3.git
cd h3
git tag -l "v4*"            # list all v4.x tags
git checkout v4.1.0         # swap in the latest v4.x release
mkdir build
cd build
cmake -DCMAKE_BUILD_TYPE=Release ..
cmake --build .


## Pre/post cleaning workflow

Sacco managers submit new routes using the `/pre-clean/routes` endpoints. New stops can be added via `/pre-clean/stops`. These records land in the `pre_clean_*` tables and have a `pending` status until reviewed.

Service staff can list, edit and either approve or reject each submission. Approving a record creates a matching entry in the `post_clean_*` tables. Once verified, run:

```bash
php artisan app:migrate-clean-data
```

to batch move all post-clean rows into the live `sacco_routes` and `stops` tables.

## Create a backup
```bash
php artisan backup:run
```

The Laravel scheduler now runs a database-only backup every Monday at 02:00 server time and writes the archive to the configured
backup disk (defaults to `storage/app/private`). Ensure your host cron is triggering `php artisan schedule:run` every minute so
the weekly job fires.

## Restore latest backup
```bash
php artisan backup:restore
```

## Referesh database and seed everything
```bash
php artisan app:refresh-all

## Sacco Management Commands

Generate a verification link for a Sacco manager (if they missed the email):
```bash
php artisan make:sacco-verification-link <email>
```

Generate an approval link for a Sacco manager (to complete registration and set password):
```bash
php artisan make:sacco-approval-link <email>
```
