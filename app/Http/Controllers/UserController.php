<?php

namespace App\Http\Controllers;

use App\Actions\Users\CreateUserAction;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Arr;
use App\Models\User;
use App\Models\Profile;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOrders;
use App\Models\ProductAtttributes;
use App\Models\Order;
use App\Models\OrderSuppliers;
use App\Models\OrderQuotations;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Session, Image, Auth, File, Hash;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:users-list|users-create|users-edit|users-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:users-create', ['only' => ['create', 'store']]);
        $this->middleware('permission:users-edit', ['only' => ['edit', 'update']]);
        $this->middleware('permission:users-delete', ['only' => ['destroy', 'bulkDestroy']]);
        $this->middleware('permission:users-edit|users-create', ['only' => ['approve', 'reject']]);
    }
    
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
	public function index(Request $request){
		$query = User::with(['profile', 'roles'])->orderBy('id', 'DESC');
		$query->where(function ($q) {
			$q->where('email', 'not like', 'guest_%@wigpleasure.com')
				->orWhereHas('roles');
		});

		$type = $request->query('type');
		if ($type === 'merchant') {
			$query->whereHas('roles', fn ($q) => $q->whereIn('name', ['Merchant', 'Customer']));
		} elseif ($type === 'supplier') {
			$query->whereHas('roles', fn ($q) => $q->where('name', 'Supplier'));
		} elseif ($type === 'admin') {
			$query->whereHas('roles', fn ($q) => $q->where('name', 'Admin'));
		} elseif ($type === 'pending') {
			$query->whereDoesntHave('roles', fn ($q) => $q->where('name', 'Admin'))
				->where(function ($q) {
					$q->whereDoesntHave('profile')
						->orWhereHas('profile', fn ($pq) => $pq->where('active', 0)->orWhereNull('active'));
				});
		}

		if ($request->filled('search')) {
			$term = '%'.$request->search.'%';
			$query->where(function ($q) use ($term) {
				$q->where('name', 'like', $term)
					->orWhere('email', 'like', $term);
			});
		}

		if ($request->query('active') === '1') {
			$query->whereHas('profile', fn ($q) => $q->where('active', 1));
		} elseif ($request->query('active') === '0') {
			$query->where(function ($q) {
				$q->whereDoesntHave('profile')
					->orWhereHas('profile', function ($pq) {
						$pq->where(function ($q2) {
							$q2->where('active', 0)->orWhereNull('active');
						});
					});
			});
		}

		$users = $query->limit(2000)->get();

        $title = match ($type) {
            'merchant' => __('Merchants'),
            'supplier' => __('Suppliers'),
            'admin' => __('Admins'),
            'pending' => __('Pending approval'),
            default => __('All Users'),
        };

        return view('pages.users.index', compact('users', 'type'))
            ->with('i', 0)
            ->with('title', $title)
            ->with('breadcrumb', $title)
            ->with('filters', [
                'search' => $request->get('search'),
                'type' => $type,
                'active' => $request->get('active'),
            ]);
	}
	
    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function create()
    {
        return view('pages.users.create')->with('countries', countries())
                                                          ->with('categories', Category::cachedAll())
                                                          ->with('social_medias', [])
                                                          ->with('selected_products', [])
                                                          ->with('title', 'Create User')
                                                          ->with('breadcrumb', 'Create User');;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();
        $data['password'] = $request->password;
        $data['photo'] = $request->file('photo');

        $user = app(CreateUserAction::class)($data);

        return redirect()->route('users.index', [], 303)->with('success', 'User created successfully');
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
        $user = User::with('profile')->findOrFail($id);
        $username = $user->profile?->username ?? generateUserName($user->email);

        return redirect()->route('profile.show', [$username, encrypt($user->id)]);
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
        $user = User::with('profile')->findOrFail($id);
        $username = $user->profile?->username ?? generateUserName($user->email);

        return redirect()->route('profile.show', [$username, encrypt($user->id)]);
    }
    
    /**
     * Update the specified resource in storage.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function update(UpdateUserRequest $request, $id)
    {
        $user = User::findOrFail($id);
        $input = $request->only(['name', 'email']);

        if ($request->filled('password')) {
            $input['password'] = Hash::make($request->password);
        }

        $user->update($input);
        $user->syncRoles($request->input('roles'));

        return redirect()->route('users.index', [], 303)->with('success', 'User updated successfully.');
    }

    /**
     * Delete the user's account.
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $user_id
     */
    public function destroy(Request $request, $user_id): RedirectResponse
    {
        $user = User::find($user_id);

        //Auth::setUser($user);
        //Auth::logout();

        $products = Product::where('user_id', $user->id)->get();

        if(count($products) > 0) {
            foreach($products as $product) {
                ProductAtttributes::where('product_id', $product->id)->delete();
                ProductOrders::where('product_id', $product->id)->delete();

                $orders = Order::where('user_id', $user->id)->where('product_id', $product->id)->get();

                if(count($orders) > 0) {
                    foreach($orders as $order) {
                        OrderQuotations::where('order_id', $order->id)->delete();
                        OrderSuppliers::where('order_id', $order->id)->delete();
                        Order::where('id', $order->id)->delete();
                    }
                }

                if($product->image) {
                    $image_path = public_path("storage/uploads/products/original/") .$product->image;
                    $thumb_path = public_path("storage/uploads/products/thumbnails/") .$product->image;
        
                    if(File::exists($image_path)) {
                        File::delete($image_path);
                    }
                    
                    if(File::exists($thumb_path)) {
                        File::delete($thumb_path);
                    }
                }
        
                $product->delete();
            }
        }

        Session::flash('success', 'User was successfully deleted.');

        $user->delete();

        return redirect()->back(303);
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:users,id',
        ]);
        $count = 0;
        foreach ($validated['ids'] as $user_id) {
            $user = User::find($user_id);
            if (!$user) continue;
            $products = Product::where('user_id', $user->id)->get();
            if (count($products) > 0) {
                foreach ($products as $product) {
                    ProductAtttributes::where('product_id', $product->id)->delete();
                    ProductOrders::where('product_id', $product->id)->delete();
                    $orders = Order::where('user_id', $user->id)->where('product_id', $product->id)->get();
                    if (count($orders) > 0) {
                        foreach ($orders as $order) {
                            OrderQuotations::where('order_id', $order->id)->delete();
                            OrderSuppliers::where('order_id', $order->id)->delete();
                            Order::where('id', $order->id)->delete();
                        }
                    }
                    if ($product->image) {
                        $image_path = public_path("storage/uploads/products/original/") . $product->image;
                        $thumb_path = public_path("storage/uploads/products/thumbnails/") . $product->image;
                        if (File::exists($image_path)) File::delete($image_path);
                        if (File::exists($thumb_path)) File::delete($thumb_path);
                    }
                    $product->delete();
                }
            }
            $user->delete();
            $count++;
        }
        return redirect()->route('users.index', [], 303)->with('success', __(':count user(s) deleted.', ['count' => $count]));
    }

    /**
     * Approve a user (set profile active = 1).
     */
    public function approve(User $user): RedirectResponse
    {
        if ($user->hasRole('Admin')) {
            return redirect()->back()->with('error', __('Cannot modify admin approval status.'));
        }
        $profile = $user->profile;
        if (!$profile) {
            $profile = Profile::create([
                'username' => generateUserName($user->email),
                'user_id' => $user->id,
                'gender' => 'male',
                'active' => 1,
                'private' => 0,
            ]);
        } else {
            $profile->active = 1;
            $profile->save();
        }
        return redirect()->back()->with('success', __('User approved successfully.'));
    }

    /**
     * Reject a user (set profile active = 0).
     */
    public function reject(User $user): RedirectResponse
    {
        if ($user->hasRole('Admin')) {
            return redirect()->back()->with('error', __('Cannot modify admin approval status.'));
        }
        $profile = $user->profile;
        if ($profile) {
            $profile->active = 0;
            $profile->save();
        }
        return redirect()->back()->with('success', __('User rejected.'));
    }
}