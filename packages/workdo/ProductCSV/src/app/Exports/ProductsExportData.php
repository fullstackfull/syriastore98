<?php

namespace Workdo\ProductCSV\app\Exports;

use App\Models\Product;
use App\Models\Store;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class ProductsExportData implements FromCollection, WithHeadings
{
    public function headings(): array
    {
        return [
            "Id",
            "Product Name",
            "Product Slug Name",
            "Main Category Name",
            "Sub Category Name",
            "Sale Price",
            "Final Price",
            "Product Brand",
            "Product Label",
            "Tax",
            "Tax Status",
            "Product Weight",
            "Product Stock",
            "Stock Status",
            "Row Stock Threshold",
            "Product Description",
        ];
    }

    public function collection(): Collection
    {
        if (\Auth::user()) {
            $storeId = getCurrentStore() ?? \Auth::user()->current_store;
            $store = Store::findOrFail($storeId);
        } else {
            $store = Store::where('slug', request()->route('storeslug'))->first();
        }
        $product_id = request()->route('id');
        $products = Product::where('id', $product_id)
            ->with('ProductData', 'brand', 'label')
            ->get();
        $exportData = $products->map(function ($product) {
            return [
                    'Id' => $product->id,
                    'Product Name' => $product->name ?? '',
                    'Product Slug Name' => $product->slug ?? '',
                    'Product Category' => optional($product->category)->name ?? '',
                    'Sale Price' => $product->price ?? 0,
                    'Final Price' => $product->sale_price ?? 0,
                    'Product Brand' => optional($product->brand)->name ?? '',
                    'Product Label' => optional($product->label)->name ?? '',
                    'Tax' => $product->tax_id ?? '',
                    'Tax Status' => $product->tax_status ?? '',
                    'Product Weight' => $product->product_weight ?? '',
                    'Product Stock' => $product->product_stock ?? '',
                    'Stock Status' => $product->stock_status ?? '',
                    'Low Stock Threshold' => $product->low_stock_threshold ?? '',
                    'Product Description' => strip_tags($product->description ?? ''),
                ];

        });
        return $exportData;
    }
}
