<?php
    
namespace App\Http\Controllers;
use App\Http\Requests\StorePermissionRequest;
use App\Http\Requests\UpdatePermissionRequest;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use DB, Auth;
    
class PermissionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:permissions-list|permissions-create|permissions-edit|permissions-delete', ['except' => []]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $permissions = Permission::orderBy('id', 'DESC')->limit(2000)->get();

        return view('pages.permissions.index', compact('permissions'))->with('i', 0)->with('title', 'All permissions')->with('breadcrumb', 'All permissions');
    }
    
    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function create()
    {
        return view('pages.permissions.create')->with('title' , 'Create permissions')->with('breadcrumb' , 'Create permissions');
    }
    
    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function store(StorePermissionRequest $request)
    {
        Permission::create([
            'guard_name' => 'web',
            'name' => $request->input('name'),
        ]);

        return redirect()->route('permissions.index')->with('success', 'Permission created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function edit($id)
    {
        $permission = Permission::find($id);

        return view('pages.permissions.edit',compact('permission'))->with('title' , 'Edit permission')->with('breadcrumb' , 'Edit permission');
    }
    
    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function update(UpdatePermissionRequest $request, $id)
    {
        $permission = Permission::findOrFail($id);
        $permission->update(['name' => $request->input('name')]);

        return redirect()->route('permissions.index')->with('success', 'Permission updated successfully.');
    }
    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        Permission::find($id)->delete();

        return redirect()->route('permissions.index')->with('success','Permission deleted successfully');
    }

    public function bulkDestroy(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);
        $count = Permission::whereIn('id', $validated['ids'])->delete();
        return redirect()->route('permissions.index')->with('success', __(':count permission(s) deleted.', ['count' => $count]));
    }
}