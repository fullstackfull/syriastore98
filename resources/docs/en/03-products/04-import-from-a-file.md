---
title: Import from a file
order: 4
summary: Bring a catalogue in from a spreadsheet instead of typing it.
related: products/add-a-product
updated: 2026-11-25
status: published
---

## When importing is worth it

Somewhere around thirty products. Below that, typing is faster than preparing a file. Above it,
the file wins — and it keeps winning every time you need to change fifty prices at once.

Importing is available depending on your plan.

## Prepare the file

Export the template from the dashboard rather than inventing a layout. The columns have to match
what the import expects, and a template you downloaded today matches today's expectations.

A product file looks like this — the same columns the dashboard's product export produces, with
one product per row:

```csv
Product Name,Product Slug Name,Main Category Name,Sale Price,Final Price,Product Stock
Olive oil 1L,olive-oil-1l,Pantry,12.00,9.50,40
Thyme 200g,thyme-200g,Pantry,4.00,4.00,120
```

Two columns are easy to mix up. `Sale Price` is what the product normally costs; `Final Price` is
what a customer pays today. Set them to the same number when nothing is discounted.

Fill it in a spreadsheet, save it in the format the template is in, and keep the original — you
will want it again.

## Import in two passes

1. Import a handful of rows first. Look at them on the shop. Prices in the right currency?
   Categories where you expected? Images actually attached?
2. Then import the rest.

The second pass is quick. Discovering on row 400 that your price column had a thousands separator
is not.

## Updating later

The same mechanism updates what is already there. That is the real benefit: a seasonal price
change is one file, not an afternoon.
