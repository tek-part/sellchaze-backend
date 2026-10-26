<?php
  
namespace App\Http\Controllers;

use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use App\Models\Attribute;
use Illuminate\Http\Request;
use App\Models\ProductAttributes;
use App\Notifications\ProductNotification;
use Auth, Session, Image, File;

class ProductController extends Controller
{
    function __construct()
    {
        //$this->middleware('permission:products-list|products-create|products-edit|products-delete', ['only' => ['index','show']]);
        $this->middleware('permission:products-list', ['only' => ['index']]);
        $this->middleware('permission:products-create', ['only' => ['create','store']]);
        $this->middleware('permission:products-edit', ['only' => ['edit','update']]);
        $this->middleware('permission:products-delete', ['only' => ['destroy', 'bulkDestroy']]);
    }
    
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Product::with(['category', 'product_attributes'])->latest();

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->limit(3000)->get();
        $categories = Category::orderBy('name')->get();

        return view('pages.products.index', compact('products', 'categories'))
            ->with('i', 0)
            ->with('title', __('Latest products'))
            ->with('breadcrumb', __('Latest products'));
    }
     
    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function create()
    {
        $attributes = Attribute::cachedAll();
        $categories = Category::cachedAll();

        return view('pages.products.create',compact('categories','attributes'))->with('title' , 'Create new product')->with('breadcrumb' , 'New product');
    }
    
    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function store(ProductStoreRequest $request)
    {
    
        $product                = new Product;
        $product->name          = $request->name;
        $product->description   = $request->description;
        $product->user_id       = Auth::user()->id;
        $product->category_id   = $request->category;
        $product->created_at    = date("Y-m-d H:i:s");
        $product->updated_at    = date("Y-m-d H:i:s");

        if ($request->hasFile('image')) {
            $image              = $request->file('image');
            $input['image']     = md5($image->getClientOriginalName() . time()) . "." . $image->getClientOriginalExtension();
            $destinationPath    = public_path('/storage/uploads/products/thumbnails');
            $imgFile            = Image::make($image->getRealPath());

            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $imgFile->resize(300, 300, function ($constraint) {
                $constraint->aspectRatio();
            })->save($destinationPath.'/'.$input['image']);

            $destinationPath = public_path('/storage/uploads/products/original');

            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $image->move($destinationPath, $input['image']);

            $product->image = $input['image'];
        }

        $product->save();

        if($request->input('attributes')) {
            foreach($request->input('attributes') as $attribute_input) {
                $product_attribute = new ProductAttributes;

                $product_attribute->attribute_id   = $attribute_input;
                $product_attribute->product_id     = $product->id;
                $product_attribute->created_at     = date("Y-m-d H:i:s");
                $product_attribute->updated_at     = date("Y-m-d H:i:s");
            
                $product_attribute->save();
            }
        }
        
        /*$user = User::first();
  
        $data = [
            'user_id'       => Auth::user()->id,
            'product_id'    => $product->id
        ];
  
        Notification::send($user, new ProductNotification($data));*/

        return redirect()->route('products.index')->with('success','Product created successfully.');
    }
     
    /**
     * Display the specified resource.
     *
     * @param  $product_id
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    // public function show($product_id)
    // {
    //     $product                = Product::find(decrypt($product_id));
    //     $product_attributes_ids = ProductAttributes::where('product_id', decrypt($product_id))->pluck('attribute_id')->all();
    //     $attributes             = Attribute::whereIn('id', $product_attributes_ids)->get();

    //     return view('pages.products.show',compact('product', 'attributes'))->with('title' , 'Product details')->with('breadcrumb' , 'Product details');
    // } 
     
    public function show($product_id)
    {
        $product = Product::findOrFail($product_id);
    
        $product_attributes_ids = ProductAttributes::where(
            'product_id', $product_id
        )->pluck('attribute_id')->all();
    
        $attributes = Attribute::whereIn('id', $product_attributes_ids)->get();
    
        return view('pages.products.show', compact('product', 'attributes'))
            ->with('title', 'Product details')
            ->with('breadcrumb', 'Product details');
    }
    
    /**
     * Show the form for editing the specified resource.
     *
     * @param  $product_id
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function edit($product_id)
    {
        $product    = Product::find(decrypt($product_id));
        $attributes = Attribute::cachedAll();
        $categories = Category::cachedAll();

        return view('pages.products.edit',compact('product', 'categories', 'attributes'))->with('title' , 'Edit product')->with('breadcrumb' , 'Edit product');
    }
    
    /**
     * Update the specified resource in storage.
     *
     * @param  $product_id
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function update(UpdateProductRequest $request, $product_id)
    {
        $product = Product::findOrFail(decrypt($product_id));

        $product->name          = $request->name;
        $product->description   = $request->description;
        $product->user_id       = Auth::user()->id;
        $product->category_id   = $request->category;
        $product->created_at    = date("Y-m-d H:i:s");
        $product->updated_at    = date("Y-m-d H:i:s");

        if ($request->hasFile('image')) {
            $image              = $request->file('image');
            $input['image']     = md5($image->getClientOriginalName() . time()) . "." . $image->getClientOriginalExtension();
            $destinationPath    = public_path('/storage/uploads/products/thumbnails');
            $imgFile            = Image::make($image->getRealPath());

            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            
            if(File::exists($destinationPath.'/'.$product->image)) {
                File::delete($destinationPath.'/'.$product->image);
            }

            $imgFile->resize(300, 300, function ($constraint) {
                $constraint->aspectRatio();
            })->save($destinationPath.'/'.$input['image']);

            $destinationPath = public_path('/storage/uploads/products/original');

            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            if(File::exists($destinationPath.'/'.$product->image)) {
                File::delete($destinationPath.'/'.$product->image);
            }

            $image->move($destinationPath, $input['image']);

            $product->image = $input['image'];
        }

        $product->save();

        if($request->input('attributes')) {
            ProductAttributes::where('product_id', $product->id)->delete();
            
            foreach($request->input('attributes') as $attribute_input) {
                $product_attribute = new ProductAttributes;

                $product_attribute->attribute_id  = $attribute_input;
                $product_attribute->product_id    = $product->id;
                $product_attribute->created_at    = date("Y-m-d H:i:s");
                $product_attribute->updated_at    = date("Y-m-d H:i:s");
            
                $product_attribute->save();
            }
        }

        return redirect()->route('products.index')->with('success','Product updated successfully');
    }
    
    /**
     * Remove the specified resource from storage.
     *
     * @param  $product_id
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function destroy($product_id)
    {
        $product = Product::find(decrypt($product_id));
        if (! $product) {
            abort(404);
        }

        $this->performProductDeletion($product);

        return redirect()->route('products.index')->with('success', 'Product successfully deleted');
    }

    /**
     * Delete multiple products (from index checkboxes).
     */
    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $count = 0;
        foreach ($validated['ids'] as $id) {
            $product = Product::find($id);
            if ($product) {
                $this->performProductDeletion($product);
                $count++;
            }
        }

        return redirect()->route('products.index')->with(
            'success',
            __(':count product(s) deleted.', ['count' => $count])
        );
    }

    protected function performProductDeletion(Product $product): void
    {
        ProductAttributes::where('product_id', $product->id)->delete();

        if ($product->image) {
            $image_path = public_path('storage/uploads/products/original/').$product->image;
            $thumb_path = public_path('storage/uploads/products/thumbnails/').$product->image;

            if (File::exists($image_path)) {
                File::delete($image_path);
            }

            if (File::exists($thumb_path)) {
                File::delete($thumb_path);
            }
        }

        $product->delete();
    }

    public function getproducts($category_id){
        $result = [];
        
        //Get products with it's values according to passed category ID
        $products           = Product::where('category_id', $category_id)->get();
        $products_select    = '';

        if(count($products) > 0) {
            $products_select .= '<select class="form-control" name="product" id="product" required><option value="">Please select product</option>';

            foreach($products as $product) {
                $products_select .= '<option value="'.$product->id.'">'.$product->name.'</option>';
            }

            $products_select .= "</select><script>
                $('select[name=\"product\"]').on('change', function() {
                    $('#attributes_holder').hide();
                    $('#attributes').html('');

                    $('#suppliers_holder').hide();
                    $('#suppliers').html('');

                    var productID = $(this).val();

                    if(productID) {
                        $.ajax({
                            url: '".url('getattributes')."/' + encodeURI(productID),
                            type: 'GET',
                            dataType: 'json',
                            success:function(data) {
                                if(data.suppliers == 'The product you selected has no supplier please choose another product') {
                                    if (typeof rizzToast === \"function\") rizzToast('warning', data.suppliers); else alert(data.suppliers);

                                    /*var delay = 1000; 
                                    setTimeout(function(){ window.location = location.protocol + '//' + location.host + '/profile/#products'; }, delay);*/

                                    $('submit[type=submit]').prop('disabled', true);

                                    return false;
                                }

                                if(data !== '') {
                                    $('#attributes_holder').show();
                                    $('#attributes').html(data.attributes);

                                    $('#suppliers_holder').show();
                                    $('#suppliers').html(data.suppliers);
                                }
                            }
                        });
                    }else{
                        $('#attributes_holder').hide();
                        $('#attributes').html('');

                        $('#suppliers_holder').hide();
                        $('#suppliers').html('');
                    }
                });
            </script>";
        }
        else {
            $products_select .= 'There is no products for this category!';
        }

        $result = ['products' => $products_select];

        return response()->json($result);
    }

    public function getmultiproducts($category_id){
        $result = [];
        
        //Get products with it's values according to passed category ID
        $products           = Product::where('category_id', $category_id)->get();
        $products_select    = '';

        if(count($products) > 0) {
            foreach($products as $product) {
                $products_select .= '<div class="form-check"><input type="checkbox" name="products['.$category_id.']['.$product->id.']" class="form-check-input" id="product-'.$product->id.'" value="'.$product->id.'" /><label class="form-check-label" for="product-'.$product->id.'">'.$product->name.'</label></div>';
            }
        }
        else {
            $products_select .= 'There is no products for this category!';
        }

        $result = ['products' => $products_select];

        return response()->json($result);
    }
}