P2.5-R-06 / R-07 BITE EVIDENCE — the numeric-operator tests run against the ORIGINAL code
=========================================================================================
Command: php artisan test tests/Feature/Merchant/CustomerNumericFilterOperatorsTest.php
Tree:    app/ and resources/ restored with `git checkout --`; only the test files are new.
Result:  14 failed, 7 passed (64 assertions)

FAILED — each one catches a named fault:
  aov equal matches only the customer with that average in the list        fault 1 (list)
  aov equal matches both customers that share an average in the list       fault 1 (list)
  aov equal matches only the customer with that average in the fragment    fault 1 (fragment)
  orders less than excludes the customers above the threshold              fault 2 (list)
  spend less than excludes the customers above the threshold               fault 2 (list)
  orders less than ... in the fragment                                     fault 2 (fragment)
  spend less than ... in the fragment                                      fault 2 (fragment)
  orders more than returns the customers above the threshold               fault 3 (list)
  spend more than returns the customers above the threshold                fault 3 (list)
  orders more than ... in the fragment                                     fault 3 (fragment)
  spend more than ... in the fragment                                      fault 3 (fragment)
  a customer with no orders is absent from every aov operator              fault 4 (empty("0"))
  no numeric operator reaches another stores customer in the list          fault 3 positive control
  no numeric operator reaches another stores customer in the fragment      fault 3 positive control

  The last two failed on their POSITIVE CONTROL, not their cross-tenant assertion: with no
  'More Than' branch the query returned nothing, so the control correctly refused to let the
  absence of store B's customer count as evidence. That is the control doing its job.

PASSED — the operators that already worked, which the repair must not break:
  premise the fixture produces the stated order counts and spends
  aov less than still returns the customers below the threshold
  aov more than still returns the customers above the threshold
  orders equal still returns only the exact match
  spend equal still returns only the exact match
  a customer with no orders is present in the count and spend filters
  the threshold itself is excluded from less than and more than

ONE OF MY OWN TEST BUGS, CAUGHT BY THIS STEP AND RECORDED BECAUSE IT IS THE PROJECT'S
COMMONEST FAILURE MODE: the first bite run reported 19 failed / 2 passed because listNames()
omitted actingAs(). getCurrentStore() reads auth()->user()->current_store, so with no
principal the predicate became `store_id IS NULL` and every assertion passed or failed for
that reason rather than the one it named. The premise test still PASSED throughout, because it
asserts through withoutGlobalScopes() and never consults the helper -- so the premise could not
have caught it.

FAULT 4, FOUND BY THIS STEP AND NOT PREVIOUSLY RECORDED ANYWHERE:
  `empty("0") === true` in PHP, so the original guard `!empty($requestData['text_field'])`
  DISCARDS a threshold of 0 and applies no filter at all. "Total Spend Equal 0" and
  "No. of Orders Equal 0" -- "which customers have never spent / never ordered", a question a
  merchant would reasonably ask -- therefore returned THE ENTIRE CUSTOMER LIST. Measured:
  'AOV More Than 0' returned all five store-A customers including the one with no orders.

P2.5-R-07 BITE — the grid tests run against the ORIGINAL code
=============================================================
Command: php artisan test tests/Feature/Merchant/CustomerGridFilterTest.php
Result:  13 failed, 5 passed (60 assertions)

FAILED: narrows on order count / total spend / AOV / name; list-and-grid agree; GET form;
        submit control; dead AJAX removed; vestigial hidden inputs gone; Reset points at the
        grid; submitted field selected; operator and value replayed; pagination keeps the filter.

PASSED, and each for a reason worth stating:
  premise the unfiltered grid shows every customer in this store  -- always worked
  the three control names are the ones the filter reads           -- the names were already right;
                                                                    only the <form> was missing
  no grid filter reaches another stores customer                  -- passes VACUOUSLY here: the
      original ignores the filter, so it returns the whole store, which contains the positive
      control and not store B. It is a tenancy guard, not a defect-catcher. Its value is after
      the fix, where the filter really narrows and the positive control proves the set is non-empty.
  an unfiltered grid does not reach another stores customer       -- always true
  a principal without manage customer is still refused            -- the permission is unchanged

AFTER THE FIX
=============
  CustomerNumericFilterOperatorsTest   27 passed (89 assertions)
  CustomerGridFilterTest               18 passed (83 assertions)
  CustomerListFilterApplyTest          13 passed (23 assertions)  <- UNCHANGED FILE. This is the
      proof the extraction preserved behaviour: it was written against the old inline filter and
      is green against the shared one without a single edit.
