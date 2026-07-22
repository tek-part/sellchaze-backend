<?php
    
namespace App\Http\Controllers;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use DB;
    
class RoleController extends Controller
{
    function __construct()
    {
        $this->middleware('permission:roles-list|roles-create|roles-edit|roles-delete', ['only' => ['index','store']]);
        $this->middleware('permission:roles-create', ['only' => ['create','store']]);
        $this->middleware('permission:roles-edit', ['only' => ['edit','update']]);
        $this->middleware('permission:roles-delete', ['only' => ['destroy', 'bulkDestroy']]);
    }
    
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $roles = Role::orderBy('id', 'DESC')->limit(500)->get();

        return view('pages.roles.index', compact('roles'))->with('i', 0)->with('title', 'All roles')->with('breadcrumb', 'All roles');
    }
    
    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function create()
    {
        $permission = Permission::get();

        return view('pages.roles.create',compact('permission'))->with('title' , 'Create role')->with('breadcrumb' , 'Create role');
    }
    
    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function store(StoreRoleRequest $request)
    {
        $role = Role::create(['name' => $request->input('name')]);
        $role->syncPermissions($request->input('permission'));

        return redirect()->route('roles.index')->with('success', 'Role created successfully.');
    }
    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $role = Role::find($id);
        $rolePermissions = Permission::join("role_has_permissions","role_has_permissions.permission_id","=","permissions.id")
            ->where("role_has_permissions.role_id",$id)
            ->get();
    
        return view('pages.roles.show',compact('role','rolePermissions'))->with('title' , 'View role')->with('breadcrumb' , 'View role');
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
        $role = Role::find($id);
        $permission = Permission::get();
        $rolePermissions = DB::table("role_has_permissions")->where("role_has_permissions.role_id",$id)
            ->pluck('role_has_permissions.permission_id','role_has_permissions.permission_id')->all();
    
        return view('pages.roles.edit',compact('role','permission','rolePermissions'))->with('title' , 'Edit role')->with('breadcrumb' , 'Edit role');
    }
    
    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function update(UpdateRoleRequest $request, $id)
    {
        $role = Role::findOrFail($id);
        $role->update(['name' => $request->input('name')]);
        $role->syncPermissions($request->input('permission'));

        return redirect()->route('roles.index')->with('success', 'Role updated successfully.');
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
        DB::table("roles")->where('id',$id)->delete();

        return redirect()->route('roles.index')->with('success','Role deleted successfully');
    }

    public function bulkDestroy(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);
        $count = \Spatie\Permission\Models\Role::whereIn('id', $validated['ids'])->delete();
        return redirect()->route('roles.index')->with('success', __(':count role(s) deleted.', ['count' => $count]));
    }
}