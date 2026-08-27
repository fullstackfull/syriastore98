<?php

namespace Workdo\AdditionalCustomFields\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\AdditionalCustomFields\app\Models\AdditionalCustomFields as AdditionalCustomFieldsModel;
use Workdo\AdditionalCustomFields\app\Models\ProductAdditionalCustomField;
use Workdo\AdditionalCustomFields\DataTables\AdditionalCustomFieldData;
use App\Models\Product;

class SettingController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
     public function index(AdditionalCustomFieldData $dataTable)
    {
            $user=auth()->user();
            $store_id = getcurrentstore();
            $field_settings = AdditionalCustomFieldsModel::where('store_id', $store_id)
            ->get();
            return $dataTable->render('additional-custom-fields::index', compact('field_settings'));
     
    }
    public function create()
    {
        // Fetch options for 'type' and 'module' select fields
        $types = [
            'text' => 'Text', 
            'textarea' => 'Textarea', 
            'date' => 'Date', 
            'radio' => 'Radio', 
            'select' => 'Select', 
            'checkbox' => 'Checkbox', 
            'time' => 'Time'
        ];
       
        
        return view('additional-custom-fields::create', compact('types'));
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        $store_id = getcurrentstore();
    
        // Validate the input data
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required',
            'options' => 'nullable|array',
            'options.*' => 'nullable|string',
        ]);


        $type = ucfirst($request->input('type'));  
        $options = $request->has('options') ? json_encode($request->input('options')) : null;

    
        // Create a new AdditionalCustomField record
        $customField = AdditionalCustomFieldsModel::create([
            'store_id' => $store_id,
            'label' => $request->input('name'),
            'type' => $type,
            'options' => $options, // Store the options as a JSON string
        ]);

    
        // Redirect or return a response
        return redirect()->route('additionalcustomfields.index')
                         ->with('success', 'Custom field created successfully!');
    }
    
    

    
    public function edit($id)
    {
        $customField = AdditionalCustomFieldsModel::findOrFail($id); // Retrieve the custom field by ID
        $types = ['radio' => 'Radio', 'checkbox' => 'Checkbox', 'select' => 'Select', 'text' => 'Text' ,'textarea' => 'TextArea' , 'date' => 'Date' , 'time' => 'Time']; // Define available types
     
        return view('additional-custom-fields::edit', compact('customField', 'types')); // Pass the data to the view
    }
    

    // Update Function
    public function update(Request $request, $id)
    {
       
        // Find the custom field by ID
        $customField = AdditionalCustomFieldsModel::findOrFail($id);

        // Validate the input data
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:radio,checkbox,select,text',
            'options' => 'nullable|array',
            'options.*' => 'nullable|string|max:255',
        ]);

        // Capitalize the first letter of the 'type'
        $type = ucfirst($request->input('type'));

        // Prepare options as JSON if provided
        $options = $request->has('options') ? json_encode($request->input('options')) : null;

        // Update the custom field record
        $customField->update([
            'label' => $request->input('name'),
            'type' => $type,
            'options' => $options,
        ]);

        // Redirect with a success message
        return redirect()->route('additionalcustomfields.index')
                         ->with('success', 'Custom field updated successfully!');
    }

    // Delete Function
    public function destroy($id)
    {
        // Find the custom field by ID
        $customField = AdditionalCustomFieldsModel::findOrFail($id);

        // Delete the custom field
        $customField->delete();

        // Redirect with a success message
        return redirect()->route('additionalcustomfields.index')
                         ->with('success', 'Custom field deleted successfully!');
    }


    public static function saveData($product, $data)
{
    // Loop through the $data array
    foreach ($data as $key => $value) {
        // Only process keys that start with "field_label_" and "field_"
        if (strpos($key, 'field_label_') === 0) {
            // Extract the field ID from the label key (e.g., 313 from field_label_313)
            $fieldId = str_replace('field_label_', '', $key);

            // Validate extracted field ID
            if (!is_numeric($fieldId)) {
                continue; // Skip invalid field IDs
            }

            // Construct the corresponding value key (e.g., field_313)
            $fieldValueKey = 'field_' . $fieldId;

            // Ensure the field value exists and is not null
            $fieldValue = isset($data[$fieldValueKey]) ? $data[$fieldValueKey] : '';

            // If the value is an array (e.g., checkboxes), encode it
            if (is_array($fieldValue)) {
                $fieldValue = json_encode($fieldValue);
            }

            // Get the label for the field
            $fieldLabel = $value;

            // Save or update the field data in the database
            ProductAdditionalCustomField::updateOrCreate(
                [
                    'field_id'   => $fieldId,
                    'product_id' => $product->id ?? null,
                ],
                [
                    'store_id'   => $product->store_id ?? null,
                    'label'      => $fieldLabel, // Label as the key
                    'value'      => $fieldValue, // Ensure value is empty if not set
                ]
            );

            // Update product status to indicate custom fields are set
            $product = Product::findOrFail($product->id);
            if ($product) {
                $product->custom_field_status = 1;
                $product->save();
            }
        }
    }
}


}
