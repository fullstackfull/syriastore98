# P2.5-DESIGN-B — before/after, measured

14 merchant screens were captured in all four combinations before and after. A pixel
counts as changed when the summed absolute RGB delta exceeds 12, which ignores
antialiasing noise; the threshold is stated so the number can be checked rather than
believed.

| % of pixels changed | screen · combination |
|---:|---|
| 32.94% | `order_kanban__ar_rtl_dark` |
| 21.20% | `order_kanban__ar_rtl_light` |
| 2.64% | `order_reports__en_ltr_light` |
| 2.60% | `order_reports__en_ltr_dark` |
| 2.35% | `order_reports__ar_rtl_dark` |
| 2.35% | `order_reports__ar_rtl_light` |
| 2.14% | `dashboard__en_ltr_dark` |
| 2.12% | `dashboard__en_ltr_light` |
| 2.11% | `customer__en_ltr_dark` |
| 2.09% | `finance__en_ltr_dark` |
| 2.09% | `customer__en_ltr_light` |
| 2.07% | `finance__en_ltr_light` |
| 1.93% | `dashboard__ar_rtl_dark` |
| 1.91% | `dashboard__ar_rtl_light` |
| 1.89% | `customer__ar_rtl_dark` |
| 1.89% | `finance__ar_rtl_dark` |
| 1.88% | `customer__ar_rtl_light` |
| 1.88% | `finance__ar_rtl_light` |
| 1.40% | `product_list__en_ltr_dark` |
| 1.40% | `order_grid__en_ltr_dark` |
| 1.39% | `order_kanban__en_ltr_dark` |
| 1.39% | `setting__en_ltr_dark` |
| 1.34% | `product_list__en_ltr_light` |
| 1.34% | `order_grid__en_ltr_light` |
| 1.33% | `order_kanban__en_ltr_light` |
| 1.33% | `setting__en_ltr_light` |
| 1.19% | `product_list__ar_rtl_dark` |
| 1.19% | `order_grid__ar_rtl_dark` |
| 1.18% | `setting__ar_rtl_dark` |
| 1.13% | `product_list__ar_rtl_light` |
| 1.13% | `order_grid__ar_rtl_light` |
| 1.12% | `setting__ar_rtl_light` |

**32 of 32 pairs changed. Mean 3.31%, max 32.94%.**

The two Arabic order-board rows at 21% and 33% are the horizontal-overflow repair:
before it, that page sat scrolled right with the sidebar off-screen and every heading
clipped, so the AFTER shot differs across most of the viewport rather than only where
the brand block sits.
