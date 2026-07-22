<?php

namespace App\Http\Controllers;

use App\Actions\Categories\CreateCategoryAction;
use App\Http\Requests\CategoryStoreRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:categories-list', ['only' => ['index']]);
        $this->middleware('permission:categories-create', ['only' => ['create', 'store']]);
        $this->middleware('permission:categories-edit', ['only' => ['edit', 'update']]);
        $this->middleware('permission:categories-delete', ['only' => ['destroy', 'bulkDestroy']]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $categories = Category::withCount('products')->latest()->limit(2000)->get();

        return view('pages.categories.index', compact('categories'))->with('title', 'Latest categories')->with('breadcrumb', 'Latest categories');
    }
     
    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function create()
    {
        return view('pages.categories.create')->with('title' , 'Create new category')->with('breadcrumb' , 'New category');
    }
    
    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function store(CategoryStoreRequest $request)
    {
        app(CreateCategoryAction::class)($request->validated());

        return redirect()->route('categories.index', [], 303)->with('success', 'Category created successfully.');
    }
 
    /**
     * Show the form for editing the specified resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function edit(Category $category)
    {
        return view('pages.categories.edit',compact('category'))->with('title' , 'Edit category')->with('breadcrumb' , 'Edit category');
    }
    
    /**
     * Update the specified resource in storage.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $category->update($request->validated());

        return redirect()->route('categories.index', [], 303)->with('success', 'Category updated successfully.');
    }
    
    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function destroy(Category $category)
    {
        $category->delete();

        return redirect()->route('categories.index', [], 303)->with('success', 'Category deleted successfully');
    }

    public function bulkDestroy(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:categories,id',
        ]);
        $count = Category::whereIn('id', $validated['ids'])->delete();
        return redirect()->route('categories.index', [], 303)->with('success', __(':count category(ies) deleted.', ['count' => $count]));
    }
}