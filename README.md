# Task API (standalone — this is the only part you host)

Plain PHP with MySQL or PostgreSQL. No framework, no Composer (PHP 7.4+). Includes a Dockerfile for Render.

## Deploy option A: GitHub + Render (Docker)
1. Put the contents of this `task-api` folder at the **root** of a new GitHub repo (the `Dockerfile` must be at the top level) and push.
2. Create a PostgreSQL database (Render: New > PostgreSQL, or another provider) and copy its **Internal/External Database URL**.
3. On Render: New > Web Service > connect the GitHub repo. Render detects the `Dockerfile` (Runtime: Docker). Pick the Free instance.
4. Add an environment variable `DATABASE_URL` = the database URL from step 2. Deploy.
5. Open `https://your-service.onrender.com/` -- you should see the small JSON "Task API" message. The `tasks` table is created automatically on the first request.
6. Share `https://your-service.onrender.com` with other groups.

Free-tier caveats: the free web service sleeps after ~15 min idle (first request then takes up to a minute), and free Render Postgres databases expire after a limited period. Check Render's current terms, or use another free Postgres host (e.g. Neon) and paste its URL into `DATABASE_URL`.

## Deploy option B: shared PHP hosting + MySQL
1. Create a MySQL database, copy `config.local.sample.php` to `config.local.php` and fill in the host, name, user and password. The table is created automatically (or import `schema_mysql.sql`).
2. Upload every file here (including `.htaccess`) to e.g. `public_html/task-api/`.
3. Open `https://your-domain/task-api/` to check.

> Needs Apache with mod_rewrite. Use HTTPS. Never commit real passwords to GitHub; use environment variables.

## Authentication / separating groups
Every request needs an `X-API-Key` header (6-100 chars). Each group invents its own secret string.
Tasks are stored per key, so groups never see each other's tasks.

## Endpoints
| Method | URL | Description |
|---|---|---|
| GET | /tasks | List tasks (with filters) |
| GET | /tasks/{id} | One task |
| POST | /tasks | Create |
| PUT / PATCH | /tasks/{id} | Update |
| DELETE | /tasks/{id} | Delete |

Fields: `title` (required, max 150), `description`, `due_date` (`YYYY-MM-DD`), `status` (`pending` | `done`).

List filters: `status`, `due` (today | overdue | upcoming | none), `from`, `to`, `q`, `sort_by` (due_date | created_at | title | status), `order` (asc | desc), `page`, `per_page` (max 100).

## Examples
```bash
curl -X POST https://your-domain/task-api/tasks \
  -H "X-API-Key: group7-secret123" -H "Content-Type: application/json" \
  -d '{"title":"Finish report","description":"Chapter 3","due_date":"2026-10-30"}'

curl "https://your-domain/task-api/tasks?status=pending&due=overdue" -H "X-API-Key: group7-secret123"

curl -X PATCH https://your-domain/task-api/tasks/1 \
  -H "X-API-Key: group7-secret123" -H "Content-Type: application/json" -d '{"status":"done"}'
```

Response:
```json
{ "status": 200,
  "data": [ { "id": 1, "title": "Finish report", "description": "Chapter 3", "due_date": "2026-10-30", "status": "pending", "created_at": "...", "updated_at": "..." } ],
  "meta": { "page": 1, "per_page": 20, "total": 1, "total_pages": 1 } }
```
Errors: `401` bad/missing key, `404` not found, `422` validation (`errors` object), `500` server error.
