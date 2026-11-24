# The 181 decision IDs — recovered content, mapping confidence, and reconciliation

**Built from `MASTER-PRODUCT-PLAN-v1.0-BASE.md`, which is committed verbatim beside this file.**
Nothing here is invented. Where the source does not determine a per-id mapping, this file says so
rather than choosing one.

> **WHAT RECOVERY IS AND IS NOT.** Recovering a requirement's text is **not** evidence that the
> requirement was implemented, and no row in the gap register was marked complete or approved by the
> act of recovering the plan. This file changes what we *know was asked for*. It does not change what
> was *built*.

---

## 1. What the recovery changed, in exact numbers

| | before 2026-11-16 | after |
|---|---|---|
| decision IDs **nameable** | ~40, as six range expressions in the Phase 02 master prompt | **181** |
| decision IDs **literally written** anywhere in the repository | **9** (`UX-001`, `UX-014`, `ARCH-001`, `ARCH-011`, `EX-001`, `EX-012`, `ORD-001`, `ORD-009`, `PRD-001` — all of them range endpoints) | 181 |
| decision IDs with **defined content** | **0** | **178** |
| decision **families** known | 6 named (`UX`, `PRD`, `ORD`, `ARCH`, `GOV`/`DATA`, `EX`) | **25** |
| families entirely unknown before | — | **19** (`ADM ANA CHAN COD CTRL CUST DATA GOV IAM INV KYC MARKET MKT OPS PRICE RET SAAS SHIP SUPPORT THEME` minus the two that were named) |
| the `EX-001`–`EX-012` exclusions | named, content **lost** — called "the most completely lost" in `MASTER-PLAN-SOURCE-VERIFICATION.md` §3.2 | **recovered in full, 12 of 12** |

**How confidently each id can be read:**

| | ids | meaning |
|---|---|---|
| **per-id text derivable** | **114** | 102 from 16 families whose text count equals their id count, plus 12 exclusions the source numbers explicitly |
| **family text recovered, per-id pairing NOT derivable** | **64** | 42 in six families the plan cites across sections rather than defining as a list, plus the 22 recovered `GOV`/`ARCH` texts whose family is short |
| **no text at all** | **3** | 1 `GOV` and 2 `ARCH` decisions. See §5 |

**The count-matched pairing is an ordered reading, not a stated mapping.** The plan writes each
family as an unnumbered bullet list under a declared range. Where the counts agree, the Nth bullet is
read as the Nth id; the source never says so. Every such table below carries that caveat, and no row
in the traceability matrix may be marked VERIFIED COMPLETE on that pairing alone.

---

## 2. The register


### `PRD-001` … `PRD-006` — 6 decision texts recovered, 6 ids

**Mapping: COUNT-MATCHED.** 6 ids, 6 texts. Phase **04 (UI in 02)**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `PRD-001` | محرر منتج كامل. | Full product editor |
| `PRD-002` | خصائص وقوالب حسب نوع النشاط. | Attributes and templates by business type |
| `PRD-003` | متغيرات وخيارات وتخصيصات نصية وملفات وألوان. | Variants, options, and text/file/colour customisations |
| `PRD-004` | حفظ خيارات التخصيص والسعر في سجل الطلب. | Store the customisation choice and its price in the order record |
| `PRD-005` | باقات ثابتة ومرنة ومنتجات مكملة. | Fixed and flexible bundles, and complementary products |
| `PRD-006` | مجموعات منتجات يدوية وذكية وربطها بالثيمات. | Manual and smart product collections, linked to themes |


### `INV-001` … `INV-010` — 10 decision texts recovered, 10 ids

**Mapping: COUNT-MATCHED.** 10 ids, 10 texts. Phase **04**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `INV-001` | مستودع افتراضي واحد مع إمكانية تعدد المستودعات. | One default warehouse, with multi-warehouse capability |
| `INV-002` | مصدر موحد للكميات الفعلية والمحجوزة والمتاحة. | A single source for actual, reserved and available quantities |
| `INV-003` | حركات مخزنية وجرد وتحويلات وتسويات. | Stock movements, stocktakes, transfers and adjustments |
| `INV-004` | منع البيع فوق المتاح افتراضياً. | Prevent selling above available by default |
| `INV-005` | الحجز الآمن عند تأكيد الطلب، لا عند إضافته للسلة. | Safe reservation at order confirmation, NOT at add-to-cart |
| `INV-006` | الطلب المسبق الاختياري والصريح. | Optional and explicit pre-order |
| `INV-007` | اختيار مستودع التنفيذ تلقائياً مع تعديل مصرح به. | Automatic fulfilment-warehouse selection, with an authorised override |
| `INV-008` | توزيع التنفيذ على عدة مستودعات وشحنات جزئية. | Split fulfilment across warehouses, and partial shipments |
| `INV-009` | عدم اعتبار المخزون السالب متاحاً للبيع الفوري. | Negative stock is not available for immediate sale |
| `INV-010` | استبعاد Batch/Lot وتواريخ الصلاحية حالياً. | Batch/Lot and expiry dates excluded for now (see EX-004) |


### `ORD-001` … `ORD-009` — 9 decision texts recovered, 9 ids

**Mapping: COUNT-MATCHED.** 9 ids, 9 texts. Phase **04 (UI in 02)**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `ORD-001` | جدول طلبات وعرض Kanban اختياري. | Order table, with an optional Kanban view |
| `ORD-002` | مراحل طلب عامة ثابتة. | Fixed general order stages |
| `ORD-003` | الفصل بين حالة الطلب والدفع والتجهيز والشحن. | Separate order, payment, fulfilment and shipping status |
| `ORD-004` | منع تعديل منتجات الطلب وكمياته بعد إنشائه. | Prevent editing an order’s products and quantities after creation (see EX-006) |
| `ORD-005` | السماح بتعديل بيانات التوصيل والملاحظات ضمن الصلاحيات والحالة. | Allow editing delivery data and notes, within permissions and status |
| `ORD-006` | تصحيح المنتجات عبر إلغاء وطلب بديل مرتبط. | Correct products via cancellation plus a linked replacement order |
| `ORD-007` | طلبات يدوية من الهاتف والمحادثة ونقاط البيع. | Manual orders from phone, chat and point of sale |
| `ORD-008` | Picking وPacking والباركود والملصقات والفواتير. | Picking, packing, barcodes, labels and invoices |
| `ORD-009` | مركز «تحتاج متابعة». | A “needs follow-up” centre |


### `PRICE-001` … `PRICE-007` — 7 decision texts recovered, 7 ids

**Mapping: COUNT-MATCHED.** 7 ids, 7 texts. Phase **04**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `PRICE-001` | محرك تسعير مركزي واحد. | One central pricing engine |
| `PRICE-002` | قوائم أسعار للجملة والشرائح والأسواق والقنوات. | Price lists for wholesale, tiers, markets and channels |
| `PRICE-003` | أسعار للمتغيرات والتخصيصات والباقات. | Prices for variants, customisations and bundles |
| `PRICE-004` | قواعد صريحة لأولويات الخصومات. | Explicit discount-priority rules |
| `PRICE-005` | عدم اختيار أقل سعر أو جمع الخصومات تلقائياً. | Never auto-pick the lowest price, never auto-stack discounts |
| `PRICE-006` | معاينة السعر النهائي وشرح طريقة حسابه. | Preview the final price and explain how it was calculated |
| `PRICE-007` | الحساب النهائي على الخادم وحفظ تفاصيل السعر الأصلي في الطلب. | Final calculation on the server; store the original price detail in the order |


### `RET-001` … `RET-007` — 7 decision texts recovered, 7 ids

**Mapping: COUNT-MATCHED.** 7 ids, 7 texts. Phase **04**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `RET-001` | مرتجعات كاملة وجزئية. | Full and partial returns |
| `RET-002` | فحص حالة المنتج قبل إعادته للبيع. | Inspect product condition before returning it to sale |
| `RET-003` | الاستبدال عبر طلب جديد مرتبط. | Exchange via a new linked order |
| `RET-004` | احتساب فروقات السعر والشحن والدفعات. | Account for price, shipping and payment differences |
| `RET-005` | منع رد مبلغ أو كمية تتجاوز الاستحقاق. | Prevent refunding an amount or quantity beyond entitlement |
| `RET-006` | منع تكرار رد الأموال أو تعديل المخزون. | Prevent duplicate refunds or duplicate stock adjustments |
| `RET-007` | الاحتفاظ بالطلب الأصلي وسجل البيع دون محوه. | Keep the original order and sales record without erasing it |


### `MKT-001` … `MKT-010` — 10 decision texts recovered, 10 ids

**Mapping: COUNT-MATCHED.** 10 ids, 10 texts. Phase **05**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `MKT-001` | قسم تسويق مستقل مرتبط بالتحليلات. | An independent marketing section, linked to analytics |
| `MKT-002` | تجربة بسيطة افتراضياً ومتقدمة عند الحاجة. | Simple by default, advanced when needed |
| `MKT-003` | كوبونات وخصومات تلقائية وBOGO. | Coupons, automatic discounts and BOGO |
| `MKT-004` | شرائح عملاء ثابتة وديناميكية. | Static and dynamic customer segments |
| `MKT-005` | نقاط ولاء وVIP وإحالات مع سجل حركات. | Loyalty points, VIP and referrals, with a transaction log |
| `MKT-006` | عكس المكافآت عند الإلغاء والاسترداد. | Reverse rewards on cancellation and refund |
| `MKT-007` | استعادة السلات والمدفوعات غير المكتملة بلا تكرار. | Recover carts and incomplete payments, without duplication |
| `MKT-008` | احترام أهلية وموافقة العميل على الرسائل. | Respect customer eligibility and consent for messages |
| `MKT-009` | تكامل Lynomia عند الجاهزية دون ازدواج. | Lynomia integration when ready, without duplication |
| `MKT-010` | أدوات تحويل داخل المتجر مرتبطة بالثيمات. | In-store conversion tools, linked to the themes |


### `ANA-001` … `ANA-010` — 10 decision texts recovered, 10 ids

**Mapping: COUNT-MATCHED.** 10 ids, 10 texts. Phase **05**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `ANA-001` | مركز للمبيعات والمالية والمنتجات والعملاء والتسويق. | A centre for sales, finance, products, customers and marketing |
| `ANA-002` | مقارنات وفلاتر وفترات محفوظة. | Comparisons, filters and saved periods |
| `ANA-003` | تقارير مخصصة وتصدير وجدولة بصلاحيات. | Custom reports, export and scheduling, permission-gated |
| `ANA-004` | تحليل التحويل والأجهزة والقنوات. | Conversion, device and channel analysis |
| `ANA-005` | الفصل بين المبيعات والتحصيل والتسويات والربحية. | Separate sales, collection, settlements and profitability |
| `ANA-006` | عدم ادعاء ربحية دقيقة عندما تكون بيانات التكلفة أو الرسوم ناقصة. | Do NOT claim precise profitability when cost or fee data is missing |
| `ANA-007` | إظهار جودة البيانات والتقديرات والنواقص. | Show data quality, estimates and omissions |
| `ANA-008` | توثيق تعريفات المؤشرات ومصادرها. | Document metric definitions and their sources |
| `ANA-009` | تنبيهات وتحليلات استباقية بضوابط واضحة. | Alerts and proactive analytics, with clear controls |
| `ANA-010` | عدم بناء محرك محاسبي جديد. | Do NOT build a new accounting engine |


### `ADM-001` … `ADM-002` — 2 decision texts recovered, 2 ids

**Mapping: COUNT-MATCHED.** 2 ids, 2 texts. Phase **06**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `ADM-001` | لوحة قيادة شاملة للمنصة. | A comprehensive platform command dashboard |
| `ADM-002` | مساحة Store 360° لمراجعة حالة كل متجر وإدارته من أنظمته الأصلية. | A Store 360° space to review and manage each store from its own systems |


### `SAAS-001` … `SAAS-007` — 7 decision texts recovered, 7 ids

**Mapping: COUNT-MATCHED.** 7 ids, 7 texts. Phase **06**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `SAAS-001` | الباقات والتجارب والفواتير وحدود الاستخدام. | Plans, trials, invoices and usage limits |
| `SAAS-002` | الترقيات والتخفيضات والتجديد وفترات السماح. | Upgrades, downgrades, renewal and grace periods |
| `SAAS-003` | عدم حذف بيانات المتجر عند انتهاء الاشتراك. | Do NOT delete store data when a subscription ends (see EX-011) |
| `SAAS-004` | تقييد البيع الجديد بعد انتهاء فترة السماح، مع الحفاظ على الطلبات القائمة والتتبع والدعم. | Restrict new selling after the grace period, while preserving existing orders, tracking and support |
| `SAAS-005` | إعادة التفعيل دون فقد البيانات. | Reactivation without data loss |
| `SAAS-006` | عدم حذف محتوى يتجاوز حدود الباقة المخفضة. | Do NOT delete content that exceeds a downgraded plan’s limits |
| `SAAS-007` | فصل إيرادات المنصة MRR/ARR عن GMV ومبيعات التجار. | Separate platform revenue (MRR/ARR) from GMV and merchant sales |


### `KYC-001` … `KYC-003` — 3 decision texts recovered, 3 ids

**Mapping: COUNT-MATCHED.** 3 ids, 3 texts. Phase **06**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `KYC-001` | توثيق الأفراد والشركات. | Verification of individuals and companies |
| `KYC-002` | إدارة الوثائق الخاصة والمراجعات والتجديد وسجلات الوصول. | Manage private documents, reviews, renewal and access logs |
| `KYC-003` | فصل توثيق Store Syria عن توثيق مزودي الدفع والتراخيص الحكومية. | Separate Store Syria verification from payment-provider and government-licence verification |


### `OPS-001` … `OPS-003` — 3 decision texts recovered, 3 ids

**Mapping: COUNT-MATCHED.** 3 ids, 3 texts. Phase **06**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `OPS-001` | مراقبة الخوادم وقواعد البيانات والطوابير والتكاملات. | Monitor servers, databases, queues and integrations |
| `OPS-002` | قياسات موارد حقيقية. | Real resource measurements |
| `OPS-003` | إدارة الحوادث والتنبيهات والتشخيص. | Incident, alert and diagnostics management |


### `MARKET-001` … `MARKET-005` — 5 decision texts recovered, 5 ids

**Mapping: COUNT-MATCHED.** 5 ids, 5 texts. Phase **06**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `MARKET-001` | سوق للتطبيقات والثيمات والمطورين. | A marketplace for apps, themes and developers |
| `MARKET-002` | مراجعات أمنية وتوافق وإصدارات وصلاحيات. | Security, compatibility, versioning and permission reviews |
| `MARKET-003` | أسعار وعوائد للمطورين عند اعتماد نموذجها التجاري. | Prices and developer revenue share, once its commercial model is approved |
| `MARKET-004` | عدم تنفيذ كود خارجي غير موثوق داخل الإنتاج. | Do NOT execute untrusted external code in production (see EX-010) |
| `MARKET-005` | تعليق التطبيقات أو سحب صلاحياتها دون إتلاف بيانات التاجر. | Suspend apps or withdraw their permissions without destroying merchant data |


### `SUPPORT-001` … `SUPPORT-005` — 5 decision texts recovered, 5 ids

**Mapping: COUNT-MATCHED.** 5 ids, 5 texts. Phase **06**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `SUPPORT-001` | التذاكر وفرق الدعم وSLA وقاعدة المعرفة والفيديوهات. | Tickets, support teams, SLA, knowledge base and videos |
| `SUPPORT-002` | مساعدة سياقية وإعلانات للويب وFlutter. | Contextual help and announcements, for web and Flutter |
| `SUPPORT-003` | مراجعة Support Ticket وHelpdesk الحاليين قبل استبدالهما. | Review the existing Support Ticket and Helpdesk modules before replacing them |
| `SUPPORT-004` | وصول دعم مؤقت ومحدد بموافقة التاجر وسجل تدقيق. | Temporary, scoped support access with merchant consent and an audit log |
| `SUPPORT-005` | وصول طوارئ منفصل وبصلاحيات وموافقات أعلى. | Separate emergency access, with higher permissions and approvals |


### `IAM-001` … `IAM-004` — 4 decision texts recovered, 4 ids

**Mapping: COUNT-MATCHED.** 4 ids, 4 texts. Phase **06**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `IAM-001` | صلاحيات دقيقة لموظفي Super Admin. | Fine-grained permissions for Super Admin staff |
| `IAM-002` | مصادقة قوية وإدارة الجلسات. | Strong authentication and session management |
| `IAM-003` | موافقات ثنائية للإجراءات الخطرة. | Two-person approval for dangerous actions |
| `IAM-004` | سجل تدقيق مركزي واستجابة للحوادث. | A central audit log and incident response |


### `CTRL-001` … `CTRL-004` — 4 decision texts recovered, 4 ids

**Mapping: COUNT-MATCHED.** 4 ids, 4 texts. Phase **06**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `CTRL-001` | تفعيل الميزات حسب الباقات والمتاجر والمجموعات التجريبية. | Feature enablement by plan, store and pilot group |
| `CTRL-002` | إطلاق تدريجي وإيقاف آمن. | Gradual rollout and safe disable |
| `CTRL-003` | سياسات عامة وإعلانات صيانة وسجل تغييرات. | Public policies, maintenance announcements and a change log |
| `CTRL-004` | عدم حذف بيانات ميزة بمجرد إيقاف إتاحتها. | Do NOT delete a feature’s data merely because its availability was disabled |


### `DATA-001` … `DATA-010` — 10 decision texts recovered, 10 ids

**Mapping: COUNT-MATCHED.** 10 ids, 10 texts. Phase **all phases**. The per-id order below is the source's own bullet order — the plan does not number the bullets, so the pairing is an ordered reading, not a stated mapping.

| id | recovered decision (verbatim) | English label |
|---|---|---|
| `DATA-001` | عزل بيانات المتاجر على جميع الطبقات. | Isolate store data at every layer |
| `DATA-002` | نسخ احتياطية محمية واختبارات استعادة فعلية. | Protected backups and REAL restore tests |
| `DATA-003` | أهداف تعافٍ مبنية على قياسات البيئة الحقيقية. | Recovery objectives based on real-environment measurement |
| `DATA-004` | Staging مناسبة للاختبارات. | Staging suitable for testing |
| `DATA-005` | استيراد آمن للمنتجات والعملاء والمخزون والطلبات التاريخية. | Safe import of products, customers, stock and historical orders |
| `DATA-006` | معاينة الاستيراد واكتشاف التكرار وتقرير النتائج. | Import preview, duplicate detection and a result report |
| `DATA-007` | عدم احتساب الطلبات التاريخية كمبيعات جديدة. | Historical orders must NOT count as new sales |
| `DATA-008` | حماية تخصيصات المتاجر أثناء الترحيل. | Protect store customisations during migration |
| `DATA-009` | خطط تراجع وتعافٍ واقعية دون افتراض أن كل Migration قابلة للعكس. | Realistic rollback and recovery plans; do not assume every migration is reversible |
| `DATA-010` | منع إعادة تنفيذ الأحداث المالية والمخزنية. | Prevent re-execution of financial and inventory events |


### `GOV-001` … `GOV-014` — 13 decision texts recovered, 14 ids

**Mapping: INCOMPLETE — 1 decision text(s) MISSING.** The range declares 14 ids and the recovered document carries only 13 texts. Phase **all phases**. **The 1 missing text(s) are NOT reconstructed here and must not be invented.**

| # | recovered decision (verbatim) | English label |
|---|---|---|
| 1 | الحفاظ على Store Syria وWorkDo وتطويرهما دون إعادة بناء. | Preserve and develop Store Syria and WorkDo without rebuilding |
| 2 | منع الحذف أو التراجع الوظيفي أو تغيير السلوك التجاري المؤثر بلا موافقة صريحة. | No deletion, functional regression or material commercial-behaviour change without explicit approval |
| 3 | ست مراحل رئيسية متتابعة. | Six sequential main phases |
| 4 | إصدار إنتاج رئيسي واحد بعد اكتمال كل مرحلة، مع السماح بالتطوير والاختبارات الداخلية تدريجياً. | One major production release per completed phase; incremental internal development and testing allowed |
| 5 | لا بدء رسمي للمرحلة التالية ولا نشر إنتاجي دون الموافقة الرسمية. | No official start of the next phase and no production deployment without formal approval |
| 6 | تقرير إنجاز واختبارات ومخاطر وقبول لكل مرحلة. | A completion, testing, risk and acceptance report for every phase |
| 7 | منع إطلاق عيوب حرجة أو عالية أو تراجع مؤثر. | No release carrying critical or high defects or a material regression |
| 8 | استثناء العيوب البسيطة يحتاج موافقة ومسؤولاً وخطة معالجة. | Exempting a minor defect needs approval, a named owner and a remediation plan |
| 9 | مسار طوارئ منفصل للإصلاحات الأمنية والتشغيلية الحرجة. | A separate emergency track for critical security and operational fixes |
| 10 | أي تعديل على القرارات المغلقة يمر بطلب تغيير. | Any change to a closed decision goes through a change request |
| 11 | الاحتفاظ بوثيقة الرؤية وست حزم مراحل وسجل القرارات وسجل التغييرات. | Keep the vision document, the six phase packages, the decision register and the change log |
| 12 | عدم اختراع مدد أو تكاليف أو نسب نجاح. | Do NOT invent durations, costs or success percentages |
| 13 | اعتماد الخطة وحده لا يأذن بتكليف Claude أو فحص المستودعات أو تعديل الكود. | Approving the plan alone does not authorise tasking Claude, scanning repositories or modifying code |
| **14–14** | **NOT RECOVERED** | **1 decision text(s) in this family remain unknown** |


### `ARCH-001` … `ARCH-011` — 9 decision texts recovered, 11 ids

**Mapping: INCOMPLETE — 2 decision text(s) MISSING.** The range declares 11 ids and the recovered document carries only 9 texts. Phase **all phases**. **The 2 missing text(s) are NOT reconstructed here and must not be invented.**

| # | recovered decision (verbatim) | English label |
|---|---|---|
| 1 | Laravel وWorkDo مصدر الحقيقة التجاري. | Laravel and WorkDo are the commercial source of truth |
| 2 | عدم تكرار محركات المنتجات والمخزون والطلبات والتسعير. | No duplicate product, inventory, order or pricing engines |
| 3 | Flutter لإدارة التجار والموظفين فقط. | Flutter is for merchant and staff administration only (see EX-003) |
| 4 | مشاركة قواعد الأعمال والصلاحيات بين الويب وFlutter. | Business rules and permissions shared between web and Flutter |
| 5 | خدمات Lynomia وغيرها اختيارية ولا تتسبب في تعطل الطلب أو الدفع عند غيابها. | Lynomia and other services are optional and must not break ordering or payment when absent |
| 6 | حماية APIs وWebhooks والعزل ومنع تكرار العمليات. | Protect APIs and webhooks, isolation, and prevent duplicate operations |
| 7 | إثبات جاهزية أي تكامل بالاختبارات، وليس بالوجود في الكود. | Prove an integration’s readiness by TESTS, not by its presence in code |
| 8 | الحفاظ على قابلية تطوير إضافات WorkDo وحقوق التراخيص. | Preserve WorkDo add-on upgradability and licence rights |
| 9 | عدم استنتاج غياب وظيفة في الإنتاج من غياب دليلها في المستودع. | Do NOT infer a feature’s absence in production from the absence of its evidence in the repository |
| **10–11** | **NOT RECOVERED** | **2 decision text(s) in this family remain unknown** |


### `UX-001` … `UX-014` — family-level content only

**Mapping: FAMILY-LEVEL ONLY.** Phase **02**. The plan cites these ids against a section whose bullet count (6) does not equal the id count (14), so no per-id pairing is derivable. The family's obligations are recovered in full; which id carries which is not.

Cited at: **02-A** → UX-001, UX-002, UX-003, UX-010; **02-B** → UX-004, UX-007, UX-008; **02-C** → UX-005, UX-006, UX-011, UX-012; **02-D** → UX-004 … UX-008; **02-E** → UX-005, UX-006, UX-011, UX-012; **02-F** → UX-008, UX-009; **02-G** → UX-013, UX-014

Content of 02-A:

- تطبيق الهوية الموحدة على صفحات لوحة التاجر.
- الاستلهام من سهولة Zid وSalla وعمق Shopify دون نسخها.
- دعم العربية والإنجليزية.
- دعم RTL وLTR.
- دعم الهاتف والتابلت والكمبيوتر.
- مراعاة سهولة الوصول والتباين والتنقل بلوحة المفاتيح.


### `THEME-001` … `THEME-008` — family-level content only

**Mapping: FAMILY-LEVEL ONLY.** Phase **03**. The plan cites these ids against a section whose bullet count (10) does not equal the id count (8), so no per-id pairing is derivable. The family's obligations are recovered in full; which id carries which is not.

Cited at: **03-A** → THEME-001 … THEME-008

Content of 03-A:

- تطوير الثيمات الثلاثة الحالية.
- محرر بصري قائم على الأقسام والسحب والإفلات.
- قوالب متعددة للصفحات والمنتجات والتصنيفات.
- تخصيصات مستقلة للجوال ضمن الثيم نفسه.
- معاينة مباشرة ومسودات.
- تراجع وإعادة وسجل إصدارات.
- مكتبة رسمية للأقسام والقوالب.
- نشر صريح وتراجع آمن.
- الحفاظ على تخصيصات المتاجر السابقة.
- عدم السماح للمحرر بتغيير محركات الدفع أو التسعير أو المخزون.


### `CUST-001` … `CUST-008` — family-level content only

**Mapping: FAMILY-LEVEL ONLY.** Phase **03**. The plan cites these ids against a section whose bullet count (9) does not equal the id count (8), so no per-id pairing is derivable. The family's obligations are recovered in full; which id carries which is not.

Cited at: **03-B** → CUST-001 … CUST-008

Content of 03-B:

- حساب مستقل للعميل في كل متجر.
- عزل العناوين والطلبات والمفضلة والولاء.
- بحث ذكي ومرادفات وفلاتر ديناميكية.
- صفحات منتجات احترافية.
- التقييمات الموثقة والأسئلة والمفضلة.
- الشراء كضيف والتتبع الآمن.
- Checkout مختصر يتكيف مع طريقة الدفع والشحن والعنوان والمنتجات.
- إعادة التحقق من السعر والمخزون على الخادم ومنع تكرار الطلب.
- تحسين SEO والفهرسة والأداء والصور والبيانات المنظمة.


### `SHIP-001` … `SHIP-004` — family-level content only

**Mapping: FAMILY-LEVEL ONLY.** Phase **04**. The plan cites these ids against a section whose bullet count (10) does not equal the id count (4), so no per-id pairing is derivable. The family's obligations are recovered in full; which id carries which is not.

Cited at: **04-D** → SHIP-001 … SHIP-004 (interleaved with COD-001 … COD-004)

Content of 04-D:

- تكامل شركات توصيل متعددة.
- اقتراح شركة التوصيل آلياً مع تعديل مصرح به.
- شحنات جزئية ومستودعات متعددة.
- بيانات تتبع وتكلفة وناقل لكل شحنة عند الحاجة.
- الحفاظ على فاتورة الطلب الأصلية الموحدة.
- منع تكرار خصم المخزون أو التحصيل بسبب تقسيم الشحنة.
- الفصل بين التسليم والتحصيل والتحويل للتاجر.
- استيراد كشوف COD والمطابقة واكتشاف الفروقات.
- تسويات جزئية ومبالغ معلقة وسجلات قابلة للتدقيق.
- تقارير أداء لشركات التوصيل من بيانات فعلية.


### `COD-001` … `COD-004` — family-level content only

**Mapping: FAMILY-LEVEL ONLY.** Phase **04**. The plan cites these ids against a section whose bullet count (10) does not equal the id count (4), so no per-id pairing is derivable. The family's obligations are recovered in full; which id carries which is not.

Cited at: **04-D** → COD-001 … COD-004 (interleaved with SHIP-001 … SHIP-004)

Content of 04-D:

- تكامل شركات توصيل متعددة.
- اقتراح شركة التوصيل آلياً مع تعديل مصرح به.
- شحنات جزئية ومستودعات متعددة.
- بيانات تتبع وتكلفة وناقل لكل شحنة عند الحاجة.
- الحفاظ على فاتورة الطلب الأصلية الموحدة.
- منع تكرار خصم المخزون أو التحصيل بسبب تقسيم الشحنة.
- الفصل بين التسليم والتحصيل والتحويل للتاجر.
- استيراد كشوف COD والمطابقة واكتشاف الفروقات.
- تسويات جزئية ومبالغ معلقة وسجلات قابلة للتدقيق.
- تقارير أداء لشركات التوصيل من بيانات فعلية.


### `CHAN-001` … `CHAN-004` — family-level content only

**Mapping: FAMILY-LEVEL ONLY.** Phase **04**. The plan cites these ids against a section whose bullet count (5) does not equal the id count (4), so no per-id pairing is derivable. The family's obligations are recovered in full; which id carries which is not.

Cited at: **04-G** → CHAN-001 … CHAN-004

Content of 04-G:

- كتالوج مركزي مع قواعد ظهور ونشر وتسعير حسب القناة.
- مزامنة ثنائية محكومة مع ملكية واضحة للبيانات.
- كشف التعارضات.
- استيراد الطلبات الخارجية دون تكرار.
- عزل التكاملات غير الجاهزة حتى لا توقف المتجر.

### `EX-001` … `EX-012` — the exclusions, recovered in full

**Mapping: EXPLICIT.** The source gives these as a numbered table, one id per row, so this is
the only family whose per-id mapping needs no ordered reading.

| id | what we do NOT do (verbatim) | English |
|---|---|---|
| `EX-001` | إعادة بناء Store Syria من الصفر | Rebuilding Store Syria from scratch |
| `EX-002` | إنشاء محرك تجارة موازٍ لـWorkDo | Creating a commerce engine parallel to WorkDo |
| `EX-003` | تطوير Flutter كتطبيق تسوق للعملاء | Developing Flutter as a customer shopping app |
| `EX-004` | Batch/Lot وتتبع الصلاحية ضمن النطاق الحالي | Batch/Lot and expiry tracking, within the current scope |
| `EX-005` | تخصيص مراحل الطلب لكل متجر | Per-store customisation of order stages |
| `EX-006` | تعديل منتجات الطلب بعد إنشائه | Editing an order’s products after it is created |
| `EX-007` | تسجيل دخول موحد للعملاء بين المتاجر، دون قرار جديد | Unified customer login across stores, absent a new decision |
| `EX-008` | محرر تصميم حر بالكامل مثل Webflow | A fully free design editor in the manner of Webflow |
| `EX-009` | إطلاق تكاملات Lynomia غير المختبرة | Releasing untested Lynomia integrations |
| `EX-010` | تشغيل كود Marketplace غير موثوق في الإنتاج | Running untrusted Marketplace code in production |
| `EX-011` | حذف بيانات المتجر تلقائياً بانتهاء الاشتراك | Automatically deleting store data when a subscription ends |
| `EX-012` | بدء مرحلة جديدة أو إطلاق إنتاج دون موافقة المالك | Starting a new phase, or releasing to production, without owner approval |

*12 of 12 recovered.*

---

## 3. The exclusions reconciled — measured, not assumed

An exclusion is the record of something deliberately **not** built. Its value is that it lets a
reader tell "never in scope" from "silently dropped". Each was checked against the 350-row matrix,
the 181-row gap register and, where it is a statement about behaviour, the code.

| id | reconciliation | verdict |
|---|---|---|
| `EX-001` rebuild from scratch | nothing in the project proposes one; `GOV-001`/`ARCH-001` say the same from the positive side | **holds** |
| `EX-002` parallel commerce engine | the standing rule already in `CLAUDE.md` and in every phase plan ("do not create a second analytics or campaign engine") | **holds, and was already being enforced without its id** |
| `EX-003` Flutter as a shopping app | `StoreSyria_app98` is the **admin** app throughout; no shopper surface exists in it | **holds** |
| `EX-004` Batch/Lot and expiry | **measured:** no row of the matrix and no row of the gap register asks for batch, lot or expiry tracking. The only `expiry` hits are token expiry and subscription expiry, which are different subjects | **holds — nothing was silently dropped, and nothing was wrongly carried as a gap** |
| `EX-005` per-store order stages | `ORD-002` fixes general stages; no per-store stage configuration exists or is requested | **holds** |
| `EX-006` editing order products after creation | **measured in code:** no controller or API handler in `app/Http/Controllers/` mutates `order_products`; the only route matching "order…update" is the delivery-status webhook. `ORD-004` states the same rule and `ORD-006` gives the sanctioned alternative (cancel + linked replacement) | **holds in the shipped surface** |
| `EX-007` cross-store unified customer login | **measured:** nothing in the matrix or gap register asks for it. `Customer` is store-scoped in `config/tenancy.php` and `CUST-001` requires a separate account per store | **holds** |
| `EX-008` a free-form Webflow-style editor | **this is the one that retroactively justifies a delivered design.** Phase 03 M4 built a **section-based** visual editor, which `THEME-002` requires and `EX-008` bounds. Had `EX-008` been available during Phase 03, the editor's shape would have been a cited decision instead of an engineering judgement | **holds — and the delivered editor is inside the boundary** |
| `EX-009` untested Lynomia integrations | `ARCH-005` and `ARCH-007` say the same; the Lynomia work is a separate integration project | **holds** |
| `EX-010` untrusted Marketplace code in production | no Marketplace exists; `MARKET-004` repeats it | **holds vacuously — Phase 06 has not started** |
| `EX-011` auto-deleting store data on subscription end | `SAAS-003` repeats it. **Not verifiable here:** Phase 06 has not started and the subscription-expiry path is recorded in Discovery as having enforcement commented out | **UNVERIFIED — must be checked before any Phase 06 subscription work** |
| `EX-012` starting a phase or releasing without approval | this is the rule the whole project has operated under; `GOV-005` states it positively | **holds** |

**No exclusion was found to have been violated.** One (`EX-011`) cannot be verified until Phase 06
begins and is recorded as unverified rather than assumed safe.

---

## 4. Exact differences the recovery produced

### 4.1 One inference is REFUTED: `PRD-001`

`MASTER-PLAN-SOURCE-VERIFICATION.md` §2.3 inferred, from how the Phase 02 ledger used the label:

> `PRD-001` governs honest metric presentation — its rows include *"Honest stat grid, every figure
> carrying its period"* and *"Refuse to display revenue/profit; label order value honestly"*.

**The recovered plan places `PRD-001` in the product catalogue family.** It is cited in `02-C`
(full-page product editor) and `02-E` (product management), and defined in `04-A` as the first of
`PRD-001`…`PRD-006`, whose text is *محرر منتج كامل* — **"full product editor"**. It has nothing to
do with metric honesty.

Four Phase 02 ledger rows (`P2-02-1`, `P2-02-5`, `P2-02-8`, `P2-07-6`) and the Phase 02 reports
surface row `P2-05-6` cite `PRD-001` for honest-metric work. **The work is sound and is not
withdrawn** — but it answers to **`ANA-006`** ("do not claim precise profitability when cost or fee
data is missing") and **`ANA-007`** ("show data quality, estimates and omissions"), which are
**Phase 05** decisions that Phase 02 partly pre-satisfied without knowing their ids.

Two consequences, both recorded rather than acted on here:

1. **`PRD-001` — "full product editor" — now needs its own coverage assessment.** Phase 02 built a
   product-editor experience, so this is likely substantially met; it has never been traced against
   this requirement, because the requirement's text was unknown.
2. **Phase 05 inherits credit it has not been given.** `ANA-006` and `ANA-007` are partly delivered
   already, on the merchant dashboard and the eleven report screens. `docs/development/phase-5/PHASE-05-METRIC-DEFINITIONS.md`
   should cite those ids.

This is the single clearest demonstration of why inference from usage is marked INFERRED and why no
row may be VERIFIED COMPLETE on it. The inference was reasonable, consistently applied, and wrong.

### 4.2 Authority upgrades

Every row of the traceability matrix carries an authority level — DIRECT, DERIVED or INFERRED. The
recovery makes a **fourth** level available for the first time: **REGISTER**, meaning the
requirement's own text is now readable in the committed plan.

This does not promote any row's *status*. A row that was `PARTIALLY IMPLEMENTED` on DERIVED
authority is still `PARTIALLY IMPLEMENTED`; what changes is that the thing it is partial against is
now quotable.

### 4.3 Requirements the 350-row matrix never tracked at all

Measured by searching the matrix for each recovered obligation's own vocabulary. **Fifteen recovered
requirements have no coverage of any kind** — not a row, not a gap, not a mention — and a
**SIXTEENTH and SEVENTEENTH were found on 2026-11-24**, after this section was written: `ADM-001`
and `ADM-002` (the platform command dashboard and Store 360°), Phase 06, now `GAP-231`/`GAP-232`.
They were missed by the matrix, by the gap register, AND by this census, because `P06-02`…`P06-06`
were derived from CLAUDE.md and PROJECT-STATE summaries that contain neither *dashboard* nor *360*.
So the figures below read **seventeen** untracked and **twenty** never-carried:

| recovered requirement | phase |
|---|---|
| `INV-006` optional, explicit pre-order | 04 |
| `PRD-002` attributes and templates by business type | 04 |
| `PRD-005` fixed and flexible bundles, complementary products | 04 |
| `ORD-008` picking, packing, barcodes, labels, invoices | 04 |
| `PRICE-002` price lists for wholesale, tiers, markets, channels | 04 |
| `PRICE-004`/`PRICE-005` explicit discount priority; never auto-stack or auto-pick lowest | 04 |
| `PRICE-006` preview the final price and explain its calculation | 04 |
| `CHAN-001`…`CHAN-004` sales channels and commercial integrations | 04 |
| `CUST-003` smart search, synonyms, dynamic filters | 03 |
| `KYC-001`…`KYC-003` individual and company verification | 06 |
| `OPS-002` real resource measurements | 06 |
| `MARKET-001`…`MARKET-005` the Marketplace | 06 |
| `SUPPORT-004` temporary scoped support access with merchant consent and an audit log | 06 |
| `IAM-003` two-person approval for dangerous actions | 06 |
| `CTRL-001`…`CTRL-004` feature flags, gradual rollout, safe disable | 06 |
| `ADM-001` comprehensive platform command dashboard *(added 2026-11-24)* | 06 |
| `ADM-002` Store 360° — review and manage each store from its own systems *(added 2026-11-24)* | 06 |

**And three more are tracked only in part**, where the matrix carries one half of a recovered
requirement and not the other:

| requirement | tracked | NOT tracked |
|---|---|---|
| `SAAS-007` separate platform revenue from merchant sales | `GMV` appears 4× | **`MRR` and `ARR` appear 0×** |
| `MKT-005` loyalty points, VIP and referrals | `loyalty` appears 14× | **`VIP` and `referral` appear 0×** |
| `CUST-005` verified reviews, questions, wishlists | product questions appear once | **verified reviews appear 0×** |

Counting the seventeen untracked plus the three half-tracked gives **twenty recovered requirements
that the matrix did not carry**, and they fall the wrong way round:

| phase | recovered requirements never tracked | state of that phase |
|---|---|---|
| **03 Storefront** | 2 (`CUST-003`, `CUST-005`) | **declared engineering-complete** |
| **04 Commerce** | **8** (`INV-006`, `PRD-002`, `PRD-005`, `ORD-008`, `PRICE-002`, `PRICE-004/005`, `PRICE-006`, `CHAN-*`) | **declared engineering-complete** |
| 05 Marketing & Analytics | 1 (`MKT-005` VIP/referrals half) | active |
| 06 SaaS & Super Admin | **9** (`KYC`, `OPS-002`, `MARKET`, `SUPPORT-004`, `IAM-003`, `CTRL`, `SAAS-007` MRR/ARR half, **`ADM-001`**, **`ADM-002`**) | not started |

**Ten of the twenty sit in phases that are already declared engineering-complete**, and eight of
those ten are Phase 04 Commerce. That is the finding: a closed phase whose owner-approved scope
included requirements nobody in this repository could read. The nine Phase 06 rows are an expected
absence — that phase has not started — but they were absent as *requirements*, not merely as
unimplemented work, which is the difference this register exists to close.

They are tracked as `GAP-182` … `GAP-199` in `MASTER-PLAN-GAP-REGISTER.md`, category **R**, all at
`COVERAGE NOT ASSESSED`.

---

## 5. What the recovery does NOT resolve

Recorded here so that no later reader over-reads this document, which is the failure mode its
predecessor was written to prevent.

1. **Three decision texts are still missing.** `GOV-001`…`GOV-014` declares fourteen ids and the
   recovered document carries **thirteen** texts. `ARCH-001`…`ARCH-011` declares eleven and carries
   **nine**. So one governance decision and two architecture decisions have no recovered wording.
   They are **not reconstructed and must not be invented**. This is the same class of loss the
   previous audit recorded, reduced from ~40 unknown texts to 3.
2. **For 64 ids the family's obligations are recovered but the per-id pairing is not.** `UX`,
   `THEME`, `CUST`, `SHIP`, `COD` and `CHAN` are cited across sections whose bullet counts do not
   equal their id counts; `GOV` and `ARCH` are short. A citation of, say, `THEME-005` therefore
   identifies a family obligation, not a specific sentence.
3. **The count-matched pairing is an ordered reading.** See §1.
4. **Byte-fidelity to the owner's original is not established.** The recovery document says of
   itself that the base text is *"a reorganization of owner-supplied approved decisions, not
   necessarily the unedited owner-authored original Decision Register"*. So the claim that the
   content was **unavailable** is resolved; the claim that this is the **original wording** is not.
5. **`EX-011` is unverified**, per §3.
6. **No status changed.** The 181 gaps are still 181 gaps.
