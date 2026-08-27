<?php

namespace Workdo\ImportExport\app\Exports;

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
            "Category Name",
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
        $storeId = getCurrentStore() ?? \Auth::user()->current_store;
        $store = Store::findOrFail($storeId);

        $products = Product::where('store_id', $store->id)
                            ->with('ProductData','brand','label')
                            ->get();

        $exportData = $products->map(function ($product) {
            return [
                'Id' => $product->id,
                'Product Name' => isset($product->name) ? $product->name : '',
                'Product Slug Name' => isset($product->slug) ? $product->slug : '',
                'Category Name' => optional($product->ProductData)->name ?? '',
                'Sale Price' => isset($product->price) ? $product->price : 0,
                'Final Price' => isset($product->sale_price) ? $product->sale_price : 0,
                'Product Brand' => optional($product->brand)->name ?? '',
                'Product Label' => optional($product->label)->name ?? '',
                'Tax' => isset($product->tax_id) ? $product->tax_id : '',
                'Tax Status' => isset($product->tax_status) ? $product->tax_status : '',
                'Product Weight' => isset($product->product_weight) ? $product->product_weight : '',
                'Product Stock' => isset($product->product_stock) ? $product->product_stock : '',
                'Stock Status' => isset($product->stock_status) ? $product->stock_status : '',
                'Row Stock Threshold' => isset($product->low_stock_threshold) ? $product->low_stock_threshold : '',
                'Product Description' => strip_tags($product->description),
            ];
        });
        return $exportData;
    }
}
