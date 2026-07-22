# Reference (legacy — not the build target)

This folder holds **read-only history** from the original Node/Postgres stack and early static
previews. The production build is **`backend/`** (Laravel 12 + MySQL) and **`web/`** (Next.js).

| Path | What |
|------|------|
| `api/` | Node.js/Express reference API — port domain logic from here when implementing Laravel milestones |
| `database/` | Original Postgres `schema.sql` + hand-written SQL migrations |
| `static/demo/` | WhatsApp flow HTML demo |
| `static/owner/` | Legacy vanilla owner dashboard |
| `static/backoffice/` | Legacy vanilla merchant backoffice |
| `static/join/` | Static “start free trial” landing (calls old Node API on `:8787` if run) |

To run the old Node API for comparison:

```bash
cd reference/api
cp .env.example .env
npm install
npm start   # http://localhost:8787
```

Do **not** add new features here. See [`../PROJECT_MEMORY.md`](../PROJECT_MEMORY.md) for the live roadmap.
