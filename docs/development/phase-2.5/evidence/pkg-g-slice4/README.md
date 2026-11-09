# P2.5-PKG-G Slice 4 — the create form's labels and its one tagging library

Captured from the running admin by `render-matrix.cjs`. **8 rows, 0 not clean.**

## The required marker marked the wrong thing

The five `*` spans were **siblings** of their labels inside `<div class="form-group ss-field">`, and
`.ss-field` is `display: flex; flex-direction: column; gap: var(--ss-space-2)`. So every direct
child is its own flex item: each `*` rendered on a standalone row, 16px below its label and 8px
above its input, reading as though it marked the **control**. `.ss-label__required`'s
`margin-inline-start: 2px` was written for an inline marker and did nothing there.

Each label is now hand-written with the span inside it, exactly as
`components/ss/secret-field.blade.php` already does.

## The trap that creates, and why every control now carries an explicit id

Dropping `Form::label()` is not free. konekt's `FormBuilder::label()` pushes the field name into
`$this->labels` (`FormBuilder.php:249`), and `getIdAttribute()` (`:1268-1277`) returns the name as
the element id **only if it is in that array**:

```php
if (array_key_exists('id', $attributes)) { return $attributes['id']; }
if (in_array($name, $this->labels))      { return $name; }
```

So a hand-written label with no `Form::label()` call makes `Form::text('first_name', …)` emit **no
id at all** — silently killing the `for` association, killing `x-ss.error-summary`'s
`href="#first_name"`, and orphaning every `aria-describedby` pair on the form. All five
`Form::text/email/password` calls therefore now pass `'id' => '<name>'` explicitly.

## Status lost `select2`, and that one class was the whole defect

Three things close with it:

* **`tags: true`.** `layouts/app.blade.php:823-836` initialises *every* `.select2` with a
  `createTag` that accepts any trimmed term — so a merchant could type a free value into Status,
  it POSTed, and `'status' => 'required|in:0,1'` refused their own dropdown's answer.
* **`is-invalid` on a 1×1 box.** select2 gives the original `<select>`
  `.select2-hidden-accessible`, clipped to one pixel, and no `is-invalid` rule for
  `.select2-selection` exists in any stylesheet here. The red border painted on nothing.
* **`href="#status"`** from the error summary moved focus onto that 1px element while select2's
  visible combobox stayed unfocused.

## A group of radios was tried first, and rejected for a reason worth recording

Radios fix all three too, and the survey preferred them. But `x-ss.choice-group` moves the hint
from the control to a `<fieldset>` — and `CustomerCreateFormTest` asserts, **in both directions**,
that every hint on this screen is bound to a control carrying that field's `name`. A fieldset has
no name, so the radios failed it; and a second test caught that the radios rendered a sixth
`invalid-feedback` message with no matching `is-invalid` mark, which the theme **hides outright**
(`style.css:3297`) — a merchant refused with no reason shown.

Rewriting that assertion to accept a group was the alternative. **Deleting one class is a smaller
and more obviously correct change than rewriting a guard to suit new markup**, and a binary choice
is not what a dropdown is bad at — a tagging library attached to one is.

## Verified

`Unit` 1459 · `Contract` 32 · the 7 files covering this form **114 passed** · the view compiles
through `blade.compiler`.
