# Demo ↔ PRODUCT-SCOPE map

The WhatsApp demo (`demo/index.html`) only checks a feature when that step **happens in the story**.

| Symbol | Meaning |
|--------|---------|
| **✓** | Shown in your current run |
| **—** | In PRODUCT-SCOPE but **not shown** in this HTML demo |
| *(empty)* | In demo, not reached yet this run |

## Shown when you play the default story (delivery + Wish)

| # | Feature | When it gets ✓ |
|---|---------|----------------|
| 1 | WhatsApp-first | Demo loads (3 WhatsApp phones) |
| 35 | Shop hours | Welcome message |
| 64 | Per-role language | Customer writes in Arabic |
| 3 | AI order draft | Draft appears |
| 8, 11, 12 | Menu / modifiers | Draft lines + prices |
| 4 | Buttons | Confirm / Edit / Cancel |
| 13 | Cart engine | Tap **Edit cart** |
| 56, 54 | Cancel + lifecycle | Tap **Cancel** (optional) |
| 22, 25 | Pin + zone | Send location |
| 23, 24 | Building + extra | Type building/floor (or saved address) |
| 18 | Saved address | Tap **Use saved address** (optional) |
| 28 | Cash | Choose **Cash** at payment (optional; default is Wish) |
| 29 | Wish auto-confirm | Choose **Wish** → payment detected |
| 5 | Busy mode | Auto-confirm without merchant tap |
| 62 | Merchant notifications | Merchant gets order alert |
| 37, 54 | Prep + lifecycle | Preparing after confirm |
| 40, 41, 43, 44 | Staff drivers | Assign Rami; Ahmad busy; on duty |
| 47 | 2 taps | Driver: Left store → Delivered |
| 48, 50 | Tracking + ETA | On the way, nearby, arrived |
| 61 | Where is my order? | Tap **Where is my order?** |
| 58, 59 | Receipt + feedback | End of order |
| 68, 69 | EOD + analytics | Merchant messages at close |

## Not in this demo (always **—** on checklist)

2, 6, 7, 9, 10, 14, 16, 17, 20, 21, 27, 30, 31, 32, 34, 36, 38, 39, 42, 46, 49, 51, 53, 57, 65, 66, 70, 72–78

Open **backoffice** separately for #2, #78. Production #76, #77 need real API.

## Removed from product (not in list)

15, 19, 26, 33, 45, 52, 55, 60, 63, 67, 71
