# MKT-008 sender census, batch 2 — AGENT-CLASSIFIED LEADS, NOT FINDINGS

> **Read this before using the two CSVs.** They are the output of a multi-agent run, kept so the work is
> not lost with the container that produced it. **They are leads for a human to confirm, not verified
> facts.** Eighty-eight of the 239 rows were never checked by a second reader at all, a sample of seven
> checked against the code produced one disagreement and one judgement call (below), and nothing here
> is a statement about what production sends. **GAP-200 does not move because of this folder.**

Design and honest state of the whole MKT-008 effort:
[`../../PHASE-05-MKT-008-ENFORCEMENT-DESIGN.md`](../../PHASE-05-MKT-008-ENFORCEMENT-DESIGN.md). The first
census, which this extends: [`../../PHASE-05-MKT-008-SENDER-CENSUS-2026-11-17.md`](../../PHASE-05-MKT-008-SENDER-CENSUS-2026-11-17.md).

## What was run

One workflow, 164 agents, no errors: the sender sites the first census listed but did not classify were
split into chunks of eight and **classified** (category, who the recipient is, whether any consent is
checked, which first-party seam would intercept it); every verdict that was PROMOTIONAL or had no seam was
then handed to an **adversarial** reader whose job was to refute one named field.

## How to read the counts

| | |
|---|---|
| rows | **239** — but only **163 distinct `file:line` sites.** A route declaration and every handler it reaches are listed as separate rows, so one `routes/customer.php` line appears up to **13** times. Do not read 239 as 239 senders. |
| adversarial check | **101 held · 50 refuted · 88 not checked.** The 50 refutations were on **consent (22)**, **category (19)**, **seam (6)** and **recipient (3)**. |
| first-pass categories | PROMOTIONAL 53 · TRANSACTIONAL 78 · OPERATIONAL_INTERNAL 55 · NOT_A_SENDER 44 · DEAD_CODE 9 |
| after the 19 category refutations are applied | PROMOTIONAL **43** · TRANSACTIONAL **81** · OPERATIONAL_INTERNAL **56** · NOT_A_SENDER **43** · DEAD_CODE **8** · MIXED **8**. Only category refutations are applied; the other 31 refute a different field and are listed in `refutations.csv`. |
| consent checked before the send | **none on 236 rows; "upstream only" on 3** (the three first-party `LynomiaChat` rows: two campaign calls and the platform notifier, whose consent boundary is enforced outside this repository) |
| recipient supplied by the caller | **98 rows.** For an order confirmation that is the shopper entering their own address, which is normal; it is still the field to read first when asking whether a send can be aimed at a third party. |

`sites.csv` carries the run's seam classification in plain words (`mail: MessageSending`,
`mail: sendEmailTemplate + MessageSending`, `whatsapp: Utility::SendMsgs`, `NONE (declared Utility seams)`).
Of the 53 first-pass PROMOTIONAL rows: 17 are reachable only by the mail event, 19 by the template helper
*and* the mail event, 10 by `Utility::SendMsgs`, 2 by the template helper alone, and **5 by no declared
Utility seam** — two are the `LynomiaChat` campaign calls (an HTTP call to an upstream service), and three
are `Mail::to()->send()` calls the run called a "template seam hole" even though the mail event would still
intercept them.

## What I checked against the code, and what I found

| Claim | Result |
|---|---|
| `CouponEmail` sends a first-order and an order-value email to the billing address with the merchant's own subject and body, and checks no consent (`CreateOrderLis.php:31-53`) | **Holds.** The only gates are two module settings. |
| `ReviewReminder` sends on `delivered` to the delivery address, gated only on the merchant's template being active (`SendOrderReviewMailListner.php:42`) | **Holds.** Whether a review request is marketing or relationship mail is a judgement the owner may want to make. |
| `LynomiaChat` campaign create/update call an upstream service and rely on its consent boundary (`CampaignApiController.php:105-150`) | **Holds**, and the module's own comments say so. |
| `SpinToWinReward` sends the "Lucky Winner" coupon to the address the participant just typed (`:322`) | **A judgement call.** The run says PROMOTIONAL; it is also the fulfilment of a prize the person asked for. |
| `ApiController.php:3850` "Stock Status" mail is PROMOTIONAL / HIGH | **I read it differently.** It fires at order time, only when the ordered product is at or below the out-of-stock threshold *and* is configured `notify_customer`: a notice about the customer's own order. I would call it TRANSACTIONAL. The run's WhatsApp twins of the same site were refuted; the mail ones were not, which is the inconsistency to expect from classification by agents. |
| `EmailTemplate::sendEmailTemplate()` does not exist; five `Helpdesk` call sites use it | **Verified in the booted app** (`method_exists` is false; the real helper is `Utility::sendEmailTemplate`). The calls throw and the throw is swallowed, so the Helpdesk notification mails **never send**. The guest-reachable reply route (`helpdesk-ticket/{ticketId}`, middleware `web` only) then writes the exception message into its flash, so the visitor sees the internal class and method name. A vendored-module defect: reported, not edited (owner decision D7); see `GAP-212`. |
| two `LotteryPrizes` jobs (`SendLotteryFailEmail`, `SendOrderCreatedEmail`) are unreferenced | **Verified** by search of `app/`, `packages/`, `routes/`, `config/`, `resources/`. |

**Seven checked: five held, one is a judgement, one I read the other way.** That is a sample, not a rate,
and it is the reason these rows are leads.

## What this does and does not change

* It does **not** change the design: every promotional path found is still reachable by the mail event, the
  template helper, `Utility::SendMsgs`, or an upstream call, which is what the seams in the design document
  assume. It does not show that no *other* path exists; the 88 unchecked rows and any site the run did not
  list are outside it.
* It does **not** close the sender-classification requirement. Until each row a human cares about is
  confirmed, the statement GAP-200 can support is unchanged.
* Nothing here was sent anywhere, and no credential or customer data is in these files.
