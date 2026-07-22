# NeoTalab API (Node reference implementation)

Implements **PRODUCT-SCOPE** domain logic for reference while Laravel (`../../backend/`) is the
production build target.

## Run

```bash
cd reference/api
npm install
npm start
```

- Health: http://localhost:8787/health  
- Scope: http://localhost:8787/scope  

## Endpoints

| Method | Path | Features |
|--------|------|----------|
| POST | `/webhooks/whatsapp` | #72, #35 |
| POST | `/orders/draft` | #3, #8, #11–13 |
| POST | `/orders/:id/fulfillment` | #22–27 |
| POST | `/orders/:id/confirm` | #5, #28–29, #41, #48, #50 |
| POST | `/orders/:id/wish-paid` | #29, #30 |
| GET | `/orders/:id/status` | #61, #54 |
| POST | `/orders/:id/cancel` | #56, #57 |
| POST | `/tracking/:orderId/ping` | #48, #77 |
| GET | `/analytics/daily` | #68, #69 |
| GET | `/customers/:phone` | #17, #18, #65 |

## Database

Full PostgreSQL schema: `../database/schema.sql`

## Laravel

Copy services pattern into `backend/` — see `../../docs/BUILD-PROMPT.md` and `../../PROJECT_MEMORY.md`.
