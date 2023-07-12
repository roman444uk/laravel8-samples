<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoleCreateRequest;
use App\Http\Requests\RoleUpdateRequest;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    function __construct()
    {
        $this->middleware('permission:role-list', ['only' => ['index']]);
        $this->middleware('permission:role-create', ['only' => ['create','store']]);
        $this->middleware('permission:role-update', ['only' => ['edit','update']]);
        $this->middleware('permission:role-delete', ['only' => ['destroy']]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return Application|Factory|View
     */
    public function index(Request $request)
    {
        $roles             = Role::orderBy('id', 'ASC')->get();
        $permissions       = config('permissions');
        $permissionGroups  = Arr::pluck($permissions, 'title', 'key');
        $permissionActions = Arr::pluck($permissions, 'actions', 'key');

        return view('pages.roles.index', compact('roles', 'permissionGroups', 'permissionActions'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Application|Factory|View
     */
    public function create()
    {
        $permission        = Permission::get();
        $permissions       = config('permissions');
        $permissionGroups  = Arr::pluck($permissions, 'title', 'key');
        $permissionActions = Arr::pluck($permissions, 'actions', 'key');

        return view('pages.roles.create', compact('permission', 'permissionGroups', 'permissionActions'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param RoleCreateRequest $request
     *
     * @return RedirectResponse
     */
    public function store(RoleCreateRequest $request)
    {
        $role = Role::create(['name' => $request->input('name'), 'title' => $request->input('title')]);
        $role->syncPermissions($request->input('permissions'));

        return redirect()->route('roles.index')->with('success', trans('panel.operation_success'));
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     *
     * @return RedirectResponse
     */
    public function show($id)
    {
        return redirect()->route('roles.index');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return Application|Factory|View
     */
    public function edit($id)
    {
        $role            = Role::find($id);
        $permission      = Permission::get();
        $rolePermissions = DB::table("role_has_permissions")->where("role_has_permissions.role_id", $id)
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->pluck('permissions.name', 'role_has_permissions.permission_id')
            ->all();

        $permissions       = config('permissions');
        $permissionGroups  = Arr::pluck($permissions, 'title', 'key');
        $permissionActions = Arr::pluck($permissions, 'actions', 'key');

        return view('pages.roles.edit',
            compact('role', 'permission', 'rolePermissions', 'permissionGroups', 'permissionActions'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $id
     *
     * @return RedirectResponse|\Illuminate\Http\Response
     */
    public function update(RoleUpdateRequest $request, $id)
    {

        $role        = Role::find($id);
        $role->name  = $request->input('name');
        $role->title = $request->input('title');
        $role->save();

        $role->syncPermissions($request->input('permissions'));

        return redirect()->route('roles.index')
            ->with('success', trans('panel.operation_success'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return RedirectResponse|\Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $role = Role::findOrFail($id);
        
        if($role->name === 'admin'){
            return redirect()->route('roles.index')
                ->with('success', trans('roles.delete_default_role'));
        }
        
        $role->delete();

        return redirect()->route('roles.index')
            ->with('success', trans('panel.operation_success'));
    }
}
