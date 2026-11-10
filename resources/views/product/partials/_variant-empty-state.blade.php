{{--
    THE VARIANT PANEL'S EMPTY STATE -- one partial for all three arms, because it was one defect
    in three places.
    =============================================================================================

    MEASURED ON THE RUNNING PRODUCT EDITOR, not read off the source. Checking a "use for variation"
    box on `/product/1/edit` fires `update_attribute()`, which PUTs
    `products.attribute_combination_edit` and replaces `.attribute_combination` with the response.
    With no option VALUES chosen yet, that response rendered:

        root display=block  height=0
          META      display=none
          SCRIPT    display=none
          TEMPLATE  display=none
          SCRIPT    display=none   (4151 chars)
          SCRIPT    display=none   (13027 chars)

    17,186 characters of `textContent` and `innerText` of exactly ZERO. The merchant had a variant
    table on screen (79 characters of visible text), ticked a box, and the table was replaced by a
    blank area. Nothing told them why, and nothing told them what to do next.

    THE CAUSE IS THE SAME IN ALL THREE VIEWS: a top-level `@if` with no `@else`.

        attribute_combinations.blade.php:1        @if (count($combinations[0]) > 0)   -> @endif:288
        attribute_combinations_data.blade.php:1   @if ($product_stock)                -> @endif:396
        attribute_combinations_edit.blade.php:2   @if (count($combinations[0]) > 0)   -> @endif:460

    So each arm -- create, saved, and rebuild-after-change -- emits its scripts and nothing else
    whenever its condition is false. The condition being false is NOT an error: "vary this product
    by Size" with no sizes chosen yet is an ordinary intermediate state on the way to configuring
    variants. It just had no rendering.

    WHY A SHARED PARTIAL AND NOT THREE BLOCKS. The three views are near-copies of one another --
    `attribute_combinations_data.blade.php:20` and `attribute_combinations_edit.blade.php:30` both
    record being kept deliberately identical to `attribute_combinations.blade.php:21` -- and three
    copies of an empty state would drift the way the rest of them already have.

    `$ssVariantHint` lets the caller say which step is missing where it can tell; the default is
    the one true statement for all three arms.
--}}
<x-ss.empty
    icon="layout-grid"
    :title="__('No variant combinations yet')"
    :body="$ssVariantHint ?? __('Choose the option values you want to sell, such as sizes, colours or materials, and each combination will appear here with its own price, stock and SKU.')" />
