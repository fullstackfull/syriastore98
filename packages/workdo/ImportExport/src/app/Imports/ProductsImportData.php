<?php

namespace Workdo\ImportExport\app\Imports;

use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Validator;
use App\Models\Product;
use App\Models\Category;
use App\Models\ProductBrand;
use App\Models\ProductLabel;
use App\Models\Shipping;
use App\Models\Tax;
use App\Models\Store;
use Illuminate\Support\Facades\Session;


class ProductsImportData implements ToModel, WithHeadingRow
{
    /**
     * @param array $row
     *
     * @return Product|null
     */
    public function model(array $row)
    {
        $validator = Validator::make($row, [
            'category_name' => 'required',
            'product_name' => 'required',
            'sale_price' => 'required|numeric',
            'final_price' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            \Log::error('Validation failed for row: ' . json_encode($row));
            Session::push('productimportdataerror', $validator->getMessageBag()->first());
            return null;
        }
        $maincategory = Category::firstOrCreate(
            ['name' => $row['category_name']],
            [
                'slug' => 'collections/' . strtolower(preg_replace("/[^\w]+/", "-", $row['category_name'])),
                'image_url' => 'packages/workdo/ImportExport/src/resources/assets/img/img.png',
                'image_path' => 'packages/workdo/ImportExport/src/resources/assets/img/img.png',
                'icon_path' => 'packages/workdo/ImportExport/src/resources/assets/img/img.png',
                'trending' => 1,
                'status' => 1,
                
                'store_id' => getCurrentStore(),
            ]
        );

        if(isset($row['product_brand'])){
            $brand = ProductBrand::firstOrCreate(
                ['name' => $row['product_brand']],
                [
                    'slug' => ProductBrand::slugs($row['product_brand']),
                    'logo' => 'packages/workdo/ImportExport/src/resources/assets/img/img.png',
                    'status' => 1,
                    'is_popular' => 1,
                    'store_id' => getCurrentStore(),
                    
                    'created_by' => \Auth::user()->id,
                ]
            );
        }

        if(isset($row['product_label'])){
            $label = ProductLabel::firstOrCreate(
                ['name' => $row['product_label']],
                [
                    'slug' => ProductLabel::slugs($row['product_label']),
                    'status' => 1,
                    
                    'store_id' => getCurrentStore(),
                    'created_by' => \Auth::user()->id,
                ]
            );
        }

        if(isset($row['shipping'])){
            $shipping = Shipping::firstOrCreate(
                ['name' => $row['shipping']],
                [
                    'slug' => str_replace(' ', '-', strtolower($row['shipping'])),
                    'description' => 'Shipping description',
                    
                    'store_id' => getCurrentStore(),
                ]
            );
        }

        if(isset($row['tax'])){
            $tax = Tax::firstOrCreate(
                ['name' => $row['tax']],
                [
                    
                    'store_id' => getCurrentStore(),
                ]
            );
        }

        $existingProduct = Product::where('name', $row['product_name'])
            ->where('category_id', $maincategory->id)
            ->first();

        if ($existingProduct) {
            $existingProduct->import_status = 'Skipped: Duplicate product';
            Session::push('productimportdata', $existingProduct);
            return null;
        }

        $product = new Product([
            'name' => $row['product_name'] ?? '',
            'slug' => Product::slugs($row['product_name'] ?? ''),
            'category_id' => $maincategory->id,
            'price' => $row['sale_price'] ?? '',
            'sale_price' => $row['final_price'] ?? '',
            'brand_id' => $brand->id ?? '',
            'label_id' => $label->id ?? '',
            'tax_id' => $tax->id ?? '',
            'product_weight' => $row['product_weight'] ?? '',
            'track_stock' => $row['track_stock'] ?? '',
            'product_stock' => $row['product_stock'] ?? '',
            'low_stock_threshold' => $row['row_stock_threshold'] ?? '',
            'description' => $row['product_description'] ?? '',
            'variant_product' => $row['variant_product'] ?? 0,
            'shipping_id' => $shipping ?? '',
            'specification' => $row['specification'] ?? '',
            'detail' => $row['details'] ?? '',
            'cover_image_path' => $row['cover_image'] ?? 'packages/workdo/ImportExport/src/resources/assets/img/img.png',
            'cover_image_url' => $row['cover_image'] ?? 'packages/workdo/ImportExport/src/resources/assets/img/img.png',
            'status' => $row['status'] ?? 1,
            'trending' => $row['trending'] ?? 0,
            'stock_status' => $row['stock_status'] ?? 'in_stock',
            'minimum_quantity' => $row['minimum_quantity'] ?? 1,
            'maximum_quantity' => $row['maximum_quantity'] ?? 100,
            'tax_status' => $row['tax_status'] ?? 'taxable',
            'attribute_id' => $row['attribute_id'] ?? '',
            'product_attribute' => $row['product_attribute'] ?? '',
            'custom_field_status' => $row['custom_field_status'] ?? 0,
            'custom_field' => $row['custom_field'] ?? '',
            'preview_type' => $row['preview_type'] ?? 'Image',
            'preview_video' => $row['preview_video'] ?? '',
            'preview_content' => $row['preview_content'] ?? '',
            'video_url' => $row['video_url'] ?? '',
            'downloadable_product' => $row['downloadable_product'] ?? '',
            'product_type' => $row['product_type'] ?? '',
            
            'store_id' => getCurrentStore(),
        ]);

        $product->save();

        // Handle SKU and create ProductVariant if needed
        if (isset($row['sku']) && !empty($row['sku'])) {
            $variant = new \App\Models\ProductVariant([
                'product_id' => $product->id,
                'variant' => $row['sku'],
                'sku' => $row['sku'],
                'price' => $row['final_price'] ?? $row['sale_price'],
                'stock' => $row['product_stock'] ?? 0,
                'minimum_quantity' => $row['minimum_quantity'] ?? 1,
                'maximum_quantity' => $row['maximum_quantity'] ?? 100,
                'store_id' => getCurrentStore(),
            ]);
            $variant->save();
        }

        return $product;
    }
}
