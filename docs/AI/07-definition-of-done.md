# Definition of Done

The project is "ready" only when **every** box is checked and each has
evidence in a result file. Executor checks the box and links the evidence;
reviewer confirms.

| # | Criterion | Evidence (result file § ) | Executor | Reviewer |
|---|---|---|---|---|
| 1 | All phases 01–06 APPROVED in `reviews/` | `PROGRESS.md` | ☐ | ☐ |
| 2 | Zero open P0 / P1 / P2 findings | all result files | ☐ | ☐ |
| 3 | Every NEEDS-DECISION answered by the owner and implemented | `PROGRESS.md` decisions table | ☐ | ☐ |
| 4 | Full suite green 3× in a row, no unjustified skips | 06 §4 | ☐ | ☐ |
| 5 | Journeys J01–J15 exist and pass | 04 journey table | ☐ | ☐ |
| 6 | Every service and controller has at least one test (coverage map has no `NONE`) | 01 coverage map, updated | ☐ | ☐ |
| 7 | Money, stock, coupon and webhook rules each have an exact-value test | 02 D3/D4/D5 | ☐ | ☐ |
| 8 | Pint clean, PHPStan level ≥ 5 with empty baseline | 06 §5–6 | ☐ | ☐ |
| 9 | `composer audit` clean or justified | 06 §7 | ☐ | ☐ |
| 10 | config/route/event cache succeed | 06 §8 | ☐ | ☐ |
| 11 | API routes unchanged vs baseline (contracts respected) | 06 §10 | ☐ | ☐ |
| 12 | Security checklist fully answered | 05 | ☐ | ☐ |
| 13 | Postman/Newman smoke green | 06 §11 | ☐ | ☐ |
| 14 | README accurate; `.env.example` complete | 03 | ☐ | ☐ |
| 15 | Ops handover list written | 06 | ☐ | ☐ |

Note: "100% working" here means *every behaviour we know about is specified
and proven by a test*. Things that can only be proven in production (real
Stripe live keys, real EasyPost account, server cron/queue) are listed in the
ops handover and must be smoke-tested once after deployment.
