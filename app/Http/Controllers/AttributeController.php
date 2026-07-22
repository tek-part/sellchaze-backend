<?php

namespace App\Http\Controllers;

use App\Actions\Attributes\CreateAttributeAction;
use App\Http\Requests\StoreAttributeRequest;
use App\Http\Requests\UpdateAttributeRequest;
use App\Models\User;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\AttributeValues;
use App\Models\ProductAttributes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Auth;

class AttributeController extends Controller
{
    function __construct()
    {
        $this->middleware('permission:attributes-list|roles-create|attributes-edit|attributes-delete', ['only' => ['index','store']]);
        $this->middleware('permission:attributes-create', ['only' => ['create','store']]);
        $this->middleware('permission:attributes-edit', ['only' => ['edit','update']]);
        $this->middleware('permission:attributes-delete', ['only' => ['destroy']]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $attributes = Attribute::with('attribute_values')->latest()->limit(2000)->get();

        return view('pages.attributes.index', compact('attributes'))->with('i', 0)->with('title', 'Latest attributes')->with('breadcrumb', 'Latest attributes');
    }
     
    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function create()
    {
        return view('pages.attributes.create')->with('title' , 'Create new attribute')->with('breadcrumb' , 'New attribute');
    }
    
    /**
     * Store a newly created resource in storage.
     *     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function store(StoreAttributeRequest $request)
    {
        $data = $request->only(['name', 'type', 'attribute_values']);
        app(CreateAttributeAction::class)($data);

        return redirect()->route('attributes.index', [], 303)->with('success', 'Attribute created successfully.');
    }
 
    /**
     * Show the form for editing the specified resource.
     *     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function edit(Attribute $attribute)
    {
        $attribute_values = AttributeValues::where('attribute_id', $attribute->id)->get();

        return view('pages.attributes.edit',compact('attribute', 'attribute_values'))->with('title' , 'Edit attribute')->with('breadcrumb' , 'Edit attribute');
    }
    
    /**
     * Update the specified resource in storage.
     *     * @param  \App\Attribute  $attribute
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function update(UpdateAttributeRequest $request, Attribute $attribute)
    {
        $attribute->update($request->only(['name', 'type']));

        if ($request->filled('attribute_values')) {
            AttributeValues::where('attribute_id', $attribute->id)->delete();
            foreach ($request->input('attribute_values') as $attrValue) {
                AttributeValues::create([
                    'value' => $attrValue['value'],
                    'attribute_id' => $attribute->id,
                ]);
            }
        }

        return redirect()->route('attributes.index', [], 303)->with('success', 'Attribute updated successfully.');
    }
    
    /**
     * Remove the specified resource from storage.
     *     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function destroy(Attribute $attribute)
    {
        AttributeValues::where('attribute_id', $attribute->id)->delete();

        $attribute->delete();

        return redirect()->route('attributes.index', [], 303)->with('success', 'Attribute deleted successfully');
    }

    public function bulkDestroy(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:attributes,id',
        ]);
        $count = 0;
        foreach ($validated['ids'] as $id) {
            $attr = Attribute::find($id);
            if ($attr) {
                AttributeValues::where('attribute_id', $attr->id)->delete();
                $attr->delete();
                $count++;
            }
        }
        return redirect()->route('attributes.index', [], 303)->with('success', __(':count attribute(s) deleted.', ['count' => $count]));
    }

    public function getattributes($product_id){
        $result = [];
        
        //Get attributes with it's values according to passed product ID
        $product_attributes = ProductAttributes::where('product_id', $product_id)->with('attribute.attribute_values')->get();
        $attributes         = '';

        if(count($product_attributes) > 0) {
            foreach($product_attributes as $product_attribute) {
                $attributes .= '<label>'.$product_attribute->attribute->name.'</label><select class="form-control" name="attributes['.$product_attribute->attribute->id.']">';

                if(count($product_attribute->attribute->attribute_values) > 0) {
                    $attributes .= '<option value="">Select attribute value</option>';

                    foreach($product_attribute->attribute->attribute_values as $attribute_value) {
                        $attributes .= '<option value="'.$attribute_value->id.'">'.$attribute_value->value.'</option>';
                    }
                }

                $attributes .= '</select><br />';
            }
        }
        else {
            $attributes .= 'There is no attributes for this product!';
        }

        $suppliers = '';
        $product = Product::find($product_id);
        $supplierIds = [];
        if ($product && Schema::hasColumn('products', 'user_id') && $product->user_id) {
            $supplierIds = [(int) $product->user_id];
        }

        if (!empty($supplierIds)) {
            $supplierUsers = User::whereIn('id', $supplierIds)->get();
            foreach ($supplierUsers as $supplierUser) {
                $suppliers .= '<div class="form-check"><input type="checkbox" name="suppliers[]" class="form-check-input" id="supplier-'.$supplierUser->id.'" value="'.$supplierUser->id.'" checked /><label class="form-check-label" for="supplier-'.$supplierUser->id.'">'.$supplierUser->name.'</label></div>';
            }
        } else {
            // Fallback: profile-based suppliers (users who have this product in their profile)
            $others_profiles = User::where('id', '<>', Auth::User()->id)->WhereHas('profile', function ($query) use ($product_id) {
                $query->where('products', 'like', '%'.$product_id.'%');
            })->get();

            if ($others_profiles->isNotEmpty()) {
                foreach ($others_profiles as $others_profile) {
                    $others_profile_products = @unserialize($others_profile->profile->products);
                    if (!is_array($others_profile_products)) {
                        continue;
                    }
                    $selected_category = array_keys($others_profile_products)[0] ?? null;
                    if ($selected_category === null) {
                        continue;
                    }
                    $others_profile_products = $others_profile_products[$selected_category] ?? [];
                    if (!empty($others_profile_products) && in_array($product_id, $others_profile_products)) {
                        $suppliers .= '<div class="form-check"><input type="checkbox" name="suppliers[]" class="form-check-input" id="supplier-'.$others_profile->id.'" value="'.$others_profile->id.'" /><label class="form-check-label" for="supplier-'.$others_profile->id.'">'.$others_profile->name.'</label></div>';
                    }
                }
            }

            if (empty($suppliers)) {
                $suppliers = 'The product you selected has no supplier please choose another product';
            }
        }

        // echo $attributes;
        // echo '<br><br><br><br>';
        // echo $suppliers;
        $result = [
            'attributes'    => $attributes,
            'suppliers'     => $suppliers
        ];

        return response()->json($result);
    }
}